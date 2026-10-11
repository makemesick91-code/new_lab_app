<?php

/**
 * GOVERNANCE-PATIENT-KTP-NEW-PATIENT-ONLY-1 — KTP photo capture belongs to NEW
 * patient registration only; patient identity is global; the stored KTP image
 * stays at its capture branch.
 *
 * The owner decisions this suite pins (rule 184):
 *   - KTP capture is offered on exactly the two new-patient registration
 *     surfaces, never on an existing patient's page;
 *   - an existing, repeat-visit or legacy-imported patient is registered for a
 *     visit without any KTP photo, scan or OCR — a missing archive is never a
 *     blocker and never makes a record "incomplete";
 *   - one global patient identity is reused across branches with no new
 *     capture, while the archived image keeps its capture branch through a
 *     cross-branch visit AND through a merge, and is refused to another branch.
 *
 * Every patient, NIK and image here is fictional/synthetic.
 */

use App\Modules\Branch\Models\Branch;
use App\Modules\Clinic\Models\Clinic;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\Patient\Models\LegacyPatientImportBatch;
use App\Modules\Patient\Models\Patient;
use App\Modules\Patient\Models\PatientDocument;
use App\Modules\Patient\Services\PatientDataCompletenessService;
use App\Modules\Patient\Services\PatientDocumentAccessService;
use App\Modules\Treatment\Models\Treatment;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require_once __DIR__.'/../PatientMerge/helpers.php';

const KGOV_NIK = '7371099909900042';

beforeEach(function () {
    seedAccessControl();
    Storage::fake('local');

    $this->clinic = Clinic::factory()->create();
    $this->treatment = Treatment::factory()->create(['is_active' => true]);
    $this->doctor = Doctor::factory()->create(['clinic_id' => $this->clinic->id]);

    $this->branchA = Branch::factory()->create(['code' => 'SPN4', 'name' => 'Cabang Sunu', 'is_active' => true, 'is_rme_enabled' => true]);
    $this->branchB = Branch::factory()->create(['code' => 'LDK2', 'name' => 'Cabang Landak', 'is_active' => true, 'is_rme_enabled' => true]);

    rmeMakeDoctorOnline($this->doctor, $this->branchB);

    // A registration operator who is not bound to a working branch context: the
    // shape used by the existing visit-flow KTP suites (explicit branch_id).
    $this->registrar = userWith(['manage_clinic_visits', 'view_clinic_visits', 'manage patients']);
});

/** A stored KTP archive with a real file on the fake private disk. */
function kgovDocument(Patient $patient, int $capturedBranchId): PatientDocument
{
    $binary = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAoAAAAKCAYAAACNMs+9AAAAFUlEQVR42mNk+M9Qz0AEYBxVSF+FABJADveWkH6oAAAAAElFTkSuQmCC');
    $path = 'patient-documents/'.$patient->id.'/ktp-kgov-'.uniqid().'.png';
    Storage::disk('local')->put($path, $binary);

    return $patient->documents()->create([
        'document_type' => PatientDocument::TYPE_KTP,
        'file_path' => $path,
        'original_filename' => 'ktp.png',
        'mime_type' => 'image/png',
        'file_size' => strlen($binary),
        'compressed_file_size' => strlen($binary),
        'checksum' => hash('sha256', $binary),
        'uploaded_by' => null,
        'captured_branch_id' => $capturedBranchId,
    ]);
}

/** A patient created by the canonical legacy patient import (provenance = import_batch_id). */
function kgovLegacyPatient(Branch $branch, array $attributes = []): Patient
{
    $batch = LegacyPatientImportBatch::query()->create([
        'uuid' => (string) Str::uuid(),
        'original_filename' => 'legacy-patients.csv',
        'status' => LegacyPatientImportBatch::STATUS_COMMITTED,
    ]);

    return Patient::factory()->create(array_merge([
        'branch_id' => $branch->id,
        'import_batch_id' => $batch->id,
    ], $attributes));
}

/** An existing-patient visit registration posted with an explicit branch. */
function kgovExistingVisit($actor, Patient $patient, Branch $branch, array $extra = [])
{
    return test()->actingAs($actor)->post(route('rme.visits.store'), array_merge([
        'patient_mode' => 'existing',
        'branch_id' => $branch->id,
        'clinic_id' => test()->clinic->id,
        'patient_id' => $patient->id,
        'doctor_id' => test()->doctor->id,
        'initial_treatment_id' => test()->treatment->id,
    ], $extra));
}

/** Position of the ONE KTP-capture root in rendered HTML (the bare attribute, not data-ktp-scan-*). */
function kgovCaptureRoots(string $html): array
{
    preg_match_all('/\sdata-ktp-scan[\s>]/', $html, $matches, PREG_OFFSET_CAPTURE);

    return array_map(fn (array $match): int => $match[1], $matches[0]);
}

/** Parse rendered HTML into an XPath over the real element tree. */
function kgovXPath(string $html): DOMXPath
{
    $dom = new DOMDocument;
    $previous = libxml_use_internal_errors(true);
    $dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    return new DOMXPath($dom);
}

/** The value of a PHP array entry whose key starts at $offset, up to its top-level comma. */
function kgovRuleValue(string $source, int $offset): string
{
    $start = strpos($source, '=>', $offset) + 2;
    $depth = 0;

    for ($i = $start, $n = strlen($source); $i < $n; $i++) {
        $char = $source[$i];
        if ($char === '[' || $char === '(') {
            $depth++;
        } elseif ($char === ']' || $char === ')') {
            if ($depth === 0) {
                break;
            }
            $depth--;
        } elseif ($char === ',' && $depth === 0) {
            break;
        }
    }

    return substr($source, $start, $i - $start);
}

/** The body of a class method, by brace depth (null when absent). */
function kgovMethodBody(string $source, string $method): ?string
{
    if (! preg_match('/function\s+'.$method.'\s*\([^)]*\)[^{]*\{/', $source, $match, PREG_OFFSET_CAPTURE)) {
        return null;
    }

    $start = $match[0][1] + strlen($match[0][0]);
    $depth = 1;

    for ($i = $start, $n = strlen($source); $i < $n && $depth > 0; $i++) {
        $depth += match ($source[$i]) {
            '{' => 1,
            '}' => -1,
            default => 0,
        };
    }

    return substr($source, $start, $i - $start - 1);
}

// --- 1. KTP capture lives on the new-patient registration surfaces only ---------

it('offers KTP capture on the Master Data new-patient page', function () {
    $html = $this->actingAs($this->registrar)
        ->get(route('settings.patients.create'))
        ->assertOk()
        ->getContent();

    expect(kgovCaptureRoots($html))->toHaveCount(1)
        ->and($html)->toContain('name="ktp_scan_token"');
});

it('offers KTP capture on the visit page only inside the new-patient panel', function () {
    $html = $this->actingAs($this->registrar)
        ->get(route('rme.visits.create'))
        ->assertOk()
        ->getContent();

    // Decided by the real element tree, not string positions: the ONE capture
    // root must have exactly one mode-panel ancestor and it must be "new". A
    // section moved into the existing panel, or out of both panels (visible to
    // existing-patient visits), fails here.
    $xpath = kgovXPath($html);
    $roots = $xpath->query('//*[@data-ktp-scan]');

    expect($roots->length)->toBe(1);

    $panels = [];
    foreach ($xpath->query('ancestor::*[@data-mode-panel]', $roots->item(0)) as $ancestor) {
        $panels[] = $ancestor->getAttribute('data-mode-panel');
    }

    expect($panels)->toBe(['new']);
});

it('offers no KTP capture section, consent prompt or attestation on an existing patient page', function () {
    $patient = Patient::factory()->create(['branch_id' => $this->branchA->id, 'medical_record_number' => 'DG-SPN4-2026-80001']);

    $html = $this->actingAs($this->registrar)
        ->get(route('settings.patients.edit', $patient))
        ->assertOk()
        ->getContent();

    expect(kgovCaptureRoots($html))->toBe([])
        ->and($html)->not->toContain('name="ktp_scan_token"')
        ->and($html)->not->toContain('data-ktp-consent')
        ->and($html)->not->toContain('data-ktp-ocr-verify-wrap')
        ->and($html)->not->toContain('Scan KTP')
        ->and($html)->not->toContain('Unggah KTP')
        ->and($html)->not->toContain('Foto KTP wajib');
});

it('never renders the OCR attestation as a native required control, even after a failed submit', function () {
    // The box sits in the visit form's "Pasien Baru" panel. Browsers validate
    // display:none controls, so a native `required` here refused an
    // existing-patient visit once OCR had been applied and the operator then
    // switched to "Pasien Terdaftar". old() replays that state after a failed submit.
    $html = $this->actingAs($this->registrar)
        ->withSession(['_old_input' => ['ktp_ocr_applied' => '1', 'patient_mode' => 'existing']])
        ->get(route('rme.visits.create'))
        ->assertOk()
        ->getContent();

    $xpath = kgovXPath($html);
    $box = $xpath->query('//input[@name="ktp_ocr_verified"]');
    $wrap = $xpath->query('//*[@data-ktp-ocr-verify-wrap]');

    // Control: the replayed state is honoured (the attestation is shown) ...
    expect($box->length)->toBe(1)
        ->and($wrap->length)->toBe(1)
        ->and(preg_split('/\s+/', $wrap->item(0)->getAttribute('class')))->not->toContain('hidden')
        // ... and still never becomes a browser-enforced constraint.
        ->and($box->item(0)->hasAttribute('required'))->toBeFalse();
});

// --- 2. Existing, repeat and legacy patients need no KTP photo ------------------

it('registers repeat visits for an existing patient who has no KTP archive', function () {
    $patient = Patient::factory()->create(['branch_id' => $this->branchB->id, 'medical_record_number' => 'DG-LDK2-2026-80002']);

    foreach ([1, 2] as $_) {
        kgovExistingVisit($this->registrar, $patient, $this->branchB)
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionMissing('warning');
    }

    expect(ClinicVisit::where('patient_id', $patient->id)->count())->toBe(2)
        ->and($patient->documents()->count())->toBe(0);
});

it('registers a visit for a legacy imported patient without KTP and leaves the record untouched', function () {
    $patient = kgovLegacyPatient($this->branchB, ['medical_record_number' => 'DG-LDK2-2024-80003', 'ktp_number' => null]);
    $before = $patient->fresh()->only(['medical_record_number', 'ktp_number', 'import_batch_id', 'branch_id', 'name']);

    kgovExistingVisit($this->registrar, $patient, $this->branchB)
        ->assertRedirect()
        ->assertSessionHasNoErrors()
        ->assertSessionMissing('warning');

    expect(ClinicVisit::where('patient_id', $patient->id)->count())->toBe(1)
        ->and($patient->documents()->count())->toBe(0)
        ->and(PatientDocument::query()->count())->toBe(0)
        ->and($patient->fresh()->only(['medical_record_number', 'ktp_number', 'import_batch_id', 'branch_id', 'name']))->toBe($before);
});

it('never holds an existing-patient visit on OCR flags left behind by the new-patient panel', function () {
    $patient = Patient::factory()->create(['branch_id' => $this->branchB->id, 'medical_record_number' => 'DG-LDK2-2026-80004']);

    // ktp_ocr_applied without an attestation is a refusal ONLY on a new patient.
    kgovExistingVisit($this->registrar, $patient, $this->branchB, ['ktp_ocr_applied' => '1'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(ClinicVisit::where('patient_id', $patient->id)->count())->toBe(1);
});

it('declares no server-side rule that requires any KTP field for a registration or visit', function () {
    // The two capture ENDPOINTS validate the photo the operator chose to send;
    // they are reached only when a capture actually happens, never as a
    // precondition of registering a patient or a visit. Pinned below so the
    // exclusion list cannot grow silently.
    $captureEndpoints = ['StoreKtpScanRequest.php', 'ParseKtpOcrRequest.php'];

    $files = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() === 'php' && str_contains($file->getPathname(), DIRECTORY_SEPARATOR.'Requests'.DIRECTORY_SEPARATOR)) {
            $files[] = $file->getPathname();
        }
    }

    $declared = [];
    $offenders = [];
    $excluded = [];

    foreach ($files as $file) {
        $rules = kgovMethodBody((string) file_get_contents($file), 'rules');
        if ($rules === null) {
            continue;
        }

        preg_match_all('/([\'"])([^\'"]*ktp[^\'"]*)\1\s*=>/i', $rules, $keys, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

        foreach ($keys as $key) {
            if (in_array(basename($file), $captureEndpoints, true)) {
                $excluded[basename($file)] = true;

                continue;
            }

            $field = basename($file).': '.$key[2][0];
            $declared[] = $field;

            // required, required_if/with/unless, Rule::requiredIf(...), pipe
            // strings, or `present` — any of them would make a KTP field a
            // precondition of saving.
            if (preg_match('/required|present/i', kgovRuleValue($rules, $key[0][1]))) {
                $offenders[] = $field;
            }
        }
    }

    // Non-vacuous: today the token, typed NIK and OCR flags are declared on both
    // registration requests and the patient update request.
    expect(count($declared))->toBeGreaterThanOrEqual(8)
        ->and($declared)->toContain('StoreClinicVisitRequest.php: ktp_scan_token')
        ->and($declared)->toContain('StorePatientRequest.php: ktp_scan_token')
        ->and($offenders)->toBe([])
        ->and(array_keys($excluded))->each->toBeIn($captureEndpoints);
});

// --- 3. One global identity across branches; the image stays at its capture branch

it('reuses the global patient at another branch with no new capture and keeps the archive at its capture branch', function () {
    $patient = Patient::factory()->create([
        'name' => 'Pasien Fiktif Lintas Cabang',
        'branch_id' => $this->branchA->id,
        'medical_record_number' => 'DG-SPN4-2026-80005',
        'ktp_number' => KGOV_NIK,
    ]);
    $document = kgovDocument($patient, $this->branchA->id);

    $operatorB = rmeAdminClinicUser($this->branchB);
    $operatorB->givePermissionTo('manage_clinic_visits');
    $patientsBefore = Patient::withTrashed()->count();

    $this->actingAs($operatorB->fresh())
        ->post(route('rme.visits.store'), [
            'patient_id' => $patient->id,
            'initial_treatment_id' => $this->treatment->id,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $visit = ClinicVisit::firstWhere('patient_id', $patient->id);
    $document->refresh();

    // Same identity, no duplicate, visit at the operator's branch.
    expect($visit)->not->toBeNull()
        ->and($visit->branch_id)->toBe($this->branchB->id)
        ->and(Patient::withTrashed()->count())->toBe($patientsBefore)
        ->and(Patient::where('ktp_number', KGOV_NIK)->count())->toBe(1);

    // No recapture, and the original archive is untouched.
    expect(PatientDocument::query()->count())->toBe(1)
        ->and($document->patient_id)->toBe($patient->id)
        ->and($document->captured_branch_id)->toBe($this->branchA->id)
        ->and(Storage::disk('local')->exists($document->file_path))->toBeTrue();

    // Global search visibility is not global image access.
    $this->actingAs($operatorB->fresh())
        ->get(route('settings.patients.documents.show', [$patient, $document]))
        ->assertNotFound();

    $denial = AuditLog::query()
        ->where('entity_type', PatientDocumentAccessService::AUDIT_ENTITY)
        ->where('action', PatientDocumentAccessService::AUDIT_ACCESS_DENIED)
        ->sole();
    expect($denial->new_values['reason'])->toBe(PatientDocumentAccessService::REASON_BRANCH_MISMATCH)
        ->and(json_encode($denial->new_values))->not->toContain(KGOV_NIK);

    // Control: the capture branch still reads it, so the 404 above is the rule.
    $this->actingAs(rmeAdminClinicUser($this->branchA))
        ->get(route('settings.patients.documents.show', [$patient, $document]))
        ->assertOk();
});

it('keeps the capture branch of a KTP archive when a merge re-points it to the canonical patient', function () {
    $source = pmPatient([], $this->branchA);
    $canonical = pmPatient([], $this->branchB);
    $document = kgovDocument($source, $this->branchA->id);
    $pathBefore = $document->file_path;

    pmMerge(pmSubmittedCase($source, $canonical, pmUser('Supervisor RME'), $canonical), pmUser('Supervisor RME'));

    $document->refresh();
    expect($document->patient_id)->toBe($canonical->id)
        ->and($document->captured_branch_id)->toBe($this->branchA->id)
        ->and($document->file_path)->toBe($pathBefore)
        ->and(PatientDocument::query()->count())->toBe(1);
});

// --- 4. A missing KTP archive never makes a record incomplete -------------------

it('evaluates completeness from typed fields only — the KTP archive never changes the verdict', function () {
    $completeness = app(PatientDataCompletenessService::class);

    $native = Patient::factory()->create([
        'branch_id' => $this->branchA->id,
        'medical_record_number' => 'DG-SPN4-2026-80006',
        'ktp_number' => KGOV_NIK,
    ]);
    $legacy = kgovLegacyPatient($this->branchB, ['medical_record_number' => 'DG-LDK2-2024-80007']);

    $nativeWithout = $completeness->evaluate($native->fresh());
    $legacyWithout = $completeness->evaluate($legacy->fresh());

    kgovDocument($native, $this->branchA->id);
    kgovDocument($legacy, $this->branchB->id);

    expect($completeness->evaluate($native->fresh()))->toBe($nativeWithout)
        ->and($completeness->evaluate($legacy->fresh()))->toBe($legacyWithout)
        ->and(array_values(array_intersect(
            array_keys(PatientDataCompletenessService::CRITICAL_FIELDS + PatientDataCompletenessService::OPTIONAL_FIELDS),
            ['ktp_document', 'ktp_photo', 'ktp_scan', 'documents'],
        )))->toBe([]);
});
