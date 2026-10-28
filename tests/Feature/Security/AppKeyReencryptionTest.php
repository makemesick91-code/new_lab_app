<?php

/**
 * SECURITY-G7B-APP-KEY-REENCRYPTION-1 — rotating the application key without
 * losing encrypted data, and retiring the previous key safely.
 *
 * Laravel decrypts with the current key and then each APP_PREVIOUS_KEYS entry,
 * so rotation alone keeps old rows readable only while the old key stays
 * configured. `security:app-key-reencrypt` rewrites those rows with the current
 * key so the previous key can be removed. These tests rotate real keys inside the
 * test process: rows are written under key A, the configuration moves to key B
 * with A as a previous key, and the command must report, rewrite and verify.
 */

use App\Models\User;
use App\Modules\Patient\Models\Patient;
use App\Modules\RmeInvoice\Support\RmePaymentIntent;
use App\Modules\Satusehat\Models\SatusehatCandidate;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function akrKey(): string
{
    return 'base64:'.base64_encode(random_bytes(32));
}

/** Point the application, the Crypt facade and Eloquent casts at a new key set. */
function akrUseKeys(string $current, array $previous = []): void
{
    config(['app.key' => $current, 'app.previous_keys' => $previous]);
    app()->forgetInstance('encrypter');
    Crypt::clearResolvedInstance('encrypter');
}

function akrEncrypterFor(string $key): Encrypter
{
    return new Encrypter(base64_decode(Str::after($key, 'base64:')), 'AES-256-CBC');
}

/** One row in each encrypted table, written under the CURRENT key. */
function akrSeed(): array
{
    $candidate = SatusehatCandidate::factory()->create([
        'eligibility_snapshot' => ['gate' => 'eligible', 'fields' => ['a', 'b']],
        'preview_snapshot' => ['resourceType' => 'Bundle'],
    ]);

    $user = User::factory()->create();
    $a = Patient::factory()->create();
    $b = Patient::factory()->create();
    $caseId = DB::table('trx_patient_merge_cases')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'case_number' => 'PMC-'.Str::upper(Str::random(8)),
        'patient_a_id' => $a->id,
        'patient_b_id' => $b->id,
        'status' => 'draft',
        'requested_by' => $user->id,
        'requested_at' => now(),
        'request_reason' => 'synthetic rotation test',
        'identity_snapshot_encrypted' => Crypt::encryptString(json_encode(['name' => 'synthetic'])),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $resolutionId = DB::table('trx_patient_merge_field_resolutions')->insertGetId([
        'merge_case_id' => $caseId,
        'field' => 'name',
        'comparison_status' => 'conflict',
        'final_value_encrypted' => Crypt::encryptString('synthetic value'),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return ['candidate' => $candidate->id, 'case' => $caseId, 'resolution' => $resolutionId];
}

/** Raw ciphertext of every encrypted column, keyed for comparison. */
function akrRaw(): array
{
    $raw = [];
    foreach (config('app_key_rotation.encrypted_columns') as $spec) {
        foreach (DB::table($spec['table'])->whereNotNull($spec['column'])->orderBy('id')->pluck($spec['column'], 'id') as $id => $value) {
            $raw[$spec['table'].'.'.$spec['column'].'#'.$id] = $value;
        }
    }

    return $raw;
}

function akrRun(array $options = []): array
{
    $exit = Artisan::call('security:app-key-reencrypt', $options + ['--json' => true]);

    return [$exit, json_decode(Artisan::output(), true), Artisan::output()];
}

beforeEach(function () {
    $this->keyA = akrKey();
    $this->keyB = akrKey();
    akrUseKeys($this->keyA);
});

it('lists every encrypted model cast in the rotation registry', function () {
    $registered = collect(config('app_key_rotation.encrypted_columns'))
        ->map(fn (array $s) => $s['table'].'.'.$s['column'])
        ->all();

    $found = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if ($file->getExtension() !== 'php' || ! str_contains($file->getPathname(), '/Models/')) {
            continue;
        }
        $source = (string) file_get_contents($file->getPathname());
        if (! preg_match_all("/'([a-z0-9_]+)'\s*=>\s*'encrypted(?::[a-z]+)?'/", $source, $m)) {
            continue;
        }
        preg_match('/^namespace\s+([^;]+);/m', $source, $ns);
        preg_match('/^(?:final\s+)?class\s+(\w+)/m', $source, $cls);
        $class = ($ns[1] ?? '').'\\'.($cls[1] ?? '');
        expect(class_exists($class))->toBeTrue("{$class} declares an encrypted cast");
        $table = (new $class)->getTable();
        foreach ($m[1] as $column) {
            $found[] = $table.'.'.$column;
        }
    }

    expect($found)->not->toBeEmpty();
    foreach ($found as $pair) {
        expect($registered)->toContain($pair);
    }
});

it('reports nothing to do while every value is under the current key, and writes nothing', function () {
    akrSeed();
    $before = akrRaw();

    [$exit, $report] = akrRun();

    expect($exit)->toBe(0)
        ->and($report['decision'])->toBe('GO')
        ->and($report['mode'])->toBe('dry-run')
        ->and($report['totals']['total'])->toBe(4)
        ->and($report['totals']['current'])->toBe(4)
        ->and($report['previous_key_values'])->toBe(0)
        ->and(akrRaw())->toBe($before);
});

it('keeps old rows readable after rotation and rewrites them so the previous key can be retired', function () {
    $ids = akrSeed();
    // A past timestamp, so a rewrite that touched updated_at could not hide inside the same second.
    DB::table('trx_satusehat_candidates')->where('id', $ids['candidate'])->update(['updated_at' => '2026-01-01 00:00:00']);
    $updatedAt = DB::table('trx_satusehat_candidates')->where('id', $ids['candidate'])->value('updated_at');

    akrUseKeys($this->keyB, [$this->keyA]);

    // Rotation with a previous key: still readable, flagged as previous.
    expect(SatusehatCandidate::find($ids['candidate'])->eligibility_snapshot['gate'])->toBe('eligible');
    $beforeApply = akrRaw();
    [$exit, $report] = akrRun();
    expect($exit)->toBe(0)
        ->and($report['previous_keys_configured'])->toBe(1)
        ->and($report['previous_key_values'])->toBe(4)
        ->and($report['previous_keys_removable'])->toBeFalse()
        ->and(akrRaw())->toBe($beforeApply);

    [$exit, $report] = akrRun(['--apply' => true]);
    expect($exit)->toBe(0)
        ->and($report['decision'])->toBe('GO')
        ->and($report['rewritten'])->toBe(4)
        ->and($report['previous_key_values'])->toBe(0)
        ->and($report['previous_keys_removable'])->toBeTrue();

    // Every value now decrypts with key B alone.
    $current = akrEncrypterFor($this->keyB);
    foreach (akrRaw() as $ciphertext) {
        expect(fn () => $current->decryptString($ciphertext))->not->toThrow(Exception::class);
    }

    // Retire key A: the application still reads the identical plaintext.
    akrUseKeys($this->keyB);
    expect(SatusehatCandidate::find($ids['candidate'])->eligibility_snapshot)->toBe(['gate' => 'eligible', 'fields' => ['a', 'b']])
        ->and(SatusehatCandidate::find($ids['candidate'])->preview_snapshot)->toBe(['resourceType' => 'Bundle'])
        ->and(json_decode(Crypt::decryptString(DB::table('trx_patient_merge_cases')->where('id', $ids['case'])->value('identity_snapshot_encrypted')), true))->toBe(['name' => 'synthetic'])
        ->and(Crypt::decryptString(DB::table('trx_patient_merge_field_resolutions')->where('id', $ids['resolution'])->value('final_value_encrypted')))->toBe('synthetic value');

    // Business timestamps are untouched; the run is recorded with counts only.
    expect(DB::table('trx_satusehat_candidates')->where('id', $ids['candidate'])->value('updated_at'))->toBe($updatedAt);
    $audit = DB::table('sys_audit_logs')->where('action', 'APP_KEY_VALUES_REENCRYPTED')->latest('id')->first();
    expect($audit)->not->toBeNull()
        ->and((string) $audit->new_values)->not->toContain('synthetic')
        ->and(json_decode((string) $audit->new_values, true)['rewritten'])->toBe(4);
});

it('is idempotent', function () {
    akrSeed();
    akrUseKeys($this->keyB, [$this->keyA]);

    akrRun(['--apply' => true]);
    $after = akrRaw();
    [$exit, $report] = akrRun(['--apply' => true]);

    expect($exit)->toBe(0)->and($report['rewritten'])->toBe(0)->and(akrRaw())->toBe($after);
});

it('refuses to write anything while a value decrypts with no configured key', function () {
    $ids = akrSeed();
    $foreign = akrEncrypterFor(akrKey())->encryptString(json_encode(['foreign' => true]));
    DB::table('trx_satusehat_candidates')->where('id', $ids['candidate'])->update(['preview_snapshot' => $foreign]);

    akrUseKeys($this->keyB, [$this->keyA]);
    $before = akrRaw();

    [$exit, $report] = akrRun(['--apply' => true]);

    expect($exit)->toBe(1)
        ->and($report['decision'])->toBe('NO-GO')
        ->and($report['totals']['undecryptable'])->toBe(1)
        ->and($report['previous_keys_removable'])->toBeFalse()
        ->and(akrRaw())->toBe($before);
});

it('classifies every row when the table spans several chunks', function () {
    config(['app_key_rotation.chunk_size' => 2]);
    SatusehatCandidate::factory()->count(5)->create(['eligibility_snapshot' => ['gate' => 'x']]);
    akrUseKeys($this->keyB, [$this->keyA]);

    [, $report] = akrRun(['--apply' => true]);

    expect($report['rewritten'])->toBe(5)->and($report['previous_key_values'])->toBe(0);
});

it('never prints a key, a plaintext or a ciphertext', function () {
    akrSeed();
    akrUseKeys($this->keyB, [$this->keyA]);
    $ciphertexts = array_values(akrRaw());

    [, , $json] = akrRun(['--apply' => true]);
    Artisan::call('security:app-key-reencrypt');
    $text = Artisan::output();

    foreach ([$json, $text] as $output) {
        foreach ([Str::after($this->keyA, 'base64:'), Str::after($this->keyB, 'base64:'), 'synthetic', 'eligible'] as $secret) {
            expect($output)->not->toContain($secret);
        }
        foreach ($ciphertexts as $c) {
            expect($output)->not->toContain(substr($c, 0, 40));
        }
    }
});

it('refuses a payment form signed under the previous key (rotation impact is fail-closed)', function () {
    $token = RmePaymentIntent::issue(42);
    expect(RmePaymentIntent::keyFrom($token, 42))->not->toBeNull();

    akrUseKeys($this->keyB, [$this->keyA]);

    // The intent HMAC uses the current key only: an open form must be reopened,
    // and a resubmission can never be accepted as a second payment.
    expect(RmePaymentIntent::keyFrom($token, 42))->toBeNull()
        ->and(RmePaymentIntent::keyFrom(RmePaymentIntent::issue(42), 42))->not->toBeNull();
});
