<?php

/**
 * STORAGE-PUBLIC-CLINICAL-EVIDENCE-1 — the GO proof.
 *
 * Incident: RME handwriting, prescription/doctor-signature canvases and lab
 * attachments were written to the 'public' disk, which is symlinked into the
 * document root. A synthetic probe confirmed they were retrievable over HTTPS
 * with no session at all.
 *
 * These tests pin each remediation claim so the exposure cannot silently
 * return.
 */

use App\Modules\Branch\Models\Branch;
use App\Modules\Clinic\Models\Clinic;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\LabOrder\Models\Attachment;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Models\MedicalRecordHandwriting;
use App\Modules\MedicalRecord\Models\MedicalRecordHandwritingPage;
use App\Modules\Patient\Models\Patient;
use App\Modules\RME\Middleware\EnsureRmeOnlineContext;
use App\Support\Storage\ClinicalEvidenceStorage;
use Database\Seeders\BranchSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/** A real 1x1 PNG; the codebase rejects uniform/blank canvases elsewhere. */
function clinicalPngBytes(): string
{
    return base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
    );
}

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    Storage::fake('public');
    Storage::fake('clinical_evidence');

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->manager = userWith(['manage_clinic_visits', 'view_clinic_visits']);

    $this->clinic = Clinic::factory()->create();
    $this->patient = Patient::factory()->create();
    $this->doctor = Doctor::factory()->create();

    $this->visit = ClinicVisit::factory()->create([
        'branch_id' => $this->branch->id,
        'clinic_id' => $this->clinic->id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
        'created_by' => $this->manager->id,
        'queue_number' => 1,
    ]);

    $this->record = MedicalRecord::factory()->create([
        'clinic_visit_id' => $this->visit->id,
        'branch_id' => $this->branch->id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
    ]);

    $this->path = 'handwritings/'.$this->branch->id.'/'.$this->visit->id.'/handwriting_p1_test.png';

    ClinicalEvidenceStorage::disk()->put($this->path, clinicalPngBytes());

    $this->handwriting = MedicalRecordHandwriting::factory()->create([
        'medical_record_id' => $this->record->id,
        'clinic_visit_id' => $this->visit->id,
        'branch_id' => $this->branch->id,
        'doctor_id' => $this->doctor->id,
        'handwriting_path' => $this->path,
        'created_by' => $this->manager->id,
    ]);
});

/* ---------------------------------------------------------------------------
 | 1. Zero unauthenticated access
 * ------------------------------------------------------------------------ */

it('never serves clinical handwriting to an unauthenticated caller', function () {
    $response = $this->get(route('rme.handwritings.image', ['handwriting' => $this->handwriting->id]));

    // Whatever the framework chooses (redirect to login, or a hard deny), the
    // one unacceptable outcome is 200 with the image bytes.
    expect($response->status())->not->toBe(200);
    expect($response->getContent())->not->toContain(clinicalPngBytes());
});

it('keeps the public disk free of clinical evidence when handwriting is stored', function () {
    expect(Storage::disk('public')->allFiles())->toBe([])
        ->and(ClinicalEvidenceStorage::disk()->exists($this->path))->toBeTrue();
});

/* ---------------------------------------------------------------------------
 | 2. Authorized clinicians retain access
 * ------------------------------------------------------------------------ */

it('serves the handwriting image to an authorised clinical user', function () {
    $this->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->actingAs($this->manager)
        ->get(route('rme.handwritings.image', ['handwriting' => $this->handwriting->id]))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png');
});

it('serves a page 2+ handwriting image to an authorised clinical user', function () {
    $pagePath = 'handwritings/'.$this->branch->id.'/'.$this->visit->id.'/handwriting_p2_test.png';
    ClinicalEvidenceStorage::disk()->put($pagePath, clinicalPngBytes());

    $page = MedicalRecordHandwritingPage::factory()->create([
        'medical_record_id' => $this->record->id,
        'clinic_visit_id' => $this->visit->id,
        'branch_id' => $this->branch->id,
        'doctor_id' => $this->doctor->id,
        'page_number' => 2,
        'handwriting_path' => $pagePath,
        'created_by' => $this->manager->id,
    ]);

    $this->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->actingAs($this->manager)
        ->get(route('rme.handwriting-pages.image', ['handwritingPage' => $page->id]))
        ->assertOk();
});

/* ---------------------------------------------------------------------------
 | 3. Wrong role / wrong branch denied
 * ------------------------------------------------------------------------ */

it('denies a user with no RME permission', function () {
    $outsider = userWith(['view_inventory']);

    $this->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->actingAs($outsider)
        ->get(route('rme.handwritings.image', ['handwriting' => $this->handwriting->id]))
        ->assertForbidden();
});

it('denies access when the record sits outside the RME branch scope', function () {
    // MedicalRecordPolicy::view admits a record only when its branch is among
    // the RME-enabled branches. A record anchored to a non-RME branch is out of
    // scope for every actor, however privileged.
    $outside = Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => false]);
    $this->record->forceFill(['branch_id' => $outside->id])->save();

    $this->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->actingAs($this->manager)
        ->get(route('rme.handwritings.image', ['handwriting' => $this->handwriting->id]))
        ->assertForbidden();
});

it('denies a doctor account that is not linked to a master doctor record', function () {
    // Doctor-role users are additionally patient-scoped by
    // DoctorPatientScopeService. An unlinked doctor account cannot be resolved
    // to a patient scope, so it must be refused rather than defaulted open.
    $unlinkedDoctor = userInRole('Doctor');

    $response = $this->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->actingAs($unlinkedDoctor)
        ->get(route('rme.handwritings.image', ['handwriting' => $this->handwriting->id]));

    // The refusal may surface as a redirect (route permission gate) or a 403
    // (policy). Both are legitimate denials, and pinning one specific code here
    // would make this a test of routing rather than of confidentiality. The
    // security claim is the one asserted: no image bytes reach this caller.
    expect($response->getStatusCode())->not->toBe(200)
        ->and($response->getContent())->not->toContain(clinicalPngBytes());
});

it('grants nothing merely from knowing an identifier', function () {
    // The whole point of the remediation: the id is not a capability.
    $this->get(route('rme.handwritings.image', ['handwriting' => $this->handwriting->id]))
        ->assertStatus(302);
});

/* ---------------------------------------------------------------------------
 | 4. Print/PDF still works without a public URL
 * ------------------------------------------------------------------------ */

it('embeds inline bytes for print instead of a linkable URL', function () {
    $pages = $this->record->fresh()->orderedHandwritingPages(true);

    expect($pages->first()['preview_url'])->toStartWith('data:image/png;base64,');
});

it('uses the authorised route rather than a public URL on screen', function () {
    $pages = $this->record->fresh()->orderedHandwritingPages();

    expect($pages->first()['preview_url'])
        ->toContain('/rme/handwritings/')
        ->not->toContain('/storage/handwritings/');
});

/* ---------------------------------------------------------------------------
 | 5. Governance — the exposure cannot be reintroduced
 * ------------------------------------------------------------------------ */

it('has no application code writing to the publicly served disk', function () {
    $offenders = [];

    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));

    foreach ($rii as $file) {
        if ($file->isDir() || $file->getExtension() !== 'php') {
            continue;
        }

        $contents = (string) file_get_contents($file->getPathname());

        // Every form that binds a write to the public disk. The incident was
        // missed by a grep for disk('public') alone, because storeAs() names the
        // disk in its third argument instead.
        $patterns = [
            "/disk\(\s*'public'\s*\)\s*->\s*(put|putFile|putFileAs|writeStream|move|copy)/",
            "/store(As|PubliclyAs|Publicly)?\([^)]*'public'\s*\)/",
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $contents)) {
                $offenders[] = str_replace(app_path().'/', '', $file->getPathname());
                break;
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('configures the clinical disk as private and not web-served', function () {
    $disk = config('filesystems.disks.'.ClinicalEvidenceStorage::diskName());

    expect($disk)->not->toBeNull()
        ->and($disk['visibility'] ?? null)->toBe('private')
        ->and($disk['serve'] ?? null)->toBeFalse();
});

it('does not symlink the clinical disk into the document root', function () {
    $root = config('filesystems.disks.'.ClinicalEvidenceStorage::diskName().'.root');

    foreach ((array) config('filesystems.links', []) as $target) {
        expect($target)->not->toBe($root);
    }
});

/* ---------------------------------------------------------------------------
 | 6. Migration — no data loss, checksum-verified, purge only after verify
 * ------------------------------------------------------------------------ */

it('copies and checksum-verifies without purging the source', function () {
    $key = 'handwritings/legacy/exposed.png';
    Storage::disk('public')->put($key, clinicalPngBytes());

    $this->artisan('clinical-evidence:migrate-public', ['--apply' => true])
        ->assertExitCode(0);

    expect(ClinicalEvidenceStorage::disk()->get($key))->toBe(clinicalPngBytes())
        // Source deliberately retained: copy and purge are separate phases so
        // nothing is deleted before a byte-identical copy is proven.
        ->and(Storage::disk('public')->exists($key))->toBeTrue();
});

it('purges the source only after the copy verifies', function () {
    $key = 'handwritings/legacy/exposed.png';
    Storage::disk('public')->put($key, clinicalPngBytes());

    $this->artisan('clinical-evidence:migrate-public', ['--purge-source' => true])
        ->assertExitCode(0);

    expect(ClinicalEvidenceStorage::disk()->get($key))->toBe(clinicalPngBytes())
        ->and(Storage::disk('public')->exists($key))->toBeFalse();
});

it('writes a manifest recording checksums for rollback', function () {
    Storage::disk('public')->put('handwritings/legacy/exposed.png', clinicalPngBytes());

    $this->artisan('clinical-evidence:migrate-public', ['--apply' => true])->assertExitCode(0);

    $manifests = glob(storage_path('app/clinical-evidence-migration/manifest-apply-*.json'));

    expect($manifests)->not->toBeEmpty();

    $manifest = json_decode((string) file_get_contents(end($manifests)), true);

    expect($manifest['summary']['decision'])->toBe('OK')
        ->and($manifest['objects'][0]['source_sha256'])->toBe(hash('sha256', clinicalPngBytes()))
        ->and($manifest['objects'][0]['target_sha256'])->toBe($manifest['objects'][0]['source_sha256']);
});

it('verifies every stored database reference resolves on the private disk', function () {
    $this->artisan('clinical-evidence:migrate-public', ['--apply' => true])
        ->assertExitCode(0);

    $manifests = glob(storage_path('app/clinical-evidence-migration/manifest-apply-*.json'));
    $manifest = json_decode((string) file_get_contents(end($manifests)), true);

    expect($manifest['database_reference_check']['unresolved'])->toBe(0)
        ->and($manifest['database_reference_check']['resolved'])->toBeGreaterThan(0);
});

/* ---------------------------------------------------------------------------
 | 7. Attachments — the read side that used to be a bare public asset link
 * ------------------------------------------------------------------------ */

it('never serves a lab attachment to an unauthenticated caller', function () {
    $order = LabOrder::factory()->create();

    $this->actingAs(userWith(['manage_lab_orders']))
        ->post(route('lab-orders.attachments.upload', $order), [
            'category' => 'CASE_PHOTO',
            'file' => UploadedFile::fake()->create('photo.png', 20, 'image/png'),
        ]);

    $attachment = Attachment::firstOrFail();

    // The upload must not have touched the publicly served disk at all.
    expect(Storage::disk('public')->exists($attachment->file_path))->toBeFalse()
        ->and(ClinicalEvidenceStorage::disk()->exists($attachment->file_path))->toBeTrue();

    $this->app['auth']->logout();

    $response = $this->get(route('attachments.download', $attachment));

    expect($response->getStatusCode())->not->toBe(200);
});

it('serves a lab attachment to an authorised user', function () {
    $order = LabOrder::factory()->create();

    $actor = userWith(['manage_lab_orders', 'view_lab_orders']);

    $this->actingAs($actor)
        ->post(route('lab-orders.attachments.upload', $order), [
            'category' => 'CASE_PHOTO',
            'file' => UploadedFile::fake()->create('photo.png', 20, 'image/png'),
        ]);

    $attachment = Attachment::firstOrFail();

    $this->actingAs($actor)
        ->get(route('attachments.download', $attachment))
        ->assertOk();
});

it('refuses an attachment whose owner type has no registered authoriser', function () {
    // Fail closed: an unmapped entity_type must never fall through to a default
    // that serves the bytes.
    $actor = userWith(['manage_lab_orders', 'view_lab_orders']);

    $attachment = Attachment::create([
        'entity_type' => 'some_unmapped_table',
        'entity_id' => 1,
        'category' => 'CASE_PHOTO',
        'file_name' => 'x.png',
        'file_path' => 'lab-orders/x/x.png',
        'mime_type' => 'image/png',
        'file_size' => 10,
        'uploaded_by' => $actor->id,
        'uploaded_at' => now(),
    ]);

    ClinicalEvidenceStorage::disk()->put('lab-orders/x/x.png', clinicalPngBytes());

    $this->actingAs($actor)
        ->get(route('attachments.download', $attachment))
        ->assertNotFound();
});
