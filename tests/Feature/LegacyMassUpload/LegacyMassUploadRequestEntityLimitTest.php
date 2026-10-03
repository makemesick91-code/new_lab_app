<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\LegacyImport\MassUpload\Models\LegacyMassUploadBatch;
use App\Modules\LegacyImport\MassUpload\Requests\StoreLegacyMassUploadPackageRequest;
use App\Modules\LegacyImport\MassUpload\Support\LegacyMassUploadBatchStatus;
use App\Modules\Patient\Models\Patient;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

require_once __DIR__.'/../LegacyOdontogram/helpers.php';

/**
 * BUGFIX-MASS-UPLOAD-REQUEST-ENTITY-TOO-LARGE-1.
 *
 * Production refused a 2 860 825-byte Mass Upload package with a bare
 * `413 Request Entity Too Large`. The package was well within the application's
 * own 500 MiB ceiling; nginx rejected it at its COMPILED DEFAULT of 1 MiB,
 * before the request ever reached PHP or Laravel, and PHP-FPM's inherited stock
 * `upload_max_filesize = 2M` would have rejected it immediately afterwards.
 *
 * The defect was not in the application. It was that two runtime layers sat
 * BELOW the application's declared limit, so the runtime — not the application —
 * decided what an operator may upload, and answered with a status code instead
 * of a reason.
 *
 * WHAT THESE TESTS ARE FOR
 * The archive-safety suites already prove Zip Slip, symlink entries, bomb
 * ceilings, magic bytes and per-document caps. None of them could fail for this
 * bug, because none of them can see the runtime. These tests assert the ONE
 * property that was missing: every runtime ceiling must sit at or above the
 * application's canonical ceiling, and all of them must stay bounded.
 *
 * They read the repository-managed runtime records on purpose. Those files are
 * the version-controlled source of the production nginx include and PHP-FPM
 * pool, so a future edit that re-lowers a ceiling fails here rather than in a
 * clinic.
 */
beforeEach(function (): void {
    seedAccessControl();
    legacyRmeArchiveFlag(true);
    lodoFlag(true);
    Storage::fake('legacy_rme_private');
    Storage::fake('legacy_odontogram_private');
    legacyMassUploadFakeDisk();
    Bus::fake();

    legacyRmeMigrationWave(['TLK1']);
    legacyRmeAdmitBranch('TLK1');
});

/**
 * Parse a PHP/nginx shorthand byte size ("500M", "512m", "1g") into bytes.
 *
 * Both notations use binary multiples, so this is the same arithmetic the two
 * runtimes apply, and comparing parsed bytes is the only way to compare an
 * nginx `512m` against a PHP `500M` against a config integer.
 */
function lmuLimitBytes(string $value): int
{
    $value = trim($value);

    if (! preg_match('/^(\d+)\s*([kmg]?)$/i', $value, $m)) {
        throw new RuntimeException('Unparseable size shorthand: ['.$value.']');
    }

    return (int) $m[1] * match (strtolower($m[2])) {
        'k' => 1024,
        'm' => 1024 ** 2,
        'g' => 1024 ** 3,
        default => 1,
    };
}

function lmuRuntimeFile(string $relative): string
{
    $path = base_path($relative);

    if (! is_file($path)) {
        throw new RuntimeException('Missing repository-managed runtime record: '.$relative);
    }

    return (string) file_get_contents($path);
}

/** The single declared value of an nginx directive in the repo-managed include. */
function lmuNginxDirective(string $directive): string
{
    $source = lmuRuntimeFile('deploy/nginx/upload-body-size.conf');

    // Only real directives count — a directive named inside a comment must not
    // satisfy this, or the record could document a ceiling it never sets.
    $lines = preg_grep(
        '/^\s*'.preg_quote($directive, '/').'\s+/',
        array_filter(
            preg_split('/\R/', $source) ?: [],
            static fn (string $line): bool => ! str_starts_with(ltrim($line), '#'),
        ),
    ) ?: [];

    if (count($lines) !== 1) {
        throw new RuntimeException(
            'Expected exactly one active `'.$directive.'` directive, found '.count($lines)
        );
    }

    return rtrim(trim(explode(' ', trim((string) reset($lines)), 2)[1] ?? ''), ';');
}

/** The single declared value of a php_admin_value in the repo-managed pool. */
function lmuPoolAdminValue(string $key): ?string
{
    $source = lmuRuntimeFile('deploy/php-fpm/daengtisiams.conf');

    $lines = preg_grep(
        '/^\s*php_admin_value\['.preg_quote($key, '/').'\]\s*=/',
        array_filter(
            preg_split('/\R/', $source) ?: [],
            static fn (string $line): bool => ! str_starts_with(ltrim($line), ';'),
        ),
    ) ?: [];

    if ($lines === []) {
        return null;
    }

    if (count($lines) !== 1) {
        throw new RuntimeException('Expected one php_admin_value['.$key.'], found '.count($lines));
    }

    return trim(explode('=', (string) reset($lines), 2)[1]);
}

function lmuLimitUploader(): User
{
    $user = userWith(['view_legacy_rme_imports', 'create_legacy_rme_imports']);
    $user->forceFill(['branch_id' => legacyRmeBranch('TLK1')->id])->save();

    return legacyRmeOperator($user);
}

function lmuLimitPatient(): Patient
{
    $patient = legacyRmeArchivablePatient(['date_of_birth' => '1990-01-01']);
    legacyRmeNativeVisit($patient, '2022-03-10');

    return $patient;
}

/**
 * A real ZIP big enough for kilobyte arithmetic to be meaningful.
 *
 * The minimal fixture archive is ~510 bytes, which rounds to a single kilobyte
 * and makes every boundary assertion degenerate. Padding the document with
 * INCOMPRESSIBLE bytes is what grows the archive — padding with repeated bytes
 * would be squeezed back out by deflate.
 */
function lmuPaddedZip(int $padBytes = 60_000): UploadedFile
{
    return legacyMassUploadZip(
        documents: ['a.pdf' => legacyRmePdfBytes(1).random_bytes($padBytes)],
        manifestRows: [legacyMassUploadRmeRow('RM-001', 'a.pdf', '2018-01-02')],
    );
}

/** Validate the `package` field exactly as the real FormRequest does. */
function lmuValidatePackageField(UploadedFile $file): array
{
    $rules = (new StoreLegacyMassUploadPackageRequest)->rules();
    $messages = (new StoreLegacyMassUploadPackageRequest)->messages();

    $validator = Validator::make(['package' => $file], $rules, $messages);

    return $validator->fails() ? $validator->errors()->get('package') : [];
}

/*
|--------------------------------------------------------------------------
| The invariant that was missing: runtime >= application
|--------------------------------------------------------------------------
*/

it('declares an nginx body ceiling at or above the application package ceiling', function (): void {
    $app = (int) config('legacy_mass_upload.package.max_bytes');
    $nginx = lmuLimitBytes(lmuNginxDirective('client_max_body_size'));

    // The exact failure in production: the effective nginx ceiling (1 MiB by
    // compiled default, because the directive was declared nowhere) was three
    // orders of magnitude below the application's.
    expect($nginx)->toBeGreaterThanOrEqual($app);

    // "Slightly above", not "equal": the request is multipart/form-data, so the
    // body carries part boundaries, the CSRF token and the other form fields in
    // addition to the ZIP itself.
    expect($nginx)->toBeGreaterThan($app);
});

it('keeps the nginx body ceiling bounded rather than unlimited', function (): void {
    $nginx = lmuLimitBytes(lmuNginxDirective('client_max_body_size'));

    // `client_max_body_size 0` disables the check entirely. These are clinical
    // documents; an unbounded archive upload is a disk-exhaustion surface.
    expect($nginx)->toBeGreaterThan(0);

    // A ceiling far above the application's would make nginx useless as an
    // outer bound and let a hostile body occupy a worker before the application
    // can refuse it.
    expect($nginx)->toBeLessThanOrEqual((int) config('legacy_mass_upload.package.max_bytes') * 2);
});

it('declares a pool upload ceiling at or above the application package ceiling', function (): void {
    $app = (int) config('legacy_mass_upload.package.max_bytes');
    $declared = lmuPoolAdminValue('upload_max_filesize');

    // Inheriting an untuned php.ini is what broke this: the pool deliberately
    // declared nothing, and php.ini held the stock Debian 2M.
    expect($declared)->not->toBeNull();
    expect(lmuLimitBytes((string) $declared))->toBeGreaterThanOrEqual($app);
});

it('keeps the pool post ceiling strictly above its upload ceiling', function (): void {
    $upload = lmuPoolAdminValue('upload_max_filesize');
    $post = lmuPoolAdminValue('post_max_size');

    expect($upload)->not->toBeNull();
    expect($post)->not->toBeNull();

    // If the whole POST is refused for exceeding post_max_size, PHP discards
    // $_POST, the CSRF token vanishes and the operator is told "Page Expired"
    // instead of anything about size.
    expect(lmuLimitBytes((string) $post))
        ->toBeGreaterThan(lmuLimitBytes((string) $upload));
});

it('keeps nginx as the outer bound so the post ceiling is never the rejector', function (): void {
    $nginx = lmuLimitBytes(lmuNginxDirective('client_max_body_size'));
    $post = lmuLimitBytes((string) lmuPoolAdminValue('post_max_size'));

    // nginx must refuse first, with an honest 413, rather than letting PHP
    // silently strip the body and produce a 419 about an expired page.
    expect($nginx)->toBeLessThanOrEqual($post);
});

it('keeps every pool ceiling bounded rather than unlimited', function (): void {
    foreach (['upload_max_filesize', 'post_max_size', 'memory_limit'] as $key) {
        $declared = lmuPoolAdminValue($key);
        expect($declared)->not->toBeNull();

        // -1 means unlimited for memory_limit; 0 means unlimited for the sizes.
        expect(trim((string) $declared))->not->toBe('-1')->not->toBe('0');
        expect(lmuLimitBytes((string) $declared))->toBeGreaterThan(0);
    }
});

it('raises the pool input time above the stock sixty seconds', function (): void {
    $declared = lmuPoolAdminValue('max_input_time');

    expect($declared)->not->toBeNull();

    // Receiving a package the size of the application ceiling cannot finish in
    // 60s. Safe to raise because nginx buffers the body in full before handing
    // it over, so this budget reads a local buffer; a stalled client is still
    // bounded by nginx's own client_body_timeout.
    expect((int) $declared)->toBeGreaterThan(60);

    // Bounded: never unlimited.
    expect((int) $declared)->toBeGreaterThan(0);
});

it('does not relax max execution time for every request on the pool', function (): void {
    // The intake path raises its OWN budget at runtime. Declaring it pool-wide
    // would remove the 30s ceiling from every page and every query too.
    expect(lmuPoolAdminValue('max_execution_time'))->toBeNull();
});

it('bounds the intake execution budget and never treats it as unlimited', function (): void {
    $seconds = (int) config('legacy_mass_upload.intake.max_execution_seconds');

    expect($seconds)->toBeGreaterThan(30);

    // 0 means "no limit" to PHP, which would let a failed intake hold one of
    // the five pool workers indefinitely.
    expect($seconds)->toBeGreaterThan(0);
});

/*
|--------------------------------------------------------------------------
| The application stays the authority
|--------------------------------------------------------------------------
*/

it('derives the framework size rule from the application ceiling', function (): void {
    $rules = (new StoreLegacyMassUploadPackageRequest)->rules();

    $max = collect($rules['package'])
        ->first(static fn ($rule): bool => is_string($rule) && str_starts_with($rule, 'max:'));

    expect($max)->not->toBeNull();

    // The rule is in kilobytes; it must express the configured byte ceiling and
    // not a hardcoded number that could drift away from it.
    expect((int) substr((string) $max, 4))
        ->toBe((int) ceil(((int) config('legacy_mass_upload.package.max_bytes')) / 1024));
});

it('refuses an oversize package with a reason rather than a status code', function (): void {
    // Shrink the ceiling instead of building a 500 MiB fixture: the property
    // under test is which layer refuses and what it says, not the number.
    $zip = lmuPaddedZip();

    $ceiling = 4096;
    expect($zip->getSize())->toBeGreaterThan($ceiling);

    config()->set('legacy_mass_upload.package.max_bytes', $ceiling);

    $errors = lmuValidatePackageField($zip);

    // The operator-facing refusal — the whole point of raising the runtime
    // ceilings above the application's.
    expect($errors)->toContain('Ukuran paket arsip melebihi batas yang diizinkan.');
});

it('accepts a package at the ceiling boundary', function (): void {
    $zip = lmuPaddedZip();

    // Exactly the package's own size, rounded up to the kilobyte the rule uses.
    config()->set('legacy_mass_upload.package.max_bytes', (int) ceil($zip->getSize() / 1024) * 1024);

    expect(lmuValidatePackageField($zip))->toBe([]);
});

it('refuses a package one kilobyte past the ceiling', function (): void {
    $zip = lmuPaddedZip();

    // One kilobyte below the archive's own size: the smallest ceiling the
    // archive can actually exceed, so this measures the boundary and not a
    // degenerate negative limit.
    $ceiling = ((int) floor($zip->getSize() / 1024) - 1) * 1024;
    expect($ceiling)->toBeGreaterThan(0);

    config()->set('legacy_mass_upload.package.max_bytes', $ceiling);

    expect(lmuValidatePackageField($zip))
        ->toContain('Ukuran paket arsip melebihi batas yang diizinkan.');
});

/*
|--------------------------------------------------------------------------
| Raising the package ceiling must not raise any safety ceiling
|--------------------------------------------------------------------------
*/

it('keeps the archive safety ceilings independent of the package ceiling', function (): void {
    $package = (int) config('legacy_mass_upload.package.max_bytes');

    // Each of these is its own fail-closed control. The bugfix moved the
    // runtime up to the package ceiling; it must not have moved any of them.
    expect((int) config('legacy_mass_upload.package.document_max_bytes'))
        ->toBe(20971520)
        ->toBeLessThan($package);

    expect((int) config('legacy_mass_upload.package.manifest_max_bytes'))
        ->toBe(5242880)
        ->toBeLessThan($package);

    expect((int) config('legacy_mass_upload.package.max_entries'))->toBe(1200);
    expect((float) config('legacy_mass_upload.package.max_compression_ratio'))->toBe(120.0);
    expect((int) config('legacy_mass_upload.package.total_uncompressed_max_bytes'))->toBe(2147483648);
});

it('still refuses an archive bomb after the runtime ceilings were raised', function (): void {
    // Highly compressible payload: a large uncompressed total behind a small ZIP.
    config()->set('legacy_mass_upload.package.total_uncompressed_max_bytes', 2048);

    $zip = legacyMassUploadZip(
        documents: ['a.pdf' => legacyRmePdfBytes(1).str_repeat('0', 200_000)],
        manifestRows: [legacyMassUploadRmeRow('RM-001', 'a.pdf', '2018-01-02')],
    );

    $uploader = lmuLimitUploader();

    $this->actingAs($uploader)
        ->post(route('settings.rme.legacy-mass-imports.store'), ['package' => $zip])
        ->assertSessionHasErrors('package');

    // The batch row survives on purpose: it is the provenance record of a
    // refused package. What must NOT happen is extraction, dispatch or publish.
    expect(LegacyMassUploadBatch::sole()->status)
        ->toBe(LegacyMassUploadBatchStatus::PACKAGE_REJECTED);
    Bus::assertNothingDispatched();
});

/*
|--------------------------------------------------------------------------
| The fix changes size ceilings only — not the workflow
|--------------------------------------------------------------------------
*/

it('still lands an accepted rme package in review without publishing anything', function (): void {
    $uploader = lmuLimitUploader();
    $patient = lmuLimitPatient();

    $zip = legacyMassUploadZip(
        documents: ['a.pdf' => legacyRmePdfBytes(1)],
        manifestRows: [
            legacyMassUploadRmeRow($patient->medical_record_number, 'a.pdf', '2018-01-02', '2019-05-06'),
        ],
    );

    $this->actingAs($uploader)
        ->post(route('settings.rme.legacy-mass-imports.store'), ['package' => $zip])
        ->assertRedirect();

    $batch = LegacyMassUploadBatch::sole();

    // Preflight only. Intake must never dispatch, and must never publish.
    expect($batch->status)->not->toBe(LegacyMassUploadBatchStatus::DISPATCHING);
    Bus::assertNothingDispatched();
});

it('still lands an accepted odontogram package in review without publishing anything', function (): void {
    $user = userWith(['view_legacy_odontogram_imports', 'create_legacy_odontogram_imports']);
    $user->forceFill(['branch_id' => legacyRmeBranch('TLK1')->id])->save();
    $uploader = legacyRmeOperator($user);

    $patient = lmuLimitPatient();

    $zip = legacyMassUploadZip(
        documents: ['a.pdf' => legacyRmePdfBytes(1)],
        manifestRows: [
            legacyMassUploadOdontogramRow($patient->medical_record_number, 'a.pdf', '2018-01-02'),
        ],
    );

    $this->actingAs($uploader)
        ->post(route('settings.rme.legacy-mass-odontograms.store'), ['package' => $zip])
        ->assertRedirect();

    $batch = LegacyMassUploadBatch::sole();

    expect($batch->status)->not->toBe(LegacyMassUploadBatchStatus::DISPATCHING);
    Bus::assertNothingDispatched();
});

it('still refuses a malformed archive at intake', function (): void {
    $uploader = lmuLimitUploader();

    $zip = UploadedFile::fake()->createWithContent('broken.zip', 'PK'.str_repeat('x', 400));

    $this->actingAs($uploader)
        ->post(route('settings.rme.legacy-mass-imports.store'), ['package' => $zip])
        ->assertSessionHasErrors('package');

    expect(LegacyMassUploadBatch::sole()->status)
        ->toBe(LegacyMassUploadBatchStatus::PACKAGE_REJECTED);
    Bus::assertNothingDispatched();
});
