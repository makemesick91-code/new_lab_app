<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Services;

use App\Models\User;
use App\Modules\LabOrder\Services\AuditLogService;
use App\Modules\RmeInvoice\Models\RmeBillingDispute;
use App\Modules\RmeInvoice\Notifications\RmeBillingDisputeNotification;
use App\Modules\RmeInvoice\Support\RmeBillingDisputeAuditEvent;
use App\Modules\RmeInvoice\Support\RmeBillingDisputePermission;
use App\Modules\RmeOnlineContext\Services\RmeWorkingBranchScope;
use App\Support\AccessControl\ActiveAccountResolver;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;

/**
 * Rule 203 — escalation ROUTING of a billing dispute to the responsible branch
 * authority, and its (secondary) in-app notifications.
 *
 * The responsible authority of a branch = every usable account holding the
 * REVIEW permission whose working-branch scope reaches the dispute's branch.
 * REVIEW is granted to no operational role (Super Admin via '*'); rule 204
 * (OPS-OQ-1a) adds every account explicitly DESIGNATED for that branch. A
 * context-bound reviewer is reached only while working in that branch; the
 * branch queue itself never depends on a notification.
 *
 * FAIL SAFE: if no reviewer can be resolved, the dispute stays OPEN in the
 * branch queue with its payment hold, and the gap is AUDITED
 * (RME_BILLING_DISPUTE_ESCALATION_UNROUTED). If delivery fails, the failure is
 * audited and logged. Nothing here can throw into the caller — building the
 * message (an invoice lookup) runs inside the same guard as routing and
 * delivery — and nothing here can release or weaken the hold.
 *
 * NO SIDE EFFECT ON OTHER ACCOUNTS: deciding whether ANOTHER account reaches a
 * branch walks the canonical working-branch scope, which expires a stale online
 * context as a side effect. That evaluation runs inside a transaction that is
 * always rolled back, so counting or routing never writes another account's
 * context row (security review finding B).
 *
 * Runs AFTER the dispute transaction committed (never inside it), so a
 * notification is never sent for a dispute that rolled back, and each committed
 * report/escalation notifies exactly once.
 */
class RmeBillingDisputeEscalationService
{
    public function __construct(
        private readonly RmeWorkingBranchScope $workingBranchScope,
        private readonly ActiveAccountResolver $accounts,
        private readonly AuditLogService $auditLogs,
        private readonly RmeBillingDisputeReviewerService $reviewers,
    ) {}

    /**
     * The usable accounts that may review disputes of $branchId, excluding $except.
     *
     * @return Collection<int, User>
     */
    public function reviewersFor(int $branchId, ?User $except = null): Collection
    {
        return $this->holdersReaching([RmeBillingDisputePermission::REVIEW], $branchId, $except, includeDesignees: true);
    }

    public function reported(RmeBillingDispute $dispute, User $reporter): void
    {
        $this->dispatch($dispute, $reporter, [RmeBillingDisputePermission::REVIEW], 'reported', fn () => [
            'Laporan ketidaksesuaian tagihan baru',
            sprintf('%s untuk tagihan %s menunggu tinjauan penanggung jawab cabang. Pembayaran tagihan ditahan.', $this->number($dispute), $this->invoiceNumber($dispute)),
        ]);
    }

    public function escalated(RmeBillingDispute $dispute, User $escalator): void
    {
        $this->dispatch($dispute, $escalator, [RmeBillingDisputePermission::REVIEW, RmeBillingDisputePermission::VIEW], 'escalated', fn () => [
            'Laporan tagihan dieskalasi ke Owner',
            sprintf('%s untuk tagihan %s memerlukan keputusan Owner. Pembayaran tagihan tetap ditahan.', $this->number($dispute), $this->invoiceNumber($dispute)),
        ]);
    }

    /**
     * @param  list<string>  $permissions
     * @param  Closure(): array{0: string, 1: string}  $content  title + message, built inside the guard
     */
    private function dispatch(RmeBillingDispute $dispute, User $actor, array $permissions, string $stage, Closure $content): void
    {
        // Nothing in here may throw into the caller: the dispute already
        // committed, and its payment hold must never depend on delivery. The
        // message is built INSIDE the guard because building it reads the
        // invoice (security review A).
        try {
            [$title, $message] = $content();
            $this->routeToAuthority($dispute, $actor, $permissions, $stage, $title, $message);
        } catch (\Throwable $e) {
            $this->failure($dispute, $actor, $stage, 'ESCALATION_FAILED', $e);
        }
    }

    /**
     * @param  list<string>  $permissions
     */
    private function routeToAuthority(RmeBillingDispute $dispute, User $actor, array $permissions, string $stage, string $title, string $message): void
    {
        $recipients = $this->holdersReaching($permissions, (int) $dispute->branch_id, $actor, includeDesignees: true);

        if ($recipients->isEmpty()) {
            $this->audit($dispute, $actor, RmeBillingDisputeAuditEvent::ACTION_ESCALATION_UNROUTED, [
                'stage' => $stage,
                'eligible_recipient_count' => 0,
            ]);

            return;
        }

        $url = route('rme.billing-disputes.show', ['dispute' => (int) $dispute->id], false);
        $delivered = 0;

        foreach ($recipients as $recipient) {
            try {
                $recipient->notify(new RmeBillingDisputeNotification($title, $message, $url, (int) $dispute->id));
                $delivered++;
            } catch (\Throwable $e) {
                $this->failure($dispute, $actor, $stage, 'DELIVERY_FAILED', $e, (int) $recipient->id);
            }
        }

        Log::info('RME billing dispute escalation routed.', [
            'dispute_id' => (int) $dispute->id,
            'branch_id' => (int) $dispute->branch_id,
            'stage' => $stage,
            'eligible_recipient_count' => $recipients->count(),
            'delivered_count' => $delivered,
        ]);
    }

    /**
     * Holders of any of $permissions, plus (rule 204) the accounts actively
     * designated for $branchId, that are usable and whose working scope reaches
     * the branch.
     *
     * @param  list<string>  $permissions
     * @return Collection<int, User>
     */
    private function holdersReaching(array $permissions, int $branchId, ?User $except, bool $includeDesignees = false): Collection
    {
        $names = Permission::query()->whereIn('name', $permissions)->pluck('name')->all();
        $candidates = $names === [] ? collect() : User::permission($names)->orderBy('id')->get();

        if ($includeDesignees) {
            $designated = $this->reviewers->designatedUserIdsFor($branchId);

            if ($designated !== []) {
                $candidates = $candidates
                    ->concat(User::query()->whereIn('id', $designated)->get())
                    ->unique(fn (User $user) => (int) $user->id)
                    ->sortBy(fn (User $user) => (int) $user->id)
                    ->values();
            }
        }

        if ($candidates->isEmpty()) {
            return collect();
        }

        // The working-branch scope of ANOTHER account may expire its stale online
        // context as a side effect; evaluate it inside a transaction that is
        // always rolled back so this read never writes their row (finding B).
        DB::beginTransaction();

        try {
            return $candidates
                ->filter(fn (User $user) => ($except === null || (int) $user->id !== (int) $except->id)
                    && $this->accounts->isUsable($user)
                    && $this->workingBranchScope->allows($user, $branchId))
                ->values();
        } finally {
            DB::rollBack();
        }
    }

    private function failure(RmeBillingDispute $dispute, User $actor, string $stage, string $code, \Throwable $e, ?int $recipientId = null): void
    {
        Log::warning('RME billing dispute notification failed; the dispute and its payment hold stand.', [
            'dispute_id' => (int) $dispute->id,
            'stage' => $stage,
            'code' => $code,
            'recipient_id' => $recipientId,
            'exception' => $e::class,
        ]);

        $this->audit($dispute, $actor, RmeBillingDisputeAuditEvent::ACTION_NOTIFICATION_FAILED, [
            'stage' => $stage,
            'code' => $code,
            'recipient_id' => $recipientId,
        ]);
    }

    /**
     * @param  array<string, int|string|null>  $extra
     */
    private function audit(RmeBillingDispute $dispute, User $actor, string $action, array $extra): void
    {
        try {
            $this->auditLogs->log(
                RmeBillingDisputeAuditEvent::ENTITY_DISPUTE,
                (int) $dispute->id,
                $action,
                null,
                array_merge([
                    'dispute_id' => (int) $dispute->id,
                    'rme_invoice_id' => (int) $dispute->rme_invoice_id,
                    'branch_id' => (int) $dispute->branch_id,
                    'status' => (string) $dispute->status,
                ], $extra),
                $actor,
            );
        } catch (\Throwable $e) {
            Log::error('RME billing dispute escalation event could not be audited.', [
                'dispute_id' => (int) $dispute->id,
                'action' => $action,
                'exception' => $e::class,
            ]);
        }
    }

    private function number(RmeBillingDispute $dispute): string
    {
        return $dispute->dispute_number !== null ? (string) $dispute->dispute_number : 'Laporan #'.(int) $dispute->id;
    }

    private function invoiceNumber(RmeBillingDispute $dispute): string
    {
        return (string) ($dispute->invoice()->value('invoice_number') ?? '#'.(int) $dispute->rme_invoice_id);
    }
}
