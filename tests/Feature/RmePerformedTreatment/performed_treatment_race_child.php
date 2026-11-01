<?php

declare(strict_types=1);

/*
| FEATURE-RME-DOCTOR-PERFORMED-TREATMENTS-1 (rule 219) — the COMPETING transaction
| of RmePerformedTreatmentConcurrencyTest.
|
| Not a test file (Pest only collects *Test.php). It boots the REAL application
| against the same PostgreSQL database, opens one transaction, runs the requested
| steps through the real services (record, declare, void, complete the
| examination, close a Rp0 invoice, deactivate an account) or as raw SQL, prints
| LOCKED, holds every lock for `hold` seconds, then commits.
|
| Usage (by the suite only): php performed_treatment_race_child.php <base64 JSON payload>
*/

use App\Models\User;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\ClinicVisit\Services\ClinicVisitService;
use App\Modules\PerformedTreatment\Models\RmePerformedTreatment;
use App\Modules\PerformedTreatment\Services\RmePerformedTreatmentService;
use App\Modules\RmeInvoice\Models\RmeInvoice;
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
$visit = fn (array $step): ClinicVisit => ClinicVisit::query()->findOrFail((int) $step['visit']);
$service = fn (): RmePerformedTreatmentService => app(RmePerformedTreatmentService::class);

try {
    DB::beginTransaction();

    foreach ((array) $payload['steps'] as $step) {
        if (isset($step['actor'])) {
            auth()->setUser($user($step));
        }

        match ($step['kind']) {
            'select' => DB::select((string) $step['sql'], (array) ($step['bindings'] ?? [])),
            'statement' => DB::statement((string) $step['sql'], (array) ($step['bindings'] ?? [])),
            'record' => $service()->record($visit($step), (array) $step['data'], $user($step)),
            'declare' => $service()->declare($visit($step), (string) $step['type'], $user($step)),
            'void' => $service()->void($visit($step), RmePerformedTreatment::query()->findOrFail((int) $step['row']), (string) $step['reason'], $user($step)),
            'complete_examination' => app(ClinicVisitService::class)->transitionStatus($visit($step), ClinicVisit::STATUS_CASHIER_PENDING, $user($step)),
            'close_zero_value' => app(RmePaymentService::class)->closeZeroValueInvoice(RmeInvoice::query()->findOrFail((int) $step['invoice']), $user($step)),
            'deactivate_user' => app(UserService::class)->deactivate($user($step, 'user')),
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

DB::commit();
fwrite(STDOUT, "COMMITTED\n");

exit(0);
