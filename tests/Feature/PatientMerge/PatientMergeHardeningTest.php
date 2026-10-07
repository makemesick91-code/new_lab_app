<?php

use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\LegacyRme\Models\LegacyRmeRecord;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\Patient\Models\LegacyPatientImportBatch;
use App\Modules\Patient\Models\LegacyPatientImportRow;
use App\Modules\Patient\Services\LegacyPatientImportService;
use App\Modules\PatientMerge\Exceptions\PatientMergeBlockedException;
use App\Modules\PatientMerge\Interfaces\PatientMergeOwnershipRepositoryInterface;
use App\Modules\PatientMerge\Repositories\PatientMergeOwnershipRepository;
use App\Modules\PatientMerge\Services\PatientDuplicateDetectionService;
use App\Modules\PatientMerge\Services\PatientMergeCaseService;
use App\Modules\PatientMerge\Services\PatientMergeReversalService;
use App\Modules\PatientMerge\Support\PatientMergeField;
use App\Modules\RME\Models\PatientDoctorAssignment;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmePayment;
use App\Modules\RmeOnlineContext\Middleware\EnsureRmeOnlineContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/*
 * FEATURE-PATIENT-DUPLICATE-RESOLUTION-MERGE-1 — regressions for the findings
 * of the security and data-integrity reviews: maker-checker covers everyone
 * who shaped a case, values are re-derived at submission, live encounters /
 * SATUSEHAT identifiers / the legacy date rule block a merge, a reversal never
 * overwrites later work, and the surrounding flows respect the merge.
 */

require_once __DIR__.'/helpers.php';

beforeEach(function (): void {
    seedAccessControl();
    $this->requester = pmUser('Supervisor RME');
    $this->approver = pmUser('Supervisor RME');
});

it('refuses an approver who edited a field of the case (maker-checker covers every author)', function (): void {
    $a = pmPatient(['name' => 'Budi Santoso', 'phone' => '081200000001']);
    $b = pmPatient(['name' => 'Budi Santosa', 'phone' => '081200000002']);
    $superAdmin = pmUser('Super Admin');
    $service = app(PatientMergeCaseService::class);

    // Super Admin bypasses every policy, so the service must refuse it.
    $case = pmReadyCase($a, $b, $this->requester, $b);
    expect(fn () => $service->resolve($case, $superAdmin, ['phone' => ['source' => PatientMergeField::SOURCE_A]], $b->id))
        ->toThrow(ValidationException::class);
    expect(fn () => $service->submit($case, $superAdmin))->toThrow(ValidationException::class);

    // An author recorded on a field resolution can never approve.
    DB::table('trx_patient_merge_field_resolutions')->where('merge_case_id', $case->id)->where('field', 'phone')->update(['resolved_by' => $superAdmin->id]);
    $case = $service->submit($case->fresh(), $this->requester);

    expect(fn () => pmMerge($case, $superAdmin))->toThrow(ValidationException::class)
        ->and(fn () => $service->reject($case->fresh(), $superAdmin, 'Bukan pasien yang sama, cek KTP.'))->toThrow(ValidationException::class)
        ->and($a->fresh()->isMerged())->toBeFalse();

    pmMerge($case->fresh(), $this->approver);
    expect($case->fresh()->submitted_by)->toBe($this->requester->id);
});

it('forbids a reviewer from editing or submitting someone else\'s draft over HTTP', function (): void {
    $a = pmPatient();
    $b = pmPatient();
    $case = pmReadyCase($a, $b, $this->requester, $b);
    $this->withoutMiddleware(EnsureRmeOnlineContext::class);

    $this->actingAs($this->approver)
        ->put(route('patient-merge.cases.resolve', $case), ['canonical_patient_id' => $a->id])
        ->assertForbidden();
    $this->actingAs($this->approver)
        ->post(route('patient-merge.cases.submit', $case))
        ->assertForbidden();
});

it('re-derives A/B values at submission so a reviewer never approves a stale value', function (): void {
    $a = pmPatient(['name' => 'Siti Aminah', 'address' => 'Jl. Lama 1']);
    $b = pmPatient(['name' => 'Siti Aminah', 'address' => 'Jl. Baru 2']);
    $case = pmReadyCase($a, $b, $this->requester, $b, ['address' => ['source' => PatientMergeField::SOURCE_A]]);

    $a->forceFill(['address' => 'Jl. Dikoreksi 3'])->save();
    $case = app(PatientMergeCaseService::class)->submit($case, $this->requester);

    $address = $case->fieldResolutions()->where('field', 'address')->first();
    expect($address->final_value_encrypted)->toBe('Jl. Dikoreksi 3');

    pmMerge($case, $this->approver);
    expect($b->fresh()->address)->toBe('Jl. Dikoreksi 3');
});

it('blocks a merge while either patient has a live encounter', function (): void {
    $a = pmPatient();
    $b = pmPatient();
    ClinicVisit::factory()->create(['patient_id' => $a->id, 'branch_id' => $a->branch_id, 'status' => ClinicVisit::STATUS_IN_PROGRESS]);

    $blocked = null;

    try {
        pmSubmittedCase($a, $b, $this->requester, $b);
    } catch (PatientMergeBlockedException $exception) {
        $blocked = $exception;
    }

    expect($blocked)->not->toBeNull()
        ->and(array_column($blocked->blockers, 'code'))->toContain('ACTIVE_ENCOUNTER');
});

it('blocks a merge whose source holds an active SATUSEHAT identifier', function (): void {
    $a = pmPatient();
    $b = pmPatient();
    DB::table('mst_satusehat_entity_identifiers')->insert([
        'environment' => 'sandbox', 'entity_type' => 'Patient', 'local_entity_type' => 'patient',
        'local_entity_id' => $a->id, 'remote_identifier' => 'P000000001', 'status' => 'active',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    try {
        pmSubmittedCase($a, $b, $this->requester, $b);
        $codes = [];
    } catch (PatientMergeBlockedException $exception) {
        $codes = array_column($exception->blockers, 'code');
    }

    expect($codes)->toContain('SATUSEHAT_IDENTIFIER_PRESENT');

    // Canonical holding the only identifier is fine: nothing needs re-pointing.
    $c = pmPatient();
    $d = pmPatient();
    DB::table('mst_satusehat_entity_identifiers')->insert([
        'environment' => 'sandbox', 'entity_type' => 'Patient', 'local_entity_type' => 'patient',
        'local_entity_id' => $d->id, 'remote_identifier' => 'P000000002', 'status' => 'active',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $case = pmSubmittedCase($c, $d, $this->requester, $d);
    expect($case->status)->toBe('pending_review');
});

it('blocks a merge that would put a legacy archive after a native record', function (): void {
    $a = pmPatient();
    $b = pmPatient();
    LegacyRmeRecord::factory()->create(['patient_id' => $a->id, 'origin_branch_id' => $a->branch_id, 'rme_date' => '2025-06-01']);
    $visit = ClinicVisit::factory()->create(['patient_id' => $b->id, 'branch_id' => $b->branch_id, 'status' => ClinicVisit::STATUS_COMPLETED, 'visit_date' => '2024-01-10']);
    MedicalRecord::factory()->create(['clinic_visit_id' => $visit->id, 'patient_id' => $b->id, 'branch_id' => $b->branch_id, 'doctor_id' => $visit->doctor_id, 'status' => MedicalRecord::STATUS_FINAL]);

    try {
        pmSubmittedCase($a, $b, $this->requester, $b);
        $codes = [];
    } catch (PatientMergeBlockedException $exception) {
        $codes = array_column($exception->blockers, 'code');
    }

    expect($codes)->toContain('LEGACY_DATE_RULE_VIOLATION');
});

it('keeps and restores the original doctor assignment notes', function (): void {
    $a = pmPatient();
    $b = pmPatient();
    $doctor = Doctor::factory()->create();
    $sourceAssignment = PatientDoctorAssignment::factory()->create(['patient_id' => $a->id, 'doctor_id' => $doctor->id, 'notes' => 'Catatan asli dokter']);
    PatientDoctorAssignment::factory()->create(['patient_id' => $b->id, 'doctor_id' => $doctor->id]);

    $case = pmMerge(pmSubmittedCase($a, $b, $this->requester, $b), $this->approver);
    $closed = $sourceAssignment->fresh();

    expect($closed->unassigned_at)->not->toBeNull()
        ->and($closed->notes)->toStartWith('Catatan asli dokter')
        ->and($closed->notes)->toContain($case->case_number);

    $reversals = app(PatientMergeReversalService::class);
    $case = $reversals->request($case, $this->approver, 'Ternyata dua orang berbeda.');
    expect($case->reversal_assessment['mode'])->toBe(PatientMergeReversalService::MODE_SAFE);

    $reversals->execute($case, $this->requester);
    $restored = $sourceAssignment->fresh();
    expect($restored->unassigned_at)->toBeNull()
        ->and($restored->notes)->toBe('Catatan asli dokter')
        ->and($restored->patient_id)->toBe($a->id);
});

it('requires supervision when the canonical identity was corrected after the merge', function (): void {
    $a = pmPatient(['name' => 'Rina']);
    $b = pmPatient(['name' => 'Rina']);
    $case = pmMerge(pmSubmittedCase($a, $b, $this->requester, $b), $this->approver);

    $b->fresh()->forceFill(['phone' => '081299990000'])->save();

    $case = app(PatientMergeReversalService::class)->request($case, $this->approver, 'Dugaan salah gabung, perlu dicek ulang.');
    expect($case->reversal_assessment['mode'])->toBe(PatientMergeReversalService::MODE_SUPERVISED)
        ->and(implode(' ', $case->reversal_assessment['reasons']))->toContain('Identitas pasien canonical');
});

it('requires supervision when a moved record changed after the merge', function (): void {
    $a = pmPatient();
    $b = pmPatient();
    $visit = ClinicVisit::factory()->create(['patient_id' => $a->id, 'branch_id' => $a->branch_id, 'status' => ClinicVisit::STATUS_COMPLETED]);
    $record = MedicalRecord::factory()->create(['clinic_visit_id' => $visit->id, 'patient_id' => $a->id, 'branch_id' => $a->branch_id, 'doctor_id' => $visit->doctor_id, 'status' => MedicalRecord::STATUS_FINAL]);
    $case = pmMerge(pmSubmittedCase($a, $b, $this->requester, $b), $this->approver);

    DB::table('trx_medical_records')->where('id', $record->id)->update(['updated_at' => now()->addMinute()]);

    $case = app(PatientMergeReversalService::class)->request($case, $this->approver, 'Dugaan salah gabung, perlu dicek ulang.');
    expect($case->reversal_assessment['mode'])->toBe(PatientMergeReversalService::MODE_SUPERVISED)
        ->and($case->reversal_assessment['activity'])->toHaveKey('trx_medical_records');
});

it('requires supervision when a patient was deleted after the merge', function (): void {
    $a = pmPatient();
    $b = pmPatient();
    $case = pmMerge(pmSubmittedCase($a, $b, $this->requester, $b), $this->approver);
    $a->fresh()->delete();

    expect(app(PatientMergeReversalService::class)->assess($case->fresh())['mode'])->toBe(PatientMergeReversalService::MODE_SUPERVISED);
});

it('refuses a legacy patient batch rollback that would delete a merged patient', function (): void {
    $batch = LegacyPatientImportBatch::query()->create([
        'uuid' => (string) Str::uuid(), 'original_filename' => 'batch.csv',
        'status' => LegacyPatientImportBatch::STATUS_COMMITTED, 'total_rows' => 1, 'committed_rows' => 1,
    ]);
    $a = pmPatient(['import_batch_id' => $batch->id]);
    $b = pmPatient();
    LegacyPatientImportRow::query()->create([
        'batch_id' => $batch->id, 'row_number' => 1, 'raw_payload' => [],
        'status' => LegacyPatientImportRow::STATUS_COMMITTED, 'committed_patient_id' => $a->id,
    ]);
    pmMerge(pmSubmittedCase($a, $b, $this->requester, $b), $this->approver);

    expect(fn () => app(LegacyPatientImportService::class)->rollback($batch->fresh(), $this->approver->id))
        ->toThrow(RuntimeException::class, 'penggabungan');
    expect($a->fresh()->trashed())->toBeFalse();
});

it('audits a merge that fails for a reason other than a blocker', function (): void {
    $a = pmPatient();
    $b = pmPatient();
    ClinicVisit::factory()->create(['patient_id' => $a->id, 'branch_id' => $a->branch_id, 'status' => ClinicVisit::STATUS_COMPLETED]);
    $case = pmSubmittedCase($a, $b, $this->requester, $b);

    app()->instance(PatientMergeOwnershipRepositoryInterface::class, new class extends PatientMergeOwnershipRepository
    {
        public function reassign(string $table, string $column, array $ids, int $toPatientId): int
        {
            return $table === 'trx_clinic_visits' ? count($ids) : parent::reassign($table, $column, $ids, $toPatientId);
        }
    });

    expect(fn () => pmMerge($case, $this->approver))->toThrow(RuntimeException::class);
    expect(AuditLog::query()->where('action', 'PATIENT_MERGE_FAILED')->where('entity_id', $case->id)->exists())->toBeTrue();
});

it('discloses only the minimum identity for a registration match outside the actor\'s branch', function (): void {
    $home = pmBranch('LDK2', 'Cabang Landak');
    $other = pmBranch('SPN4', 'Cabang Sunu');
    pmPatient(['name' => 'Yusuf Habibi', 'date_of_birth' => '1990-05-05', 'ktp_number' => '7371000000004444', 'phone' => '081311112222'], $other);

    $actor = pmUser('Admin Klinik');
    $actor->forceFill(['branch_id' => $home->id])->save();
    rmeMakeAdminClinicActive($actor, $home);

    $matches = app(PatientDuplicateDetectionService::class)->registrationMatches(['name' => 'Yusuf Habibi', 'date_of_birth' => '1990-05-05'], $actor);

    expect($matches)->toHaveCount(1)
        ->and($matches[0]['in_scope'])->toBeFalse()
        ->and($matches[0])->not->toHaveKeys(['date_of_birth', 'phone_masked', 'ktp_masked'])
        ->and($matches[0])->toHaveKeys(['id', 'name', 'medical_record_number', 'branch_label']);
});

it('answers 404 instead of redirecting when the canonical workspace cannot be opened', function (): void {
    // The canonical patient has no medical record in the actor's workspace, so
    // the redirect would land on a 404 anyway — and must not reveal, first,
    // that the merged patient exists and which patient it now points to.
    $a = pmPatient();
    $b = pmPatient();
    pmMerge(pmSubmittedCase($a, $b, $this->requester, $b), $this->approver);
    $this->withoutMiddleware(EnsureRmeOnlineContext::class);

    $this->actingAs(pmUser('Super Admin'))->get(route('rme.medical-records.patients.show', $a->id))->assertNotFound();
});

it('applies registration limits and refuses control characters in manual values', function (): void {
    $a = pmPatient(['name' => 'Andi']);
    $b = pmPatient(['name' => 'Andy']);
    $service = app(PatientMergeCaseService::class);
    $case = $service->create($this->requester, $a->id, $b->id, 'Pasien terdaftar dua kali saat registrasi.');

    expect(fn () => $service->resolve($case, $this->requester, ['name' => ['source' => 'manual', 'manual_value' => str_repeat('A', 151), 'reason' => 'Sesuai KTP asli pasien.']], $b->id))
        ->toThrow(ValidationException::class)
        ->and(fn () => $service->resolve($case, $this->requester, ['name' => ['source' => 'manual', 'manual_value' => "Andi\nX", 'reason' => 'Sesuai KTP asli pasien.']], $b->id))
        ->toThrow(ValidationException::class);

    $case = $service->resolve($case, $this->requester, ['name' => ['source' => 'manual', 'manual_value' => 'Andi Pratama', 'reason' => 'Sesuai KTP asli pasien.']], $b->id);
    expect($case->fieldResolutions->firstWhere('field', 'name')->final_value_encrypted)->toBe('Andi Pratama');
});

it('requires supervision when a row was attached to a moved parent behind the merge', function (): void {
    $a = pmPatient();
    $b = pmPatient();
    $visit = ClinicVisit::factory()->create(['patient_id' => $a->id, 'branch_id' => $a->branch_id, 'status' => ClinicVisit::STATUS_COMPLETED]);
    $invoice = RmeInvoice::factory()->create(['clinic_visit_id' => $visit->id, 'patient_id' => $a->id, 'branch_id' => $a->branch_id, 'status' => RmeInvoice::STATUS_PARTIAL, 'grand_total' => 200000, 'subtotal' => 200000]);
    $case = pmMerge(pmSubmittedCase($a, $b, $this->requester, $b), $this->approver);

    // A payment that queued behind the merge's lock on the invoice commits with
    // an id BELOW the merge's high-water mark and an old created_at: only the
    // parent-reference probe can see it.
    $payment = RmePayment::factory()->create(['rme_invoice_id' => $invoice->id, 'clinic_visit_id' => $visit->id, 'patient_id' => $b->id, 'branch_id' => $b->branch_id, 'amount' => 50000]);
    DB::table('trx_rme_payments')->where('id', $payment->id)->update(['created_at' => now()->subDay(), 'updated_at' => now()->subDay()]);
    $manifest = $case->moved_records;
    $manifest['high_water']['trx_rme_payments'] = $payment->id + 10;
    DB::table('trx_patient_merge_cases')->where('id', $case->id)->update(['moved_records' => json_encode($manifest)]);

    $assessment = app(PatientMergeReversalService::class)->assess($case->fresh());
    expect($assessment['mode'])->toBe(PatientMergeReversalService::MODE_SUPERVISED)
        ->and($assessment['activity'])->toHaveKey('trx_rme_payments');
});
