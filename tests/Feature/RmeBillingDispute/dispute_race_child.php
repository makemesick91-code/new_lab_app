<?php

declare(strict_types=1);

/*
| IMPLEMENTATION-RME-BILLING-DISPUTE-ESCALATION-OPS-OQ-1 (rule 203) — the
| COMPETING transaction of RmeBillingDisputeConcurrencyTest.
|
| Not a test file (Pest only collects *Test.php). The concurrency suite starts
| this script as a child PHP process. It boots the REAL application against the
| same PostgreSQL database, opens one transaction, runs the requested steps
| through the real services (report / review / escalate / resolve a dispute,
| pay an invoice, submit a correction, deactivate a user) or as raw SQL, prints
| LOCKED, holds every lock it took for `hold` seconds, then commits or rolls
| back. The parent runs the competing real service meanwhile, so the two
| genuinely interleave.
|
| A service's own DB::transaction() runs nested inside the outer transaction (a
| savepoint), so every row lock it takes is held until the outer COMMIT /
| ROLLBACK below — which is exactly the window the parent must race.
|
| HARDENING-RME-BILLING-DISPUTE-BRANCH-REVIEWER-CONTROL-PAYMENT-1 (rule 204) adds
| the reviewer designation (designate / revoke), the review action, the
| Kontrol-only payment, the ordinary control payment, a correction approval
| and a branch-change approval — each through its REAL service.
|
| Usage (by the suite only): php dispute_race_child.php <base64 JSON payload>
*/

use App\Models\User;
use App\Modules\RmeInvoice\Models\RmeBillingDispute;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmeInvoiceCorrection;
use App\Modules\RmeInvoice\Services\RmeBillingDisputeReviewerService;
use App\Modules\RmeInvoice\Services\RmeBillingDisputeService;
use App\Modules\RmeInvoice\Services\RmeInvoiceCorrectionService;
use App\Modules\RmeInvoice\Services\RmePaymentService;
use App\Modules\RmeOnlineContext\Services\BranchChangeApprovalService;
use App\Modules\User\Services\UserService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$payload = json_decode((string) base64_decode((string) ($argv[1] ?? ''), true), true);
if (! is_array($payload)) {
    fwrite(STDOUT, "ERROR invalid payload\n");
    exit(2);
}

$root = dirname(__DIR__, 3);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

foreach ((array) ($payload['config'] ?? []) as $key => $value) {
    config()->set((string) $key, $value);
}

$user = fn (array $step, string $key = 'actor'): User => User::query()->findOrFail((int) $step[$key]);
$dispute = fn (array $step): RmeBillingDispute => RmeBillingDispute::query()->findOrFail((int) $step['dispute']);
$invoice = fn (array $step): RmeInvoice => RmeInvoice::query()->findOrFail((int) $step['invoice']);

try {
    DB::beginTransaction();

    foreach ((array) $payload['steps'] as $step) {
        if (isset($step['actor'])) {
            auth()->setUser($user($step));
        }

        match ($step['kind']) {
            'select' => DB::select((string) $step['sql'], (array) ($step['bindings'] ?? [])),
            'statement' => DB::statement((string) $step['sql'], (array) ($step['bindings'] ?? [])),
            'report_dispute' => app(RmeBillingDisputeService::class)->report($invoice($step), $user($step), [
                'reason_category' => (string) $step['reason'],
                'description' => (string) $step['description'],
            ]),
            'escalate_dispute' => app(RmeBillingDisputeService::class)->escalate($dispute($step), $user($step), (string) $step['note']),
            'resolve_dispute' => app(RmeBillingDisputeService::class)->resolve($dispute($step), $user($step), (array) $step['data']),
            'pay_invoice' => app(RmePaymentService::class)->pay($invoice($step), $user($step), (array) $step['form']),
            'submit_correction' => app(RmeInvoiceCorrectionService::class)->submit($invoice($step), $user($step), (array) $step['data']),
            'deactivate_user' => app(UserService::class)->deactivate($user($step, 'user')),
            'start_review' => app(RmeBillingDisputeService::class)->startReview($dispute($step), $user($step), $step['note'] ?? null),
            'designate_reviewer' => app(RmeBillingDisputeReviewerService::class)->designate($user($step), $user($step, 'user'), (int) $step['branch'], (string) $step['reason']),
            'revoke_designation' => app(RmeBillingDisputeReviewerService::class)->revoke(
                $user($step),
                app(RmeBillingDisputeReviewerService::class)->find((int) $step['designation']) ?? throw new InvalidArgumentException('designation not found'),
                (string) $step['reason'],
            ),
            'pay_control_only' => app(RmePaymentService::class)->payControlInvoiceOnly($invoice($step), $user($step), (array) $step['form']),
            'pay_control' => app(RmePaymentService::class)->allocateControlPayment($invoice($step), $user($step), (array) $step['form']),
            'approve_correction' => app(RmeInvoiceCorrectionService::class)->approve(RmeInvoiceCorrection::query()->findOrFail((int) $step['correction']), $user($step)),
            'approve_branch_change' => app(BranchChangeApprovalService::class)->approve((int) $step['request'], $user($step)),
            default => throw new InvalidArgumentException('Unknown race step '.(string) $step['kind']),
        };
    }
} catch (Throwable $e) {
    fwrite(STDOUT, 'ERROR '.$e::class.': '.$e->getMessage()."\n");
    DB::rollBack();
    exit(3);
}

fwrite(STDOUT, "LOCKED\n");
fflush(STDOUT);
usleep((int) (((float) ($payload['hold'] ?? 2.0)) * 1_000_000));

if (($payload['finish'] ?? 'commit') === 'rollback') {
    DB::rollBack();
    fwrite(STDOUT, "ROLLED_BACK\n");
} else {
    DB::commit();
    fwrite(STDOUT, "COMMITTED\n");
}

exit(0);
