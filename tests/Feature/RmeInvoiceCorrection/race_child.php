<?php

declare(strict_types=1);

/*
| FIX-RME-INVOICE-CORRECTION-ZERO-ORIGINAL-GUARD-1 (rule 201) — the COMPETING
| transaction of RmeInvoiceCorrectionRejectionDeactivationRaceTest.
|
| Not a test file (Pest only collects *Test.php). The race suite starts this
| script as a child PHP process. It boots the REAL application against the same
| PostgreSQL database, opens one transaction, runs the requested steps through
| the real services (the correction rejection, the user deactivation) or as raw
| SQL, prints LOCKED, holds every lock it took for `hold` seconds, then commits
| or rolls back. The parent runs the competing real service meanwhile, so the
| two genuinely interleave — one PHP process cannot hold a transaction open
| while another blocks on it.
|
| A service's own DB::transaction() runs nested inside the outer transaction (a
| savepoint), so every row lock it takes is held until the outer COMMIT /
| ROLLBACK below — which is exactly the window the parent must race.
|
| Usage (by the suite only): php race_child.php <base64 JSON payload>
*/

use App\Models\User;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmeInvoiceCorrection;
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

try {
    DB::beginTransaction();

    foreach ((array) $payload['steps'] as $step) {
        match ($step['kind']) {
            'select' => DB::select((string) $step['sql'], (array) ($step['bindings'] ?? [])),
            'statement' => DB::statement((string) $step['sql'], (array) ($step['bindings'] ?? [])),
            'reject_correction' => app(RmeInvoiceCorrectionService::class)->reject(
                RmeInvoiceCorrection::query()->findOrFail((int) $step['correction']),
                User::query()->findOrFail((int) $step['actor']),
                (string) $step['note'],
            ),
            'deactivate_user' => app(UserService::class)->deactivate(User::query()->findOrFail((int) $step['user'])),
            // FEATURE-RME-POSTPAYMENT-INVOICE-CORRECTION-1 — the post-payment races
            // (RmeInvoiceCorrectionPostPaymentConcurrencyTest).
            'approve_correction' => app(RmeInvoiceCorrectionService::class)->approve(
                RmeInvoiceCorrection::query()->findOrFail((int) $step['correction']),
                User::query()->findOrFail((int) $step['actor']),
                null,
            ),
            'pay_invoice' => app(RmePaymentService::class)->pay(
                RmeInvoice::query()->findOrFail((int) $step['invoice']),
                User::query()->findOrFail((int) $step['actor']),
                (array) $step['form'],
            ),
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
