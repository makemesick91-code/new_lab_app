<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| DMS-RME-COMPREHENSIVE-CLOSURE-1 (OD-5-9, rule 121 §6) — consent signature copy phase
|--------------------------------------------------------------------------
|
| `rme:consent-signatures-migrate` copies every consent signature object from the
| legacy private disk to the clinical evidence disk under the SAME key, proves the
| copy byte-identical by SHA-256, never overwrites a different object, never
| deletes the source, and prints counts only. Dry run by default.
|
| Review round (2026-11-17): a failed write is WRITE_FAILED and never stops the
| run or prints the key; an object that exists but cannot be read is
| UNREADABLE — never copied as an empty object, never overwritten on the
| clinical disk; the command refuses root (it runs as the runtime user
| `daengtisiams`, because the clinical disk is private 0700/0600).
|
| Selected by the existing `RmeVisitConsent` critical-gate token in both runner
| variants and declared in critical_gate_mandatory_suites (CG-19). Helper prefix
| `rcsm` is unique because the critical gate loads every suite into one process.
*/

use App\Console\Commands\RmeConsentSignaturesMigrateCommand;
use App\Modules\Consent\Models\RmeVisitConsent;
use App\Modules\LabOrder\Models\AuditLog;
use App\Support\Storage\ClinicalEvidenceStorage;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToWriteFile;

beforeEach(function () {
    seedAccessControl();
    config()->set('rme_consent.legacy_signature_disk', 'local');
    Storage::fake('local');
    Storage::fake(ClinicalEvidenceStorage::diskName());
});

function rcsmPng(string $tag): string
{
    return "\x89PNG\r\n\x1a\n".$tag;
}

/** A consent whose two signature objects live on the legacy disk. */
function rcsmLegacyConsent(): RmeVisitConsent
{
    $consent = RmeVisitConsent::factory()->create();
    $consent->forceFill([
        'consenter_signature_path' => 'rme-consents/'.$consent->consent_number.'/consenter-a.png',
        'doctor_signature_path' => 'rme-consents/'.$consent->consent_number.'/doctor-a.png',
    ])->save();

    Storage::disk('local')->put($consent->consenter_signature_path, rcsmPng('consenter-'.$consent->id));
    Storage::disk('local')->put($consent->doctor_signature_path, rcsmPng('doctor-'.$consent->id));

    return $consent->fresh();
}

function rcsmRun(array $options = []): array
{
    $code = Artisan::call('rme:consent-signatures-migrate', $options);

    return [$code, Artisan::output()];
}

it('SM-01 writes nothing on a dry run and reports what it would copy', function () {
    rcsmLegacyConsent();
    rcsmLegacyConsent();

    [$code, $out] = rcsmRun();

    expect($code)->toBe(0)
        ->and($out)->toContain('TO_COPY=4')
        ->and($out)->toMatch('/^COPIED=0$/m')
        ->and($out)->toContain('CONSENT_SIGNATURE_MIGRATION=DRY_RUN')
        ->and(Storage::disk(ClinicalEvidenceStorage::diskName())->allFiles())->toBe([])
        ->and(AuditLog::query()->where('action', RmeConsentSignaturesMigrateCommand::AUDIT_ACTION)->count())->toBe(0);
});

it('SM-02 copies every object key for key, byte-identical, and keeps the source', function () {
    $consent = rcsmLegacyConsent();
    $before = $consent->getAttributes();

    [$code, $out] = rcsmRun(['--apply' => true]);

    $target = Storage::disk(ClinicalEvidenceStorage::diskName());

    expect($code)->toBe(0)
        ->and($out)->toContain('COPIED=2')
        ->and($out)->toContain('CONSENT_SIGNATURE_MIGRATION=APPLIED')
        ->and($target->get($consent->consenter_signature_path))->toBe(Storage::disk('local')->get($consent->consenter_signature_path))
        ->and($target->get($consent->doctor_signature_path))->toBe(Storage::disk('local')->get($consent->doctor_signature_path))
        // Source untouched, no column rewritten.
        ->and(Storage::disk('local')->allFiles())->toHaveCount(2)
        ->and($consent->fresh()->getAttributes())->toBe($before)
        // The copied object reads back through the clinical evidence authority.
        ->and(ClinicalEvidenceStorage::dataUri($consent->consenter_signature_path))->toStartWith('data:image/png;base64,');
});

it('SM-03 is idempotent: a second run copies nothing and stays clean', function () {
    rcsmLegacyConsent();
    rcsmRun(['--apply' => true]);

    [$code, $out] = rcsmRun(['--apply' => true]);

    expect($code)->toBe(0)
        ->and($out)->toContain('ALREADY_COPIED=2')
        ->and($out)->toMatch('/^COPIED=0$/m');
});

it('SM-04 never overwrites a different object already on the clinical disk and fails the run', function () {
    $consent = rcsmLegacyConsent();
    $target = Storage::disk(ClinicalEvidenceStorage::diskName());
    $target->put($consent->consenter_signature_path, rcsmPng('something-else'));

    [$code, $out] = rcsmRun(['--apply' => true]);

    expect($code)->toBe(1)
        ->and($out)->toContain('CONFLICT=1')
        ->and($out)->toContain('CONSENT_SIGNATURE_MIGRATION=INCOMPLETE')
        ->and($target->get($consent->consenter_signature_path))->toBe(rcsmPng('something-else'));
});

it('SM-05 counts a referenced object that exists on neither disk without failing', function () {
    $consent = rcsmLegacyConsent();
    Storage::disk('local')->delete($consent->doctor_signature_path);

    [$code, $out] = rcsmRun(['--apply' => true]);

    expect($code)->toBe(0)
        ->and($out)->toContain('MISSING=1')
        ->and($out)->toContain('COPIED=1');
});

it('SM-06 leaves inline data URIs alone and prints no key, number or patient detail', function () {
    $consent = rcsmLegacyConsent();
    $inline = RmeVisitConsent::factory()->create();
    $inline->forceFill(['consenter_signature_path' => 'data:image/png;base64,AAAA', 'doctor_signature_path' => null])->save();

    [, $out] = rcsmRun();

    expect($out)->toContain('INLINE=1')
        ->and($out)->not->toContain($consent->consent_number)
        ->and($out)->not->toContain('rme-consents/')
        ->and($out)->not->toContain((string) $consent->patient_name_snapshot);
});

it('SM-07 writes one summary audit row with counts only on --apply', function () {
    rcsmLegacyConsent();

    rcsmRun(['--apply' => true]);

    $row = AuditLog::query()->where('action', RmeConsentSignaturesMigrateCommand::AUDIT_ACTION)->sole();

    expect($row->new_values['copied'])->toBe(2)
        ->and($row->new_values['outcome'])->toBe('APPLIED')
        ->and(json_encode($row->new_values))->not->toContain('rme-consents/');
});

it('SM-08 counts a failed write, never prints the key or the storage message, and keeps going', function () {
    $first = rcsmLegacyConsent();
    rcsmLegacyConsent();

    $target = Mockery::mock(Filesystem::class);
    $target->shouldReceive('exists')->andReturn(false);
    $target->shouldReceive('put')->andThrow(new UnableToWriteFile('Unable to write file at location: '.$first->consenter_signature_path.'.'));
    $target->shouldReceive('delete')->andReturn(true);
    Storage::set(ClinicalEvidenceStorage::diskName(), $target);

    [$code, $out] = rcsmRun(['--apply' => true]);

    $row = AuditLog::query()->where('action', RmeConsentSignaturesMigrateCommand::AUDIT_ACTION)->sole();

    expect($code)->toBe(1)
        // Both consents were attempted: the first failure did not stop the run.
        ->and($out)->toMatch('/^WRITE_FAILED=4$/m')
        ->and($out)->toMatch('/^COPIED=0$/m')
        ->and($out)->toContain('CONSENT_SIGNATURE_MIGRATION=INCOMPLETE')
        ->and($out)->toContain(UnableToWriteFile::class)
        ->and($out)->not->toContain('rme-consents/')
        ->and($out)->not->toContain($first->consent_number)
        ->and($row->new_values['outcome'])->toBe('INCOMPLETE')
        ->and($row->new_values['write_failed'])->toBe(4);
});

it('SM-09 never turns an unreadable legacy object into an empty clinical object', function () {
    rcsmLegacyConsent();

    $source = Mockery::mock(Filesystem::class);
    $source->shouldReceive('exists')->andReturn(true);
    // A non-throwing disk answers null when the read fails after exists().
    $source->shouldReceive('get')->andReturn(null);
    Storage::set('local', $source);

    [$code, $out] = rcsmRun(['--apply' => true]);

    expect($code)->toBe(1)
        ->and($out)->toMatch('/^UNREADABLE=2$/m')
        ->and($out)->toMatch('/^COPIED=0$/m')
        ->and($out)->toContain('CONSENT_SIGNATURE_MIGRATION=INCOMPLETE')
        ->and(Storage::disk(ClinicalEvidenceStorage::diskName())->allFiles())->toBe([]);
});

it('SM-10 never overwrites a clinical object that exists but cannot be read', function () {
    rcsmLegacyConsent();

    $target = Mockery::mock(Filesystem::class);
    $target->shouldReceive('exists')->andReturn(true);
    $target->shouldReceive('get')->andThrow(new RuntimeException('permission denied'));
    $target->shouldNotReceive('put');
    $target->shouldNotReceive('delete');
    Storage::set(ClinicalEvidenceStorage::diskName(), $target);

    [$code, $out] = rcsmRun(['--apply' => true]);

    expect($code)->toBe(1)
        ->and($out)->toMatch('/^UNREADABLE=2$/m')
        ->and($out)->toMatch('/^CONFLICT=0$/m')
        ->and($out)->toContain('CONSENT_SIGNATURE_MIGRATION=INCOMPLETE');
});

it('SM-11 refuses to run as root and writes nothing', function () {
    rcsmLegacyConsent();

    Artisan::registerCommand(new class extends RmeConsentSignaturesMigrateCommand
    {
        protected function runningAsRoot(): bool
        {
            return true;
        }
    });

    [$code, $out] = rcsmRun(['--apply' => true]);

    expect($code)->toBe(1)
        ->and($out)->toContain('Refusing to run as root')
        ->and(Storage::disk(ClinicalEvidenceStorage::diskName())->allFiles())->toBe([])
        ->and(AuditLog::query()->where('action', RmeConsentSignaturesMigrateCommand::AUDIT_ACTION)->count())->toBe(0);
});
