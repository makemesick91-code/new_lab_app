<?php

declare(strict_types=1);

/*
| FEATURE-RME-RP0-CONTROL-CONSULTATION-1 (rule 217) — the COMPETING transaction of
| RmeZeroValueClosureConcurrencyTest.
|
| Not a test file (Pest only collects *Test.php). It boots the REAL application
| against the same PostgreSQL database, opens one transaction, runs the requested
| steps through the real services (close Rp0, report a dispute, deactivate a user,
| pay a Kontrol batch) or as raw SQL, prints LOCKED, holds every lock for `hold`
| seconds, then commits or rolls back — so the parent's real service genuinely
| interleaves with it.
|
| Usage (by the suite only): php zero_value_race_child.php <base64 JSON payload>
*/

use App\Models\User;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Services\RmeBillingDisputeService;
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

try {
    DB::beginTransaction();

    foreach ((array) $payload['steps'] as $step) {
        if (isset($step['actor'])) {
            auth()->setUser($user($step));
        }

        match ($step['kind']) {
            'select' => DB::select((string) $step['sql'], (array) ($step['bindings'] ?? [])),
            'close_zero_value' => app(RmePaymentService::class)->closeZeroValueInvoice($invoice($step), $user($step)),
            'report_dispute' => app(RmeBillingDisputeService::class)->report($invoice($step), $user($step), [
                'reason_category' => (string) $step['reason'],
                'description' => (string) $step['description'],
            ]),
            'deactivate_user' => app(UserService::class)->deactivate($user($step, 'user')),
            'pay_control' => app(RmePaymentService::class)->allocateControlPayment($invoice($step), $user($step), (array) $step['form']),
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
