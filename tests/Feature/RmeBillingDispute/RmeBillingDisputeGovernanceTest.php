<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| IMPLEMENTATION-RME-BILLING-DISPUTE-ESCALATION-OPS-OQ-1 — governance pins.
|--------------------------------------------------------------------------
|
| The owner approved OPS-OQ-1 for every branch; this suite pins that the
| repository says so in the places a future reader looks (the decision
| register, the durable rule, the operator documents), that no switch can turn
| the hold off, that the migration fabricates no dispute from existing invoices,
| and that the operator status command is read-only, PII-free and fails closed
| when an active hold cannot be lifted by anyone.
*/

use App\Models\User;
use App\Modules\RmeInvoice\Models\RmeBillingDispute;
use App\Modules\RmeInvoice\Support\RmeBillingDisputeStatus;
use App\Modules\RmeOnlineContext\Models\UserOnlineContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/helpers.php';

beforeEach(fn () => rbdSetup());

function rbdFile(string $path): string
{
    $full = base_path($path);
    expect(is_file($full))->toBeTrue("{$path} must exist");

    return (string) file_get_contents($full);
}

it('records OPS-OQ-1 as an approved owner policy in the decision register, with the open follow-up', function () {
    $register = rbdFile('docs/decisions/rme-billing-correction-owner-decisions.md');

    expect($register)->toContain('OPS-OQ-1')
        ->and($register)->toContain('OWNER_POLICY_APPROVED')
        ->and($register)->toContain('OPS-OQ-1a')
        ->and($register)->toContain('IMPLEMENTATION-RME-BILLING-DISPUTE-ESCALATION-OPS-OQ-1');
});

it('ships the durable rule with the invariants a future change must not break', function () {
    $rule = rbdFile('.cursor/rules/203-rme-billing-dispute-escalation-ops-oq-1.mdc');

    foreach ([
        'OPS-OQ-1',
        'is not a correction',
        'every payment path',
        'whole batch',
        'never PAID, CANCELLED or Rp0',
        'one active dispute per invoice',
        'resolution',
        'SPN4',
        'D28',
        'never holds the patient',
    ] as $phrase) {
        expect(mb_strtolower($rule))->toContain(mb_strtolower($phrase));
    }
});

it('no longer tells operators that OPS-OQ-1 is an open question', function () {
    foreach ([
        '.cursor/rules/202-rme-invoice-correction-pilot-operational-readiness.mdc',
        'docs/operations/rme-invoice-correction-pilot-sop-spn4.md',
        'docs/operations/rme-invoice-correction-incident-escalation.md',
        'docs/operations/rme-invoice-correction-maker-checker-guide.md',
        'docs/operations/rme-invoice-correction-operator-handoff.md',
    ] as $path) {
        $text = rbdFile($path);
        expect($text)->toContain('rule 203')
            ->and(preg_match('/OPS-OQ-1[^a]{0,80}(open owner question|pertanyaan untuk Owner)(?![^\n]*(DIJAWAB|ANSWERED|SUPERSEDED|DISETUJUI|APPROVED))/i', $text))
            ->toBe(0, "{$path} still presents OPS-OQ-1 as open");
    }
});

it('has no switch that could turn the hold off', function () {
    $flags = (string) file_get_contents(base_path('config/feature_flags.php'));
    $service = (string) file_get_contents(base_path('app/Modules/RmeInvoice/Services/RmePaymentService.php'));

    expect($flags)->not->toContain('billing_dispute')
        ->and($service)->toContain('assertNoActiveDispute')
        ->and(substr_count($service, '$this->assertNoPaymentHold('))->toBeGreaterThanOrEqual(5);
});

it('fabricates no dispute in its migration', function () {
    $migration = rbdFile('database/migrations/2026_10_24_100001_create_trx_rme_billing_disputes_table.php');
    $code = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $migration);

    expect($code)->not->toContain('->insert(')
        ->and($code)->not->toMatch('/INSERT\s+INTO/i')
        // The invoice table is named only as a foreign key target, never read.
        ->and($code)->not->toContain("DB::table('trx_rme_invoices')")
        ->and($code)->not->toMatch('/SELECT\s/i')
        ->and(RmeBillingDispute::query()->count())->toBe(0);
});

// ─── The operator status command ────────────────────────────────────────────

it('reports GO with no disputes and a reachable reviewer, and writes nothing', function () {
    $writes = 0;
    DB::listen(function ($query) use (&$writes) {
        if (preg_match('/^\s*(insert|update|delete)/i', $query->sql) === 1) {
            $writes++;
        }
    });

    $exit = Artisan::call('rme:billing-dispute-status', ['--json' => true]);
    $report = json_decode(Artisan::output(), true);

    expect($exit)->toBe(0)
        ->and($report['verdict'])->toBe('GO')
        ->and($writes)->toBe(0);
});

it('fails closed when an active hold sits in a branch no reviewer can reach', function () {
    $dispute = rbdReport(ricInvoice(), null, ['description' => 'Rahasia: harga tambal salah ketik kasir.']);
    test()->reviewer->forceFill(['is_active' => false])->save();

    $exit = Artisan::call('rme:billing-dispute-status', ['--json' => true]);
    $output = Artisan::output();
    $report = json_decode($output, true);

    $branch = collect($report['branches'])->firstWhere('branch_code', 'IC01');
    expect($exit)->toBe(1)
        ->and($report['verdict'])->toBe('FAIL')
        ->and($branch['counts'][RmeBillingDisputeStatus::OPEN])->toBe(1)
        ->and($branch['reviewers'])->toBe(0)
        ->and(collect($report['findings'])->pluck('code')->all())->toContain('ACTIVE_DISPUTE_WITHOUT_REVIEWER')
        ->and($output)->not->toContain('Rahasia')
        ->and($output)->not->toContain((string) test()->patient->name)
        ->and($output)->not->toContain((string) $dispute->invoice->invoice_number);
});

it('reports WATCH for a branch without a reviewer, failing only under --strict', function () {
    test()->reviewer->forceFill(['is_active' => false])->save();

    expect(Artisan::call('rme:billing-dispute-status'))->toBe(0)
        ->and(Artisan::call('rme:billing-dispute-status', ['--strict' => true]))->toBe(1);
});

/**
 * Security review B — resolving whether ANOTHER account reaches a branch walks
 * the canonical working-branch scope, which expires a stale online context as a
 * side effect. Counting reviewers (status command, detail page) and routing a
 * notification must never write another account's context row.
 */
function rbdStaleContextReviewer(): User
{
    $user = userInRole('Admin Klinik');
    $user->givePermissionTo('review_rme_billing_disputes');
    rmeMakeAdminClinicActive($user, test()->branch);
    DB::table('trx_user_online_contexts')->where('user_id', $user->id)
        ->update(['last_seen_at' => now()->subHours(2)]);

    return $user->fresh();
}

it('does not expire another account\'s online context when the status command counts reviewers', function () {
    $stale = rbdStaleContextReviewer();
    rbdReport(ricInvoice());
    // Re-stale it AFTER the report, so the command is the only thing that could write it.
    DB::table('trx_user_online_contexts')->where('user_id', $stale->id)
        ->update(['status' => UserOnlineContext::STATUS_ONLINE, 'last_seen_at' => now()->subHours(2)]);
    $snapshot = fn () => DB::table('trx_user_online_contexts')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
    $before = $snapshot();
    expect(collect($before)->firstWhere('user_id', $stale->id)['status'])->toBe(UserOnlineContext::STATUS_ONLINE);

    Artisan::call('rme:billing-dispute-status', ['--json' => true]);

    // The canonical scope still ISSUES its expiry update while deciding reach, but
    // inside a transaction that is always rolled back: nothing persists.
    expect($snapshot())->toBe($before);
});

it('does not expire another account\'s online context when a report routes its notification', function () {
    $stale = rbdStaleContextReviewer();
    $before = DB::table('trx_user_online_contexts')->where('user_id', $stale->id)->value('status');

    rbdReport(ricInvoice());

    expect(DB::table('trx_user_online_contexts')->where('user_id', $stale->id)->value('status'))->toBe($before);
});
