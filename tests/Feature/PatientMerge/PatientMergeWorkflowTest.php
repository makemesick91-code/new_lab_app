<?php

use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\LegacyRme\Models\LegacyRmeRecord;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\Odontogram\Models\Odontogram;
use App\Modules\Patient\Models\Patient;
use App\Modules\Patient\Models\PatientDocument;
use App\Modules\PatientMerge\Models\PatientRmAlias;
use App\Modules\PatientMerge\Services\PatientMergeCaseService;
use App\Modules\PatientMerge\Support\PatientMergeField;
use App\Modules\PatientMerge\Support\PatientMergeStatus;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmePayment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/*
 * FEATURE-PATIENT-DUPLICATE-RESOLUTION-MERGE-1 — the happy path and the
 * identity / integrity guarantees of a completed merge.
 */

require_once __DIR__.'/helpers.php';

beforeEach(function (): void {
    seedAccessControl();
    $this->requester = pmUser('Supervisor RME');
    $this->approver = pmUser('Supervisor RME');
});

/** Two visits with RME + odontogram + paid invoice for a patient. */
function pmClinicalHistory(Patient $patient, int $visits = 1): void
{
    for ($i = 0; $i < $visits; $i++) {
        $visit = ClinicVisit::factory()->create(['patient_id' => $patient->id, 'branch_id' => $patient->branch_id, 'status' => ClinicVisit::STATUS_COMPLETED]);
        MedicalRecord::factory()->create(['clinic_visit_id' => $visit->id, 'patient_id' => $patient->id, 'branch_id' => $visit->branch_id, 'doctor_id' => $visit->doctor_id, 'status' => MedicalRecord::STATUS_FINAL]);
        Odontogram::factory()->create(['clinic_visit_id' => $visit->id, 'branch_id' => $visit->branch_id]);
        $invoice = RmeInvoice::factory()->create(['clinic_visit_id' => $visit->id, 'patient_id' => $patient->id, 'branch_id' => $visit->branch_id, 'status' => RmeInvoice::STATUS_PAID, 'grand_total' => 150000, 'subtotal' => 150000]);
        RmePayment::factory()->create(['rme_invoice_id' => $invoice->id, 'clinic_visit_id' => $visit->id, 'patient_id' => $patient->id, 'branch_id' => $visit->branch_id, 'amount' => 150000]);
    }
}

it('reconciles identity field by field: canonical B, name from A, KTP from B, DOB manual', function (): void {
    $a = pmPatient(['name' => 'Nur Aisyah', 'ktp_number' => '7371000000000001', 'date_of_birth' => '1990-01-01', 'gender' => 'Female', 'phone' => '081234500001']);
    $b = pmPatient(['name' => 'Nur Aisya', 'ktp_number' => '7371000000000002', 'date_of_birth' => '1990-01-02', 'gender' => 'Female', 'phone' => '081234500001']);

    $case = pmSubmittedCase($a, $b, $this->requester, $b, [
        'name' => ['source' => PatientMergeField::SOURCE_A],
        'ktp_number' => ['source' => PatientMergeField::SOURCE_B],
        'date_of_birth' => ['source' => PatientMergeField::SOURCE_MANUAL, 'manual_value' => '1990-01-03', 'reason' => 'Sesuai akta kelahiran yang ditunjukkan pasien.'],
    ]);

    $merged = pmMerge($case, $this->approver);

    expect($merged->status)->toBe(PatientMergeStatus::COMPLETED)
        ->and($merged->canonical_patient_id)->toBe($b->id)
        ->and($merged->source_patient_id)->toBe($a->id);

    $b->refresh();
    expect($b->name)->toBe('Nur Aisyah')
        ->and($b->ktp_number)->toBe('7371000000000002')
        ->and($b->date_of_birth->toDateString())->toBe('1990-01-03')
        ->and($b->isMerged())->toBeFalse();

    $sources = $merged->fieldResolutions->pluck('source', 'field');
    expect($sources['name'])->toBe('patient_a')
        ->and($sources['ktp_number'])->toBe('patient_b')
        ->and($sources['date_of_birth'])->toBe('manual')
        ->and($sources['phone'])->toBe('matched');
});

it('moves every clinical and financial record to the canonical patient without changing any of them', function (): void {
    $a = pmPatient();
    $b = pmPatient();
    pmClinicalHistory($a, 2);
    pmClinicalHistory($b, 1);
    PatientDocument::query()->forceCreate(['patient_id' => $a->id, 'document_type' => 'ktp_scan', 'file_path' => 'x/a.png', 'original_filename' => 'a.png', 'mime_type' => 'image/png', 'file_size' => 10]);

    $invoicesBefore = RmeInvoice::query()->orderBy('id')->get(['id', 'invoice_number', 'grand_total', 'status', 'created_at'])->toArray();
    $paymentsBefore = RmePayment::query()->orderBy('id')->get(['id', 'payment_number', 'amount', 'paid_at'])->toArray();
    $recordsBefore = MedicalRecord::query()->orderBy('id')->get(['id', 'clinic_visit_id', 'status'])->toArray();
    $visitBranchesBefore = ClinicVisit::query()->orderBy('id')->pluck('branch_id', 'id')->all();

    pmMerge(pmSubmittedCase($a, $b, $this->requester, $b), $this->approver);

    expect(ClinicVisit::query()->where('patient_id', $b->id)->count())->toBe(3)
        ->and(ClinicVisit::query()->where('patient_id', $a->id)->count())->toBe(0)
        ->and(MedicalRecord::query()->where('patient_id', $b->id)->count())->toBe(3)
        ->and(Odontogram::query()->whereIn('clinic_visit_id', ClinicVisit::query()->where('patient_id', $b->id)->pluck('id'))->count())->toBe(3)
        ->and(RmeInvoice::query()->where('patient_id', $b->id)->count())->toBe(3)
        ->and(RmePayment::query()->where('patient_id', $b->id)->count())->toBe(3)
        ->and(PatientDocument::query()->where('patient_id', $b->id)->count())->toBe(1);

    // Same invoices, same payments, same amounts/statuses/timestamps, same records, same branches.
    expect(RmeInvoice::query()->orderBy('id')->get(['id', 'invoice_number', 'grand_total', 'status', 'created_at'])->toArray())->toBe($invoicesBefore)
        ->and(RmePayment::query()->orderBy('id')->get(['id', 'payment_number', 'amount', 'paid_at'])->toArray())->toBe($paymentsBefore)
        ->and(MedicalRecord::query()->orderBy('id')->get(['id', 'clinic_visit_id', 'status'])->toArray())->toBe($recordsBefore)
        ->and(ClinicVisit::query()->orderBy('id')->pluck('branch_id', 'id')->all())->toBe($visitBranchesBefore);
});

it('keeps the source patient as a read-only pointer and its Nomor RM as a searchable alias', function (): void {
    $a = pmPatient();
    $b = pmPatient();
    $oldRm = $a->medical_record_number;

    $case = pmMerge(pmSubmittedCase($a, $b, $this->requester, $b), $this->approver);

    $source = Patient::withTrashed()->find($a->id);
    expect($source)->not->toBeNull()
        ->and($source->trashed())->toBeFalse()
        ->and($source->merged_into_patient_id)->toBe($b->id)
        ->and($source->merge_case_id)->toBe($case->id)
        ->and($source->is_active)->toBeFalse()
        ->and($source->medical_record_number)->toBe($oldRm);

    $alias = PatientRmAlias::query()->where('alias_medical_record_number', $oldRm)->first();
    expect($alias)->not->toBeNull()
        ->and($alias->canonical_patient_id)->toBe($b->id)
        ->and($alias->source_patient_id)->toBe($a->id)
        ->and($alias->revoked_at)->toBeNull();
});

it('releases the source KTP so the canonical patient can carry it without violating uniqueness', function (): void {
    $a = pmPatient(['ktp_number' => '7371000000000011']);
    $b = pmPatient(['ktp_number' => null]);

    pmMerge(pmSubmittedCase($a, $b, $this->requester, $b, ['ktp_number' => ['source' => PatientMergeField::SOURCE_A]]), $this->approver);

    expect($b->fresh()->ktp_number)->toBe('7371000000000011')
        ->and(Patient::withTrashed()->find($a->id)->ktp_number)->toBeNull();
});

it('reassigns legacy archives and keeps them legacy with their original provenance', function (): void {
    $a = pmPatient();
    $b = pmPatient();
    $record = LegacyRmeRecord::factory()->create(['patient_id' => $a->id]);
    $snapshot = fn (LegacyRmeRecord $r): array => [$r->status, $r->origin_branch_id, $r->rme_date?->toDateString(), $r->source_import_id];
    $before = $snapshot($record);

    pmMerge(pmSubmittedCase($a, $b, $this->requester, $b), $this->approver);

    $record->refresh();
    expect($record->patient_id)->toBe($b->id)
        ->and($snapshot($record))->toBe($before);
});

it('writes an audit trail with requester, approver, field provenance and moved counts — never a full KTP', function (): void {
    $a = pmPatient(['ktp_number' => '7371000000000021']);
    $b = pmPatient();
    pmClinicalHistory($a);

    $case = pmMerge(pmSubmittedCase($a, $b, $this->requester, $b), $this->approver);

    $completed = AuditLog::query()->where('entity_type', PatientMergeCaseService::AUDIT_ENTITY)
        ->where('entity_id', $case->id)->where('action', 'PATIENT_MERGE_COMPLETED')->first();

    expect($completed)->not->toBeNull()
        ->and($completed->new_values['requested_by'])->toBe($this->requester->id)
        ->and($completed->new_values['approved_by'])->toBe($this->approver->id)
        ->and($completed->new_values['moved_counts']['trx_clinic_visits'])->toBe(1)
        ->and($completed->new_values['field_sources'])->toHaveKey('name');

    $allAudit = AuditLog::query()->where('entity_type', PatientMergeCaseService::AUDIT_ENTITY)->get()->toJson();
    $caseJson = json_encode([$case->before_summary, $case->after_summary]);
    expect($allAudit)->not->toContain('7371000000000021')
        ->and($caseJson)->not->toContain('7371000000000021');

    // The full pre-merge identity is kept ONLY encrypted.
    $raw = DB::table('trx_patient_merge_cases')->where('id', $case->id)->value('identity_snapshot_encrypted');
    expect($raw)->not->toContain('7371000000000021')
        ->and($case->fresh()->identity_snapshot_encrypted['source']['ktp_number'])->toBe('7371000000000021');
});

it('records before/after counts that conserve every group', function (): void {
    $a = pmPatient();
    $b = pmPatient();
    pmClinicalHistory($a, 2);
    pmClinicalHistory($b, 1);

    $case = pmMerge(pmSubmittedCase($a, $b, $this->requester, $b), $this->approver);

    $before = $case->before_summary;
    $after = $case->after_summary;
    expect($after['canonical']['counts']['visits'])->toBe($before['canonical']['counts']['visits'] + $before['source']['counts']['visits'])
        ->and($after['canonical']['counts']['odontograms'])->toBe(3)
        ->and($after['source_counts']['visits'])->toBe(0);
});

it('refuses an approver who filed the case, even a Super Admin', function (): void {
    $superAdmin = pmUser('Super Admin');
    $case = pmSubmittedCase(pmPatient(), pmPatient(), $superAdmin);

    expect(fn () => pmMerge($case, $superAdmin))->toThrow(ValidationException::class);
    expect($case->fresh()->status)->toBe(PatientMergeStatus::PENDING_REVIEW);
});

it('supports reject and cancel without touching either patient', function (): void {
    $a = pmPatient();
    $b = pmPatient();
    $service = app(PatientMergeCaseService::class);

    $rejected = $service->reject(pmSubmittedCase($a, $b, $this->requester), $this->approver, 'Bukan orang yang sama setelah dicek KTP.');
    expect($rejected->status)->toBe(PatientMergeStatus::REJECTED);

    $cancelled = $service->cancel(pmReadyCase($a, $b, $this->requester), $this->requester, 'Salah pilih pasien saat membuat pengajuan.');
    expect($cancelled->status)->toBe(PatientMergeStatus::CANCELLED)
        ->and($a->fresh()->isMerged())->toBeFalse()
        ->and($b->fresh()->isMerged())->toBeFalse();
});

it('closes a duplicate active doctor assignment instead of violating the active-assignment index', function (): void {
    $a = pmPatient();
    $b = pmPatient();
    $doctorId = $a->doctor_id;
    $assignment = fn (Patient $p) => DB::table('trx_rme_patient_doctor_assignments')->insertGetId([
        'patient_id' => $p->id, 'doctor_id' => $doctorId, 'assigned_at' => now(), 'assignment_type' => 'auto_visit', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $sourceRow = $assignment($a);
    $canonicalRow = $assignment($b);

    pmMerge(pmSubmittedCase($a, $b, $this->requester, $b), $this->approver);

    $rows = DB::table('trx_rme_patient_doctor_assignments')->whereIn('id', [$sourceRow, $canonicalRow])->get()->keyBy('id');
    expect($rows[$sourceRow]->patient_id)->toBe($b->id)
        ->and($rows[$sourceRow]->unassigned_at)->not->toBeNull()
        ->and($rows[$canonicalRow]->unassigned_at)->toBeNull()
        ->and(DB::table('trx_rme_patient_doctor_assignments')->count())->toBe(2);
});
