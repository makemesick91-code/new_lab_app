<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| FEATURE-RME-BRANCH-TARIFF-PROVISIONING-1 — the controlled import under REAL
| concurrency (PostgreSQL only).
|--------------------------------------------------------------------------
|
| THE RACES.
|   1. ATTEST vs a master-data price edit. The import planned an ATTEST of a
|      tariff at 250.000; before it writes, another session changes the price.
|      Without the row lock + re-check, PostgreSQL's UPDATE of the approval
|      columns would wait on the editor's row, then re-apply on the NEW row
|      version — an approval recorded on a price the owner never approved.
|      With them, the import waits, re-reads the moved price and refuses.
|   2. CREATE vs a concurrent identical CREATE. The unique index
|      (branch, treatment, effective_date) makes the second writer fail, and the
|      whole import rolls back — no partial plan, no audit row, no duplicate.
|
| THE PROOF. A child PHP process plays the competing session: it locks / writes
| WITHOUT committing, signals the parent, sleeps, then commits. The parent runs
| the real service meanwhile, so the two genuinely interleave.
|
| WHY IT SKIPS ELSEWHERE. SQLite compiles lockForUpdate() to nothing.
|
| WHY THE WRITER CONNECTION. RefreshDatabase wraps the DEFAULT connection in a
| transaction a second session can never see. The suite switches the default to
| a separate connection that commits for real and deletes every row it created
| (by id watermark) afterwards — the RmeInvoiceCorrectionConcurrencyTest pattern.
|
| Run against PostgreSQL 16 (the production major):
|   DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=55491 DB_DATABASE=rtp \
|   DB_USERNAME=rtp DB_PASSWORD=rtp \
|   php artisan test tests/Feature/RmeTariffAuthority/RmeTariffProvisioningConcurrencyTest.php
*/

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\Tariff\Models\Tariff;
use App\Modules\Tariff\Services\TariffImportService;
use App\Modules\Tariff\Support\TariffAuditEvent;
use App\Modules\Treatment\Models\Treatment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

const RTPC_WRITER = 'rtpc_concurrency_writer';

const RTPC_REF = 'OWNER-TARIFF-RACE-0001';

/** Tables this suite writes to, child-before-parent delete order. */
const RTPC_TABLES = [
    'sys_audit_logs',
    'mst_tariffs',
    'mst_treatments',
    'mst_treatment_categories',
    'mst_branches',
    'users',
];

beforeEach(function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped(
            'Row-lock behaviour is only observable on PostgreSQL; SQLite compiles lockForUpdate() to nothing.',
        );
    }

    $base = config('database.connections.'.config('database.default'));
    config()->set('database.connections.'.RTPC_WRITER, $base);
    DB::purge(RTPC_WRITER);
    config()->set('database.default', RTPC_WRITER);

    seedAccessControl();

    $this->watermarks = [];
    foreach (RTPC_TABLES as $table) {
        $this->watermarks[$table] = (int) DB::connection(RTPC_WRITER)->table($table)->max('id');
    }
});

afterEach(function () {
    if (! isset($this->watermarks)) {
        return;
    }

    $writer = DB::connection(RTPC_WRITER);
    foreach (['model_has_permissions', 'model_has_roles'] as $pivot) {
        $writer->table($pivot)->where('model_type', User::class)->where('model_id', '>', $this->watermarks['users'])->delete();
    }
    foreach (RTPC_TABLES as $table) {
        $writer->table($table)->where('id', '>', $this->watermarks[$table])->delete();
    }

    DB::purge(RTPC_WRITER);
});

/** @return array{branch: Branch, scaling: Treatment, tambal: Treatment, tariff: Tariff, actor: User} */
function rtpcFixture(): array
{
    return Model::withoutEvents(function () {
        $branch = Branch::factory()->create(['code' => 'RP'.random_int(100, 999), 'is_rme_enabled' => true, 'is_active' => true]);
        $scaling = Treatment::factory()->create(['code' => 'TRT-RP'.random_int(1000, 9999), 'is_active' => true]);
        $tambal = Treatment::factory()->create(['code' => 'TRT-RQ'.random_int(1000, 9999), 'is_active' => true]);
        $tariff = Tariff::factory()->create([
            'branch_id' => $branch->id, 'treatment_id' => $scaling->id,
            'price' => '250000', 'effective_date' => '2026-01-01', 'is_active' => true,
        ]);

        return ['branch' => $branch, 'scaling' => $scaling, 'tambal' => $tambal, 'tariff' => $tariff, 'actor' => userInRole('Super Admin')];
    });
}

/**
 * Child PHP session: runs $statements uncommitted, prints LOCKED, sleeps, commits.
 *
 * @param  list<array{0: string, 1: list<mixed>}>  $statements
 * @return array{0: resource, 1: array<int, resource>}
 */
function rtpcCompeting(array $statements, float $holdSeconds = 2.0): array
{
    $db = config('database.connections.'.RTPC_WRITER);
    $payload = base64_encode(json_encode([
        'dsn' => sprintf('pgsql:host=%s;port=%s;dbname=%s', $db['host'], $db['port'], $db['database']),
        'user' => $db['username'],
        'password' => $db['password'],
        'statements' => $statements,
        'hold' => $holdSeconds,
    ]));

    $code = <<<'PHP'
        $c = json_decode(base64_decode($argv[1]), true);
        $pdo = new PDO($c['dsn'], $c['user'], $c['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->beginTransaction();
        foreach ($c['statements'] as [$sql, $bindings]) { $pdo->prepare($sql)->execute($bindings); }
        fwrite(STDOUT, "LOCKED\n"); fflush(STDOUT);
        usleep((int) ($c['hold'] * 1000000));
        $pdo->commit();
        fwrite(STDOUT, "COMMITTED\n");
        PHP;

    $process = proc_open([PHP_BINARY, '-r', $code, $payload], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    expect($process)->toBeResource();

    $line = fgets($pipes[1]);
    if (trim((string) $line) !== 'LOCKED') {
        $err = stream_get_contents($pipes[2]);
        proc_close($process);
        test()->fail('Competing transaction did not start: '.trim((string) $line).' '.$err);
    }

    return [$process, $pipes];
}

/** @param array{0: resource, 1: array<int, resource>} $handle */
function rtpcJoin(array $handle): void
{
    [$process, $pipes] = $handle;
    $rest = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    $exit = proc_close($process);
    expect($exit)->toBe(0, 'competing transaction failed: '.$err)
        ->and($rest)->toContain('COMMITTED');
}

it('refuses an ATTEST whose tariff price is changed by a concurrent session — no approval on an unapproved price', function () {
    $f = rtpcFixture();
    $rows = [['treatment_code' => (string) $f['scaling']->code, 'price' => '250000']];
    $importer = app(TariffImportService::class);
    $plan = $importer->plan((string) $f['branch']->code, '2026-01-01', $rows, RTPC_REF);
    expect($plan['rows'][0]['action'])->toBe(TariffImportService::ATTEST);

    $handle = rtpcCompeting([
        ['SELECT id FROM mst_tariffs WHERE id = ? FOR UPDATE', [$f['tariff']->id]],
        ['UPDATE mst_tariffs SET price = ? WHERE id = ?', ['255000.00', $f['tariff']->id]],
    ]);

    $refused = null;
    try {
        $importer->apply((string) $f['branch']->code, '2026-01-01', $rows, $plan['digest'], 'Owner mengesahkan tarif yang ada.', $f['actor'], RTPC_REF);
    } catch (InvalidArgumentException $e) {
        $refused = $e->getMessage();
    }
    rtpcJoin($handle);

    $fresh = Tariff::query()->find($f['tariff']->id);
    expect($refused)->toContain('PLAN_CHANGED')
        ->and((string) $fresh->price)->toBe('255000.00')
        ->and($fresh->approval_reference)->toBeNull()
        ->and(AuditLog::where('action', TariffAuditEvent::PROVENANCE_ATTESTED)->where('entity_id', $f['tariff']->id)->count())->toBe(0);
});

it('rolls the whole import back when a concurrent session creates the same tariff — no duplicate, no partial plan', function () {
    $f = rtpcFixture();
    $rows = [
        ['treatment_code' => (string) $f['tambal']->code, 'price' => '120000'],
        ['treatment_code' => (string) $f['scaling']->code, 'price' => '250000'],
    ];
    $importer = app(TariffImportService::class);
    $plan = $importer->plan((string) $f['branch']->code, '2026-01-01', $rows, RTPC_REF);
    expect($plan['creates'])->toBe(1)->and($plan['attests'])->toBe(1);

    $handle = rtpcCompeting([
        ['INSERT INTO mst_tariffs (branch_id, treatment_id, price, effective_date, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, true, now(), now())',
            [$f['branch']->id, $f['tambal']->id, '120000.00', '2026-01-01']],
    ]);

    $failed = false;
    try {
        $importer->apply((string) $f['branch']->code, '2026-01-01', $rows, $plan['digest'], 'Daftar tarif resmi cabang dari owner.', $f['actor'], RTPC_REF);
    } catch (QueryException|InvalidArgumentException) {
        $failed = true;
    }
    rtpcJoin($handle);

    expect($failed)->toBeTrue()
        ->and(Tariff::query()->where('branch_id', $f['branch']->id)->where('treatment_id', $f['tambal']->id)->count())->toBe(1)
        // The ATTEST row of the same plan was not applied either: one transaction.
        ->and(Tariff::query()->find($f['tariff']->id)->approval_reference)->toBeNull()
        ->and(AuditLog::where('action', TariffAuditEvent::IMPORTED)->whereIn('entity_id', Tariff::query()->where('branch_id', $f['branch']->id)->pluck('id'))->count())->toBe(0);
});
