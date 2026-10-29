<?php

/**
 * SECURITY-G7B-APP-KEY-REENCRYPTION-1 — recovery paths of an application key
 * rotation: reading with no previous key, migrating the SATUSEHAT snapshots,
 * restoring a backup taken before the rotation, and rolling the rotation back
 * after a complete or an interrupted re-encryption.
 *
 * Keys are generated in the test process; every value is synthetic. Each test
 * proves its end state twice: through the application (models / Crypt) and
 * against a single-key encrypter, so "readable" never silently means "readable
 * only because an old key is still configured".
 */

use App\Models\User;
use App\Modules\Patient\Models\Patient;
use App\Modules\PatientMerge\Models\PatientMergeCase;
use App\Modules\PatientMerge\Models\PatientMergeFieldResolution;
use App\Modules\Satusehat\Models\SatusehatCandidate;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function akbKey(): string
{
    return 'base64:'.base64_encode(random_bytes(32));
}

function akbUseKeys(string $current, array $previous = []): void
{
    config(['app.key' => $current, 'app.previous_keys' => $previous]);
    app()->forgetInstance('encrypter');
    Crypt::clearResolvedInstance('encrypter');
}

function akbOnly(string $key): Encrypter
{
    return new Encrypter(base64_decode(Str::after($key, 'base64:')), 'AES-256-CBC');
}

/** @return array{0: int, 1: array<string, mixed>} */
function akbRun(array $options = []): array
{
    $exit = Artisan::call('security:app-key-reencrypt', $options + ['--json' => true]);

    return [$exit, json_decode(Artisan::output(), true)];
}

/** Raw ciphertext of every registered encrypted column, keyed table.column#id. */
function akbRaw(): array
{
    $raw = [];
    foreach (config('app_key_rotation.encrypted_columns') as $spec) {
        foreach (DB::table($spec['table'])->whereNotNull($spec['column'])->orderBy('id')->pluck($spec['column'], 'id') as $id => $value) {
            $raw[$spec['table'].'.'.$spec['column'].'#'.$id] = $value;
        }
    }

    return $raw;
}

/** Write raw ciphertexts back exactly as captured — what restoring a dump does. */
function akbRestore(array $raw): void
{
    foreach ($raw as $ref => $value) {
        [$tableColumn, $id] = explode('#', $ref);
        [$table, $column] = explode('.', $tableColumn);
        DB::table($table)->where('id', (int) $id)->update([$column => $value]);
    }
}

/** Every raw value decrypts with exactly this one key. */
function akbAllDecryptWith(string $key): bool
{
    $only = akbOnly($key);
    foreach (akbRaw() as $ciphertext) {
        try {
            $only->decryptString($ciphertext);
        } catch (DecryptException) {
            return false;
        }
    }

    return true;
}

/** Number of raw values that decrypt with this one key. */
function akbCountDecryptableWith(string $key): int
{
    $only = akbOnly($key);
    $n = 0;
    foreach (akbRaw() as $ciphertext) {
        try {
            $only->decryptString($ciphertext);
            $n++;
        } catch (DecryptException) {
            // not under this key
        }
    }

    return $n;
}

/**
 * One row per encrypted table, under the key configured now, and the
 * plaintext each must read back as.
 *
 * @return array{ids: array<string, int>, expect: array<string, mixed>}
 */
function akbSeedAll(): array
{
    $eligibility = ['gate' => 'eligible', 'fields' => ['nik_masked', 'diagnosis'], 'note' => 'Pasien sintetis — ujian ✓'];
    $preview = ['resourceType' => 'Bundle', 'entry' => [['resource' => ['resourceType' => 'Encounter', 'status' => 'finished']]]];
    $candidate = SatusehatCandidate::factory()->create(['eligibility_snapshot' => $eligibility, 'preview_snapshot' => $preview]);

    $user = User::factory()->create();
    $a = Patient::factory()->create();
    $b = Patient::factory()->create();
    $identity = ['name' => 'Sintetis A', 'dob' => '1990-01-01'];
    $caseId = DB::table('trx_patient_merge_cases')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'case_number' => 'PMC-'.Str::upper(Str::random(8)),
        'patient_a_id' => $a->id,
        'patient_b_id' => $b->id,
        'status' => 'draft',
        'requested_by' => $user->id,
        'requested_at' => now(),
        'request_reason' => 'synthetic recovery test',
        'identity_snapshot_encrypted' => Crypt::encryptString(json_encode($identity)),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $resolutionId = DB::table('trx_patient_merge_field_resolutions')->insertGetId([
        'merge_case_id' => $caseId,
        'field' => 'name',
        'comparison_status' => 'conflict',
        'final_value_encrypted' => Crypt::encryptString('nilai sintetis'),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [
        'ids' => ['candidate' => $candidate->id, 'case' => $caseId, 'resolution' => $resolutionId],
        'expect' => ['eligibility' => $eligibility, 'preview' => $preview, 'identity' => $identity, 'final' => 'nilai sintetis'],
    ];
}

/** Read every seeded value back through the application with the key set configured now. */
function akbReadAll(array $seed): array
{
    $candidate = SatusehatCandidate::findOrFail($seed['ids']['candidate']);

    return [
        'eligibility' => $candidate->eligibility_snapshot,
        'preview' => $candidate->preview_snapshot,
        'identity' => PatientMergeCase::findOrFail($seed['ids']['case'])->identity_snapshot_encrypted,
        'final' => PatientMergeFieldResolution::findOrFail($seed['ids']['resolution'])->final_value_encrypted,
    ];
}

beforeEach(function () {
    $this->keyA = akbKey();
    $this->keyB = akbKey();
    akbUseKeys($this->keyA);
});

it('reads every value with no previous key configured once re-encryption is complete', function () {
    $seed = akbSeedAll();
    akbUseKeys($this->keyB, [$this->keyA]);
    [$exit] = akbRun(['--apply' => true]);
    expect($exit)->toBe(0);

    // Retire A: no previous key at all.
    akbUseKeys($this->keyB);
    [$exit, $report] = akbRun();

    expect($exit)->toBe(0)
        ->and($report['decision'])->toBe('GO')
        ->and($report['previous_keys_configured'])->toBe(0)
        ->and($report['previous_key_values'])->toBe(0)
        ->and($report['totals']['undecryptable'])->toBe(0)
        ->and($report['totals']['current'])->toBe(4)
        ->and($report['previous_keys_removable'])->toBeTrue()
        ->and(akbReadAll($seed))->toBe($seed['expect'])
        ->and(akbAllDecryptWith($this->keyB))->toBeTrue()
        ->and(akbCountDecryptableWith($this->keyA))->toBe(0);
});

it('migrates realistic SATUSEHAT snapshots across several chunks without changing a byte of plaintext', function () {
    config(['app_key_rotation.chunk_size' => 3]);
    $expected = [];
    foreach (range(1, 11) as $i) {
        $eligibility = [
            'gate' => $i % 2 ? 'eligible' : 'ineligible',
            'reasons' => $i % 2 ? [] : ['ihs_missing', 'diagnosis_unmapped'],
            'note' => "kandidat sintetis #{$i} — ö ü ✓ 漢",
            'nested' => ['depth' => ['level' => $i, 'flags' => [true, false, null]]],
        ];
        $preview = $i % 3 === 0 ? null : ['resourceType' => 'Bundle', 'total' => $i, 'entry' => array_fill(0, $i, ['resource' => ['resourceType' => 'Observation', 'valueString' => str_repeat('x', 50)]])];
        $candidate = SatusehatCandidate::factory()->create(['eligibility_snapshot' => $eligibility, 'preview_snapshot' => $preview]);
        $expected[$candidate->id] = [$eligibility, $preview];
    }
    $nonNull = collect($expected)->sum(fn ($pair) => 1 + ($pair[1] === null ? 0 : 1));

    akbUseKeys($this->keyB, [$this->keyA]);
    [$exit, $report] = akbRun(['--apply' => true]);

    expect($exit)->toBe(0)
        ->and($report['rewritten'])->toBe($nonNull)
        ->and($report['previous_key_values'])->toBe(0);

    akbUseKeys($this->keyB);
    foreach ($expected as $id => [$eligibility, $preview]) {
        $row = SatusehatCandidate::findOrFail($id);
        expect($row->eligibility_snapshot)->toBe($eligibility)
            ->and($row->preview_snapshot)->toBe($preview);
    }
    // A null snapshot stays a SQL NULL — never an encrypted "null".
    expect(DB::table('trx_satusehat_candidates')->whereNull('preview_snapshot')->count())->toBe(3)
        ->and(akbAllDecryptWith($this->keyB))->toBeTrue();
});

it('refuses a backup restored after the old key was retired, and recovers it with the escrowed key', function () {
    $seed = akbSeedAll();
    $backup = akbRaw(); // a dump taken before the rotation: every value under key A

    akbUseKeys($this->keyB, [$this->keyA]);
    akbRun(['--apply' => true]);
    akbUseKeys($this->keyB); // A retired

    akbRestore($backup);
    $restored = akbRaw();
    expect($restored)->toBe($backup);

    // Without A nothing can read the restored rows, and nothing is written.
    [$exit, $report] = akbRun(['--apply' => true]);
    expect($exit)->toBe(1)
        ->and($report['decision'])->toBe('NO-GO')
        ->and($report['totals']['undecryptable'])->toBe(4)
        ->and($report['previous_keys_removable'])->toBeFalse()
        ->and(akbRaw())->toBe($restored);

    // Recovery: the escrowed old key configured as a previous key.
    akbUseKeys($this->keyB, [$this->keyA]);
    expect(akbReadAll($seed))->toBe($seed['expect']);
    [$exit, $report] = akbRun(['--apply' => true]);
    expect($exit)->toBe(0)
        ->and($report['rewritten'])->toBe(4)
        ->and($report['previous_key_values'])->toBe(0);

    akbUseKeys($this->keyB);
    expect(akbReadAll($seed))->toBe($seed['expect'])
        ->and(akbAllDecryptWith($this->keyB))->toBeTrue();
});

it('rolls back a completed rotation: old key current, new key previous, values moved back, new key retired', function () {
    $seed = akbSeedAll();
    akbUseKeys($this->keyB, [$this->keyA]);
    akbRun(['--apply' => true]);
    expect(akbAllDecryptWith($this->keyB))->toBeTrue();

    // Rollback configuration.
    akbUseKeys($this->keyA, [$this->keyB]);
    expect(akbReadAll($seed))->toBe($seed['expect']);
    [$exit, $report] = akbRun(['--apply' => true]);
    expect($exit)->toBe(0)
        ->and($report['rewritten'])->toBe(4)
        ->and($report['previous_key_values'])->toBe(0)
        ->and($report['previous_keys_removable'])->toBeTrue();

    // The abandoned key retired: everything reads with the original key alone.
    akbUseKeys($this->keyA);
    expect(akbReadAll($seed))->toBe($seed['expect'])
        ->and(akbAllDecryptWith($this->keyA))->toBeTrue()
        ->and(akbCountDecryptableWith($this->keyB))->toBe(0);
});

it('rolls back or resumes an interrupted re-encryption with every value readable throughout', function () {
    $seed = akbSeedAll();
    $all = config('app_key_rotation.encrypted_columns');

    // Interrupted run: only the SATUSEHAT columns were rewritten before it stopped.
    akbUseKeys($this->keyB, [$this->keyA]);
    config(['app_key_rotation.encrypted_columns' => array_slice($all, 0, 2)]);
    akbRun(['--apply' => true]);
    config(['app_key_rotation.encrypted_columns' => $all]);
    expect(akbCountDecryptableWith($this->keyB))->toBe(2)
        ->and(akbCountDecryptableWith($this->keyA))->toBe(2)
        ->and(akbReadAll($seed))->toBe($seed['expect']);

    // Rollback from the mixed state: only the two rewritten values move back.
    akbUseKeys($this->keyA, [$this->keyB]);
    expect(akbReadAll($seed))->toBe($seed['expect']);
    [$exit, $report] = akbRun(['--apply' => true]);
    expect($exit)->toBe(0)->and($report['rewritten'])->toBe(2);
    akbUseKeys($this->keyA);
    expect(akbAllDecryptWith($this->keyA))->toBeTrue()->and(akbReadAll($seed))->toBe($seed['expect']);

    // Or resume forward from the same mixed state: the rest are rewritten.
    akbUseKeys($this->keyB, [$this->keyA]);
    config(['app_key_rotation.encrypted_columns' => array_slice($all, 0, 2)]);
    akbRun(['--apply' => true]);
    config(['app_key_rotation.encrypted_columns' => $all]);
    [$exit, $report] = akbRun(['--apply' => true]);
    expect($exit)->toBe(0)->and($report['rewritten'])->toBe(2)->and($report['previous_key_values'])->toBe(0);
    akbUseKeys($this->keyB);
    expect(akbAllDecryptWith($this->keyB))->toBeTrue()->and(akbReadAll($seed))->toBe($seed['expect']);
});

it('refuses a rollback that drops the key the values were moved to, and writes nothing', function () {
    akbSeedAll();
    akbUseKeys($this->keyB, [$this->keyA]);
    akbRun(['--apply' => true]);
    akbUseKeys($this->keyB);
    $settled = akbRaw();

    // Wrong rollback: the old key back as current, the new key not kept as a previous key.
    akbUseKeys($this->keyA);
    [$exit, $report] = akbRun(['--apply' => true]);

    expect($exit)->toBe(1)
        ->and($report['decision'])->toBe('NO-GO')
        ->and($report['totals']['undecryptable'])->toBe(4)
        ->and(akbRaw())->toBe($settled);
});
