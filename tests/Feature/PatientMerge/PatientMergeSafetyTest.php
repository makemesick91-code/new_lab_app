<?php

use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\LegacyOdontogram\Models\LegacyOdontogramRecord;
use App\Modules\LegacyRme\Models\LegacyRmeImport;
use App\Modules\LegacyRme\Models\LegacyRmeRecord;
use App\Modules\LegacyRme\Support\LegacyRmeImportStatus;
use App\Modules\Patient\Models\Patient;
use App\Modules\PatientMerge\Exceptions\PatientMergeBlockedException;
use App\Modules\PatientMerge\Interfaces\PatientMergeOwnershipRepositoryInterface;
use App\Modules\PatientMerge\Models\PatientRmAlias;
use App\Modules\PatientMerge\Repositories\PatientMergeOwnershipRepository;
use App\Modules\PatientMerge\Services\PatientMergeCaseService;
use App\Modules\PatientMerge\Support\PatientMergeField;
use App\Modules\PatientMerge\Support\PatientMergeStatus;
use App\Modules\PatientMerge\Support\PatientOwnedRecordRegistry;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/*
 * FEATURE-PATIENT-DUPLICATE-RESOLUTION-MERGE-1 — everything that must STOP a
 * merge, and the guarantee that a failed merge changes nothing at all.
 */

require_once __DIR__.'/helpers.php';

beforeEach(function (): void {
    seedAccessControl();
    $this->requester = pmUser('Supervisor RME');
    $this->approver = pmUser('Supervisor RME');
});

function pmBlockerCodes(callable $action): array
{
    try {
        $action();
    } catch (PatientMergeBlockedException $blocked) {
        return array_column($blocked->blockers, 'code');
    }

    return [];
}

it('covers every foreign key to mst_patients in the live schema (registry is complete)', function (): void {
    expect(app(PatientMergeOwnershipRepositoryInterface::class)->unregisteredPatientForeignKeys())->toBe([]);
});

it('refuses to merge when the live schema has an unregistered patient foreign key', function (): void {
    $repository = new class extends PatientMergeOwnershipRepository
    {
        public function unregisteredPatientForeignKeys(): array
        {
            return ['trx_new_clinical_table.patient_id'];
        }
    };
    app()->instance(PatientMergeOwnershipRepositoryInterface::class, $repository);

    $codes = pmBlockerCodes(fn () => pmSubmittedCase(pmPatient(), pmPatient(), $this->requester));

    expect($codes)->toContain('OWNERSHIP_REGISTRY_INCOMPLETE');
});

it('blocks submission while a critical identity conflict is unresolved', function (): void {
    $a = pmPatient(['name' => 'Budi Santoso', 'date_of_birth' => '1980-05-05']);
    $b = pmPatient(['name' => 'Budi Santosa', 'date_of_birth' => '1980-05-06']);
    $service = app(PatientMergeCaseService::class);

    $case = $service->create($this->requester, $a->id, $b->id, 'Dua registrasi untuk pasien yang sama.');
    $case = $service->resolve($case, $this->requester, ['name' => ['source' => PatientMergeField::SOURCE_A]], $a->id);

    $codes = pmBlockerCodes(fn () => $service->submit($case, $this->requester));

    expect($codes)->toContain('UNRESOLVED_IDENTITY_CONFLICT')
        ->and($case->fresh()->status)->toBe(PatientMergeStatus::DRAFT);
});

it('never leaves a critical field empty and requires a reason for a manual value', function (): void {
    $a = pmPatient(['name' => 'Siti']);
    $b = pmPatient(['name' => 'Sitti']);
    $service = app(PatientMergeCaseService::class);
    $case = $service->create($this->requester, $a->id, $b->id, 'Dua registrasi untuk pasien yang sama.');

    expect(fn () => $service->resolve($case, $this->requester, ['name' => ['source' => PatientMergeField::SOURCE_EMPTY]], null))
        ->toThrow(ValidationException::class);
    expect(fn () => $service->resolve($case, $this->requester, ['name' => ['source' => PatientMergeField::SOURCE_MANUAL, 'manual_value' => 'Siti Aminah', 'reason' => 'ok']], null))
        ->toThrow(ValidationException::class);
    expect(fn () => $service->resolve($case, $this->requester, ['ktp_number' => ['source' => PatientMergeField::SOURCE_MANUAL, 'manual_value' => '12345', 'reason' => 'Dicek dari KTP asli pasien.']], null))
        ->toThrow(ValidationException::class);
});

it('fails closed when the final KTP already belongs to a third patient (Patient C)', function (): void {
    $a = pmPatient(['ktp_number' => null]);
    $b = pmPatient(['ktp_number' => null]);
    pmPatient(['ktp_number' => '7371000000000099']); // Patient C

    $service = app(PatientMergeCaseService::class);
    $case = pmReadyCase($a, $b, $this->requester, $b, [
        'ktp_number' => ['source' => PatientMergeField::SOURCE_MANUAL, 'manual_value' => '7371000000000099', 'reason' => 'Nomor dari fotokopi KTP pasien.'],
    ]);

    $codes = pmBlockerCodes(fn () => $service->submit($case, $this->requester));
    expect($codes)->toContain('KTP_OWNED_BY_OTHER_PATIENT');
    expect(Patient::query()->where('ktp_number', '7371000000000099')->count())->toBe(1);
});

it('re-checks Patient C inside the merge transaction (KTP claimed after submission)', function (): void {
    $a = pmPatient(['ktp_number' => null]);
    $b = pmPatient(['ktp_number' => null]);
    $case = pmSubmittedCase($a, $b, $this->requester, $b, [
        'ktp_number' => ['source' => PatientMergeField::SOURCE_MANUAL, 'manual_value' => '7371000000000077', 'reason' => 'Nomor dari fotokopi KTP pasien.'],
    ]);

    $c = pmPatient(['ktp_number' => '7371000000000077']); // registered concurrently

    $codes = pmBlockerCodes(fn () => pmMerge($case, $this->approver));

    expect($codes)->toContain('KTP_OWNED_BY_OTHER_PATIENT')
        ->and($c->fresh()->ktp_number)->toBe('7371000000000077')
        ->and($a->fresh()->isMerged())->toBeFalse()
        ->and($case->fresh()->status)->toBe(PatientMergeStatus::PENDING_REVIEW);
});

it('blocks when both patients hold an active legacy archive of the same type', function (): void {
    $a = pmPatient();
    $b = pmPatient();
    LegacyRmeRecord::factory()->create(['patient_id' => $a->id]);
    LegacyRmeRecord::factory()->create(['patient_id' => $b->id]);

    expect(pmBlockerCodes(fn () => pmSubmittedCase($a, $b, $this->requester)))->toContain('LEGACY_SLOT_CONFLICT');
});

it('allows one active archive per type even when each patient holds a different type', function (): void {
    $a = pmPatient();
    $b = pmPatient();
    LegacyRmeRecord::factory()->create(['patient_id' => $a->id]);
    LegacyOdontogramRecord::factory()->create(['patient_id' => $b->id]);

    $merged = pmMerge(pmSubmittedCase($a, $b, $this->requester, $b), $this->approver);

    expect($merged->status)->toBe(PatientMergeStatus::COMPLETED);
});

it('blocks while a legacy import is still in flight', function (): void {
    $a = pmPatient();
    $b = pmPatient();
    LegacyRmeImport::factory()->create(['patient_id' => $a->id, 'status' => LegacyRmeImportStatus::READY_FOR_REVIEW]);

    expect(pmBlockerCodes(fn () => pmSubmittedCase($a, $b, $this->requester)))->toContain('LEGACY_IMPORT_IN_FLIGHT');
});

it('refuses when a patient was edited after submission (identity fingerprint)', function (): void {
    $a = pmPatient();
    $b = pmPatient();
    $case = pmSubmittedCase($a, $b, $this->requester, $b);

    $a->forceFill(['address' => 'Alamat baru setelah pengajuan'])->save();

    expect(pmBlockerCodes(fn () => pmMerge($case, $this->approver)))->toContain('IDENTITY_CHANGED_SINCE_SUBMISSION');
});

it('rejects selecting the same patient twice, a merged patient, or a patient already in an open case', function (): void {
    $service = app(PatientMergeCaseService::class);
    $a = pmPatient();
    $b = pmPatient();
    $c = pmPatient();

    expect(fn () => $service->create($this->requester, $a->id, $a->id, 'Alasan yang cukup panjang.'))->toThrow(ValidationException::class);

    pmReadyCase($a, $b, $this->requester);
    expect(fn () => $service->create($this->requester, $a->id, $c->id, 'Alasan yang cukup panjang.'))->toThrow(ValidationException::class);

    $d = pmPatient();
    $e = pmPatient();
    pmMerge(pmSubmittedCase($d, $e, $this->requester, $e), $this->approver);
    expect(fn () => $service->create($this->requester, $d->id, $c->id, 'Alasan yang cukup panjang.'))->toThrow(ValidationException::class);
});

it('rejects a second merge of the same case (double approve)', function (): void {
    $case = pmSubmittedCase(pmPatient(), pmPatient(), $this->requester);
    pmMerge($case, $this->approver);

    $other = pmUser('Supervisor RME');
    expect(fn () => pmMerge($case, $other))->toThrow(ValidationException::class);
});

it('rolls back EVERYTHING when a step fails mid-merge (zero partial mutations)', function (): void {
    $a = pmPatient(['ktp_number' => '7371000000000055']);
    $b = pmPatient();
    ClinicVisit::factory()->count(2)->create(['patient_id' => $a->id, 'branch_id' => $a->branch_id, 'status' => ClinicVisit::STATUS_COMPLETED]);
    $case = pmSubmittedCase($a, $b, $this->requester, $b, ['ktp_number' => ['source' => PatientMergeField::SOURCE_A]]);

    // Fails AFTER visits were moved, the KTP released and identity written.
    $failing = new class extends PatientMergeOwnershipRepository
    {
        public function reassign(string $table, string $column, array $ids, int $toPatientId): int
        {
            $moved = parent::reassign($table, $column, $ids, $toPatientId);

            if ($table === 'trx_clinic_visits') {
                throw new RuntimeException('Simulated failure in the middle of the merge.');
            }

            return $moved;
        }
    };
    app()->instance(PatientMergeOwnershipRepositoryInterface::class, $failing);

    expect(fn () => pmMerge($case, $this->approver))->toThrow(RuntimeException::class);

    expect(ClinicVisit::query()->where('patient_id', $a->id)->count())->toBe(2)
        ->and(ClinicVisit::query()->where('patient_id', $b->id)->count())->toBe(0)
        ->and($a->fresh()->ktp_number)->toBe('7371000000000055')
        ->and($a->fresh()->isMerged())->toBeFalse()
        ->and($b->fresh()->ktp_number)->toBeNull()
        ->and(PatientRmAlias::query()->count())->toBe(0)
        ->and($case->fresh()->status)->toBe(PatientMergeStatus::PENDING_REVIEW);
});

it('fails the conservation check if a row goes missing, and rolls back', function (): void {
    $a = pmPatient();
    $b = pmPatient();
    ClinicVisit::factory()->create(['patient_id' => $a->id, 'branch_id' => $a->branch_id, 'status' => ClinicVisit::STATUS_COMPLETED]);
    $case = pmSubmittedCase($a, $b, $this->requester, $b);

    // A repository that "loses" a moved visit: reports success but moves nothing.
    $lossy = new class extends PatientMergeOwnershipRepository
    {
        public function reassign(string $table, string $column, array $ids, int $toPatientId): int
        {
            return $table === 'trx_clinic_visits' ? count($ids) : parent::reassign($table, $column, $ids, $toPatientId);
        }
    };
    app()->instance(PatientMergeOwnershipRepositoryInterface::class, $lossy);

    expect(fn () => pmMerge($case, $this->approver))->toThrow(RuntimeException::class, 'conservation');
    expect($a->fresh()->isMerged())->toBeFalse();
});

it('handles a chain merge: an alias keeps resolving after its canonical patient is merged again', function (): void {
    $a = pmPatient();
    $b = pmPatient();
    $c = pmPatient();

    pmMerge(pmSubmittedCase($a, $b, $this->requester, $b), $this->approver);
    pmMerge(pmSubmittedCase($b, $c, $this->requester, $c), $this->approver);

    expect(PatientRmAlias::query()->where('alias_medical_record_number', $a->medical_record_number)->value('canonical_patient_id'))->toBe($c->id)
        ->and(PatientRmAlias::query()->where('alias_medical_record_number', $b->medical_record_number)->value('canonical_patient_id'))->toBe($c->id);
});

it('lists exactly the registry tables as reassigned and keeps provenance columns', function (): void {
    expect(PatientOwnedRecordRegistry::REASSIGN)->toHaveKeys(['trx_clinic_visits', 'trx_medical_records', 'trx_rme_invoices', 'trx_rme_payments', 'trx_rme_legacy_records', 'trx_odontogram_legacy_records', 'mst_patient_documents'])
        ->and(PatientOwnedRecordRegistry::PRESERVE)->toHaveKey('stg_legacy_patient_imports.committed_patient_id');
});

it('refuses a second alias for the same Nomor RM at the database (alias race)', function (): void {
    $a = pmPatient();
    $b = pmPatient();
    $case = pmMerge(pmSubmittedCase($a, $b, $this->requester, $b), $this->approver);

    expect(fn () => DB::transaction(fn () => PatientRmAlias::query()->create([
        'alias_medical_record_number' => $a->medical_record_number,
        'source_patient_id' => $a->id,
        'canonical_patient_id' => $b->id,
        'merge_case_id' => $case->id,
        'merged_at' => now(),
    ])))->toThrow(QueryException::class);
});
