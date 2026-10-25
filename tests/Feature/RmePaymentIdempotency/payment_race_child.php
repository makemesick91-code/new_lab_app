<?php

declare(strict_types=1);

/*
| FIX-RME-PAYMENT-SUBMISSION-IDEMPOTENCY-1 (rule 205) — the COMPETING transaction
| of RmePaymentSubmissionConcurrencyTest.
|
| Not a test file (Pest only collects *Test.php). The concurrency suite starts
| this script as a child PHP process. It boots the REAL application against the
| same PostgreSQL database, opens one transaction, runs the requested steps
| through the real services (the four payment paths, a dispute report, a
| correction submission, a deactivation) or as raw SQL, prints LOCKED, holds every
| lock it took for `hold` seconds, then commits or rolls back. The parent runs the
| competing real service meanwhile, so the two genuinely interleave.
|
| A service's own DB::transaction() runs nested inside the outer transaction (a
| savepoint), so every row lock it takes — and every row it inserts, including the
| submission ledger row and its UNIQUE(intent_key) entry — is held until the outer
| COMMIT / ROLLBACK below: exactly the window the parent must race.
|
| The parent mints every payment intent (with the application key it passes in
| the environment), so the child never invents one.
|
| Usage (by the suite only): php payment_race_child.php <base64 JSON payload>
*/

use App\Models\User;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Services\RmeBillingDisputeService;
use App\Modules\RmeInvoice\Services\RmeInvoiceCorrectionService;
use App\Modules\RmeInvoice\Services\RmePaymentService;
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
$invoice = fn (array $step): RmeInvoice => RmeInvoice::query()->findOrFail((int) $step['invoice']);

$signaled = false;

try {
    DB::beginTransaction();

    foreach ((array) $payload['steps'] as $step) {
        if (isset($step['actor'])) {
            auth()->setUser($user($step));
        }

        match ($step['kind']) {
            // Release the parent BEFORE the remaining steps run, so a later step can
            // wait on a lock the parent takes meanwhile (the deadlock scenario).
            'signal' => (function () use (&$signaled): void {
                fwrite(STDOUT, "LOCKED\n");
                fflush(STDOUT);
                $signaled = true;
            })(),
            'sleep' => usleep((int) (((float) $step['seconds']) * 1_000_000)),
            'select' => DB::select((string) $step['sql'], (array) ($step['bindings'] ?? [])),
            'statement' => DB::statement((string) $step['sql'], (array) ($step['bindings'] ?? [])),
            'pay_invoice' => app(RmePaymentService::class)->pay($invoice($step), $user($step), (array) $step['form']),
            'pay_control' => app(RmePaymentService::class)->allocateControlPayment($invoice($step), $user($step), (array) $step['form']),
            'pay_control_only' => app(RmePaymentService::class)->payControlInvoiceOnly($invoice($step), $user($step), (array) $step['form']),
            'pay_visit' => app(RmePaymentService::class)->allocateVisitPayment($invoice($step), $user($step), (array) $step['form'], array_map('intval', (array) $step['selected'])),
            'report_dispute' => app(RmeBillingDisputeService::class)->report($invoice($step), $user($step), [
                'reason_category' => (string) $step['reason'],
                'description' => (string) $step['description'],
            ]),
            'submit_correction' => app(RmeInvoiceCorrectionService::class)->submit($invoice($step), $user($step), (array) $step['data']),
            'deactivate_user' => app(UserService::class)->deactivate($user($step, 'user')),
            default => throw new InvalidArgumentException('Unknown race step '.(string) $step['kind']),
        };
    }
} catch (Throwable $e) {
    fwrite(STDOUT, 'ERROR '.$e::class.': '.$e->getMessage()."\n");
    DB::rollBack();
    exit(3);
}

if (! $signaled) {
    fwrite(STDOUT, "LOCKED\n");
    fflush(STDOUT);
}
usleep((int) (((float) ($payload['hold'] ?? 2.0)) * 1_000_000));

if (($payload['finish'] ?? 'commit') === 'rollback') {
    DB::rollBack();
    fwrite(STDOUT, "ROLLED_BACK\n");
} else {
    DB::commit();
    fwrite(STDOUT, "COMMITTED\n");
}

exit(0);
