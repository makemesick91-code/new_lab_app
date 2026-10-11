<?php

/**
 * SECURITY-PATIENT-KTP-ARCHIVE-BRANCH-ISOLATION-1 — a stored KTP image is
 * served only to an operator whose server-resolved working branch is the
 * branch the image was CAPTURED at, or to an explicitly privileged account.
 *
 * Before this sprint `PatientDocumentController::show()` authorized on
 * `PatientPolicy::view`, which is `manage patients` and nothing else, so every
 * front-office account at every branch could open every patient's identity
 * card by id. Patient master data stays unscoped (an existing product
 * decision); only the KTP ARCHIVE is narrowed here.
 *
 * Provenance is a write-once document column (`captured_branch_id`), never the
 * patient's `branch_id` or Nomor RM — both are editable through the unscoped
 * patient update form, so either would let an operator re-home a patient and
 * then read the card.
 */

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Clinic\Models\Clinic;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\Patient\Models\Patient;
use App\Modules\Patient\Models\PatientDocument;
use App\Modules\Patient\Policies\PatientDocumentPolicy;
use App\Modules\Patient\Services\PatientDocumentAccessService;
use App\Modules\RmeOnlineContext\Middleware\EnsureRmeOnlineContext;
use App\Modules\RmeOnlineContext\Services\BranchChangeApprovalService;
use App\Modules\RmeOnlineContext\Services\UserOnlineContextService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

const KBI_NIK = '7371099909900001';

beforeEach(function () {
    seedAccessControl();
    Storage::fake('local');

    $this->branchA = Branch::factory()->create([
        'code' => 'SPN4', 'name' => 'Cabang Sunu', 'is_active' => true, 'is_rme_enabled' => true,
    ]);
    $this->branchB = Branch::factory()->create([
        'code' => 'LDK2', 'name' => 'Cabang Landak', 'is_active' => true, 'is_rme_enabled' => true,
    ]);

    $this->patient = Patient::factory()->create([
        'name' => 'Pasien Rahasia Isolasi',
        'branch_id' => $this->branchA->id,
        'medical_record_number' => 'DG-SPN4-2026-77001',
        'ktp_number' => KBI_NIK,
    ]);

    $this->document = kbiDocument($this->patient, $this->branchA->id);
});

/** A stored KTP document with a real file on the fake private disk. */
function kbiDocument(Patient $patient, ?int $capturedBranchId): PatientDocument
{
    $binary = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAoAAAAKCAYAAACNMs+9AAAAFUlEQVR42mNk+M9Qz0AEYBxVSF+FABJADveWkH6oAAAAAElFTkSuQmCC');
    $path = 'patient-documents/'.$patient->id.'/ktp-kbi-'.uniqid().'.png';
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

function kbiFrontOffice(Branch $branch): User
{
    $user = User::factory()->create();
    rmeMakeFrontOfficeActive($user, $branch);

    return $user;
}

function kbiShowUrl(Patient $patient, PatientDocument $document): string
{
    return route('settings.patients.documents.show', [$patient, $document]);
}

function kbiAuditRows(): array
{
    return AuditLog::query()
        ->where('entity_type', PatientDocumentAccessService::AUDIT_ENTITY)
        ->orderBy('id')
        ->get()
        ->all();
}

// --- 1. The decision matrix -----------------------------------------------------

it('serves the card to a front office operator working at the capture branch', function () {
    $response = $this->actingAs(kbiFrontOffice($this->branchA))
        ->get(kbiShowUrl($this->patient, $this->document))
        ->assertOk();

    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('Cache-Control'))->toContain('private')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->streamedContent())->not->toBe('');
});

it('serves the card to an admin klinik working at the capture branch', function () {
    $this->actingAs(rmeAdminClinicUser($this->branchA))
        ->get(kbiShowUrl($this->patient, $this->document))
        ->assertOk();
});

it('refuses another branch operator who knows the patient and document ids', function () {
    $this->actingAs(kbiFrontOffice($this->branchB))
        ->get(kbiShowUrl($this->patient, $this->document))
        ->assertNotFound();
});

it('answers a cross-branch request exactly like a missing document', function () {
    $adminB = kbiFrontOffice($this->branchB);

    $denied = $this->actingAs($adminB)->get(kbiShowUrl($this->patient, $this->document));
    $missing = $this->actingAs($adminB)->get(route('settings.patients.documents.show', [$this->patient, 999999]));

    expect($denied->getStatusCode())->toBe(404)
        ->and($missing->getStatusCode())->toBe(404);
});

it('refuses enumeration of document ids across branches', function () {
    $adminB = kbiFrontOffice($this->branchB);
    $others = collect(range(1, 3))->map(fn () => kbiDocument(Patient::factory()->create(['branch_id' => $this->branchA->id]), $this->branchA->id));

    foreach ($others as $document) {
        $this->actingAs($adminB)->get(kbiShowUrl($document->patient, $document))->assertNotFound();
    }
});

it('cannot be reached through a medical record number in place of the patient id', function () {
    $this->actingAs(kbiFrontOffice($this->branchB))
        ->get('/settings/patients/'.$this->patient->medical_record_number.'/documents/'.$this->document->id)
        ->assertNotFound();
});

it('ignores a forged branch_id in the query, the body and a header', function () {
    $adminB = kbiFrontOffice($this->branchB);

    $this->actingAs($adminB)
        ->get(kbiShowUrl($this->patient, $this->document).'?branch_id='.$this->branchA->id)
        ->assertNotFound();

    $this->actingAs($adminB)
        ->withHeaders(['X-Branch-Id' => (string) $this->branchA->id, 'X-Branch-Code' => 'SPN4'])
        ->get(kbiShowUrl($this->patient, $this->document))
        ->assertNotFound();

    $this->actingAs($adminB)
        ->call('GET', kbiShowUrl($this->patient, $this->document), ['branch_id' => $this->branchA->id, 'branch_code' => 'SPN4'])
        ->assertNotFound();
});

it('does not let finding the patient in the global lookup grant the card', function () {
    $adminB = kbiFrontOffice($this->branchB);

    // C: branch B can legitimately find the patient for registration.
    $found = $this->actingAs($adminB)
        ->getJson(route('rme.visits.patient-search', ['q' => 'Rahasia Isolasi']))
        ->assertOk()
        ->json();
    expect(collect($found['results'])->pluck('id')->all())->toContain($this->patient->id);

    // D: that does not open the card captured at branch A.
    $this->actingAs($adminB)->get(kbiShowUrl($this->patient, $this->document))->assertNotFound();
});

it('does not open the card because the patient also visited the requesting branch', function () {
    ClinicVisit::factory()->create(['patient_id' => $this->patient->id, 'branch_id' => $this->branchB->id]);

    $this->actingAs(kbiFrontOffice($this->branchB))
        ->get(kbiShowUrl($this->patient, $this->document))
        ->assertNotFound();
});

it('serves a card for a patient with no native visits to the capture branch', function () {
    expect(ClinicVisit::where('patient_id', $this->patient->id)->exists())->toBeFalse();

    $this->actingAs(kbiFrontOffice($this->branchA))->get(kbiShowUrl($this->patient, $this->document))->assertOk();
    $this->actingAs(kbiFrontOffice($this->branchB))->get(kbiShowUrl($this->patient, $this->document))->assertNotFound();
});

it('lets super admin read across branches through the existing privilege', function () {
    $this->actingAs(superAdmin())->get(kbiShowUrl($this->patient, $this->document))->assertOk();
});

it('does not give supervisor rme the card because of the role name', function () {
    $supervisor = userInRole('Supervisor RME');
    expect($supervisor->can('manage patients'))->toBeTrue();

    $this->actingAs($supervisor)->get(kbiShowUrl($this->patient, $this->document))->assertNotFound();
});

it('lets supervisor rme read across branches only with the explicit permission', function () {
    $supervisor = userInRole('Supervisor RME');
    $supervisor->givePermissionTo(PatientDocumentAccessService::CROSS_BRANCH_PERMISSION);

    $this->actingAs($supervisor)->get(kbiShowUrl($this->patient, $this->document))->assertOk();
});

it('grants the cross branch permission to no role except super admin', function () {
    $permission = PatientDocumentAccessService::CROSS_BRANCH_PERMISSION;

    expect(PermissionSeeder::PERMISSIONS)->toContain($permission);

    foreach (RoleSeeder::ROLE_PERMISSIONS as $role => $permissions) {
        if ($permissions === '*') {
            continue;
        }
        expect($permissions)->not->toContain($permission, "role {$role} must not hold {$permission}");
    }

    expect(userInRole('Supervisor RME')->can($permission))->toBeFalse()
        ->and(userInRole('Owner')->can($permission))->toBeFalse()
        ->and(superAdmin()->can($permission))->toBeTrue();
});

it('gives a doctor no new access to the card', function () {
    $doctor = userInRole('Doctor');

    $this->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->actingAs($doctor)
        ->get(kbiShowUrl($this->patient, $this->document))
        ->assertForbidden();
});

it('gives a kasir no new access to the card, even at the capture branch', function () {
    $kasir = userInRole('Kasir');
    rmeMakeKasirActive($kasir, $this->branchA);

    $this->actingAs($kasir)->get(kbiShowUrl($this->patient, $this->document))->assertForbidden();
});

it('refuses a perawat, whose working branch is self-selected and never committed for the day', function () {
    // A Perawat has no daily branch lock (by design) and may re-select its
    // working branch at will. Treating that selection as authority would let it
    // hop A -> B -> A and read every branch's cards, audited as same_branch.
    $perawat = User::factory()->create();

    foreach ([$this->branchA, $this->branchB, $this->branchA] as $branch) {
        rmeMakePerawatActive($perawat, $branch);
        $this->actingAs($perawat->fresh())->get(kbiShowUrl($this->patient, $this->document))->assertNotFound();
    }

    $reasons = collect(kbiAuditRows())->pluck('new_values.reason')->unique()->values()->all();
    expect($reasons)->toBe([PatientDocumentAccessService::REASON_UNCOMMITTED_WORKING_BRANCH]);
});

it('refuses a manage-patients holder with no working branch at all', function () {
    $this->actingAs(userWith(['manage patients']))
        ->get(kbiShowUrl($this->patient, $this->document))
        ->assertNotFound();
});

it('refuses a context-bound operator whose working context is gone', function () {
    $adminA = kbiFrontOffice($this->branchA);
    $this->actingAs($adminA)->get(kbiShowUrl($this->patient, $this->document))->assertOk();

    app(UserOnlineContextService::class)->markOffline($adminA);

    $this->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->actingAs($adminA)
        ->get(kbiShowUrl($this->patient, $this->document))
        ->assertNotFound();
});

it('re-evaluates on every request after an approved branch switch', function () {
    $adminA = kbiFrontOffice($this->branchA);
    $this->actingAs($adminA)->get(kbiShowUrl($this->patient, $this->document))->assertOk();

    $approvals = app(BranchChangeApprovalService::class);
    $request = $approvals->request($adminA, $this->branchB->id, 'Dipindahkan ke cabang Landak hari ini.');
    $approvals->approve($request->id, superAdmin(), 'Disetujui untuk pengujian.');

    // Same account, same browser session — the old branch's card is gone.
    $this->actingAs($adminA->fresh())->get(kbiShowUrl($this->patient, $this->document))->assertNotFound();
});

it('refuses an unauthenticated request', function () {
    $this->get(kbiShowUrl($this->patient, $this->document))->assertRedirect(route('login'));
});

it('refuses an account without manage patients', function () {
    $this->actingAs(User::factory()->create())
        ->get(kbiShowUrl($this->patient, $this->document))
        ->assertForbidden();
});

it('still refuses a document of a different patient behind a matching branch', function () {
    $other = Patient::factory()->create(['branch_id' => $this->branchA->id]);

    $this->actingAs(kbiFrontOffice($this->branchA))
        ->get(route('settings.patients.documents.show', [$other, $this->document]))
        ->assertNotFound();
});

// --- 2. Unknown provenance fails closed -------------------------------------------

it('fails closed for a document with no capture provenance', function () {
    $legacy = kbiDocument($this->patient, null);

    $this->actingAs(kbiFrontOffice($this->branchA))->get(kbiShowUrl($this->patient, $legacy))->assertNotFound();
    $this->actingAs(kbiFrontOffice($this->branchB))->get(kbiShowUrl($this->patient, $legacy))->assertNotFound();
    $this->actingAs(superAdmin())->get(kbiShowUrl($this->patient, $legacy))->assertOk();
});

it('does not treat a legacy imported patient as belonging to every branch', function () {
    $legacyPatient = Patient::factory()->create([
        'branch_id' => $this->branchA->id,
        'medical_record_number' => 'DG-SPN4-2024-31001',
    ]);
    $legacyDocument = kbiDocument($legacyPatient, null);

    $this->actingAs(kbiFrontOffice($this->branchA))->get(kbiShowUrl($legacyPatient, $legacyDocument))->assertNotFound();
    $this->actingAs(kbiFrontOffice($this->branchB))->get(kbiShowUrl($legacyPatient, $legacyDocument))->assertNotFound();
});

it('keeps provenance on the document when the patient is re-homed through the master data form', function () {
    // The unscoped patient update form can move a patient's branch and RM.
    $this->patient->forceFill([
        'branch_id' => $this->branchB->id,
        'medical_record_number' => 'DG-LDK2-2026-77001',
    ])->save();

    $this->actingAs(kbiFrontOffice($this->branchB))->get(kbiShowUrl($this->patient->fresh(), $this->document))->assertNotFound();
    $this->actingAs(kbiFrontOffice($this->branchA))->get(kbiShowUrl($this->patient->fresh(), $this->document))->assertOk();
});

it('refuses to rewrite a recorded capture branch', function () {
    $this->document->captured_branch_id = $this->branchB->id;

    expect(fn () => $this->document->save())->toThrow(LogicException::class);
    expect($this->document->fresh()->captured_branch_id)->toBe($this->branchA->id);
});

it('allows a capture branch to be recorded where none existed', function () {
    $legacy = kbiDocument($this->patient, null);
    $legacy->captured_branch_id = $this->branchA->id;
    $legacy->save();

    expect($legacy->fresh()->captured_branch_id)->toBe($this->branchA->id);
});

it('records unknown provenance as its own refusal reason, not as a branch mismatch', function () {
    $legacy = kbiDocument($this->patient, null);

    $this->actingAs(kbiFrontOffice($this->branchA))->get(kbiShowUrl($this->patient, $legacy))->assertNotFound();

    $row = collect(kbiAuditRows())->sole();
    expect($row->new_values['reason'])->toBe(PatientDocumentAccessService::REASON_UNKNOWN_PROVENANCE)
        ->and($row->new_values['captured_branch_id'])->toBeNull();
});

it('refuses through the service and the policy an account that lacks manage patients, even at the capture branch', function () {
    // The route middleware already refuses this account over HTTP; the service
    // must not depend on that, because the policy also answers `@can`.
    $kasir = userInRole('Kasir');
    rmeMakeKasirActive($kasir, $this->branchA);
    expect($kasir->can('manage patients'))->toBeFalse();

    $decision = app(PatientDocumentAccessService::class)->decide($kasir, $this->document);

    expect($decision->allowed)->toBeFalse()
        ->and($decision->reason)->toBe(PatientDocumentAccessService::REASON_NO_PERMISSION)
        ->and($kasir->can('view', $this->document))->toBeFalse()
        ->and($kasir->can('delete', $this->document))->toBeFalse();
});

it('ignores a cross-branch grant that reaches an account only through a role', function () {
    // The role editor can tick any permission for a whole role. The owner rule
    // is "explicit, per account", so a role-level grant is not honoured.
    $supervisor = userInRole('Supervisor RME');
    Role::findByName('Supervisor RME')->givePermissionTo(PatientDocumentAccessService::CROSS_BRANCH_PERMISSION);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect($supervisor->fresh()->can(PatientDocumentAccessService::CROSS_BRANCH_PERMISSION))->toBeTrue();
    $this->actingAs($supervisor->fresh())->get(kbiShowUrl($this->patient, $this->document))->assertNotFound();

    $status = kbiGrant(['action' => 'status', '--user' => $supervisor->id]);
    expect($status['holds_cross_branch_ktp_access'])->toBeFalse()
        ->and($status['role_level_grant_ignored'])->toBeTrue();
});

it('does not record a view for a card whose file is missing', function () {
    Storage::disk('local')->delete($this->document->file_path);

    $this->actingAs(kbiFrontOffice($this->branchA))->get(kbiShowUrl($this->patient, $this->document))->assertNotFound();

    expect(collect(kbiAuditRows())->pluck('action')->all())->not->toContain(PatientDocumentAccessService::AUDIT_VIEW_GRANTED);
});

// --- 3. Delete follows the same rule ----------------------------------------------

it('refuses deletion of another branch card and leaves it intact', function () {
    $this->actingAs(kbiFrontOffice($this->branchB))
        ->delete(route('settings.patients.documents.destroy', [$this->patient, $this->document]))
        ->assertNotFound();

    expect(PatientDocument::find($this->document->id))->not->toBeNull()
        ->and(Storage::disk('local')->exists($this->document->file_path))->toBeTrue();
});

it('lets the capture branch delete its own card', function () {
    $this->actingAs(kbiFrontOffice($this->branchA))
        ->delete(route('settings.patients.documents.destroy', [$this->patient, $this->document]))
        ->assertRedirect(route('settings.patients.edit', $this->patient));

    expect(PatientDocument::find($this->document->id))->toBeNull();
});

// --- 4. The patient page reveals nothing it cannot serve --------------------------

it('hides the stored card panel from another branch on the patient edit page', function () {
    $this->actingAs(kbiFrontOffice($this->branchB))
        ->get(route('settings.patients.edit', $this->patient))
        ->assertOk()
        ->assertDontSee('Dokumen KTP Tersimpan')
        ->assertDontSee(kbiShowUrl($this->patient, $this->document), false);
});

it('shows the stored card panel to the capture branch', function () {
    $this->actingAs(kbiFrontOffice($this->branchA))
        ->get(route('settings.patients.edit', $this->patient))
        ->assertOk()
        ->assertSee('Dokumen KTP Tersimpan')
        ->assertSee(kbiShowUrl($this->patient, $this->document), false);
});

it('exposes the same decision through the policy and the access service', function () {
    $policy = app(PatientDocumentPolicy::class);
    $adminA = kbiFrontOffice($this->branchA);
    $adminB = kbiFrontOffice($this->branchB);

    expect($policy->view($adminA, $this->document)->allowed())->toBeTrue()
        ->and($policy->view($adminB, $this->document)->allowed())->toBeFalse()
        ->and($policy->view($adminB, $this->document)->status())->toBe(404)
        ->and($adminB->can('view', $this->document))->toBeFalse();
});

// --- 5. Audit evidence, without identity data -------------------------------------

it('audits a granted view without the nik, the name or the storage path', function () {
    $adminA = kbiFrontOffice($this->branchA);
    $this->actingAs($adminA)->get(kbiShowUrl($this->patient, $this->document))->assertOk();

    $rows = kbiAuditRows();
    expect($rows)->toHaveCount(1);

    $row = $rows[0];
    $payload = json_encode([$row->old_values, $row->new_values, $row->action]);

    expect($row->action)->toBe(PatientDocumentAccessService::AUDIT_VIEW_GRANTED)
        ->and((int) $row->entity_id)->toBe($this->document->id)
        ->and((int) $row->performed_by)->toBe($adminA->id)
        ->and($row->new_values['captured_branch_id'])->toBe($this->branchA->id)
        ->and($row->new_values['operator_branch_id'])->toBe($this->branchA->id)
        ->and($payload)->not->toContain(KBI_NIK)
        ->and($payload)->not->toContain('Pasien Rahasia Isolasi')
        ->and($payload)->not->toContain($this->document->file_path)
        ->and($payload)->not->toContain('patient-documents');
});

it('audits a denied cross-branch attempt with a reason code', function () {
    $adminB = kbiFrontOffice($this->branchB);
    $this->actingAs($adminB)->get(kbiShowUrl($this->patient, $this->document))->assertNotFound();

    $row = collect(kbiAuditRows())->sole();
    expect($row->action)->toBe(PatientDocumentAccessService::AUDIT_ACCESS_DENIED)
        ->and($row->new_values['reason'])->toBe(PatientDocumentAccessService::REASON_BRANCH_MISMATCH)
        ->and($row->new_values['operation'])->toBe('view')
        ->and(json_encode($row->new_values))->not->toContain(KBI_NIK);
});

it('audits privileged cross-branch access as privileged', function () {
    $this->actingAs(superAdmin())->get(kbiShowUrl($this->patient, $this->document))->assertOk();

    $row = collect(kbiAuditRows())->sole();
    expect($row->action)->toBe(PatientDocumentAccessService::AUDIT_VIEW_PRIVILEGED)
        ->and($row->new_values['privileged'])->toBeTrue();
});

it('throttles repeated document requests per account', function () {
    $adminB = kbiFrontOffice($this->branchB);
    $limit = PatientDocumentAccessService::REQUESTS_PER_MINUTE;

    for ($i = 0; $i < $limit; $i++) {
        $this->actingAs($adminB)->get(kbiShowUrl($this->patient, $this->document))->assertNotFound();
    }

    $this->actingAs($adminB)->get(kbiShowUrl($this->patient, $this->document))->assertStatus(429);
    expect(kbiAuditRows())->toHaveCount($limit);
});

// --- 6. Provenance is stamped at capture, from the server ---------------------------

it('stamps the operator working branch when master data registration archives a card', function () {
    $adminA = kbiFrontOffice($this->branchA);
    $token = test()->actingAs($adminA)->postJson(route('settings.patients.ktp-scan.upload-temp'), [
        'document_type' => 'ktp',
        'image_base64' => 'iVBORw0KGgoAAAANSUhEUgAAAAoAAAAKCAYAAACNMs+9AAAAFUlEQVR42mNk+M9Qz0AEYBxVSF+FABJADveWkH6oAAAAAElFTkSuQmCC',
        'filename' => 'ktp.png',
    ])->assertOk()->json('token');

    // The form names branch B for the patient; the card was captured at A.
    $this->actingAs($adminA)->post(route('settings.patients.store'), [
        'clinic_id' => Clinic::factory()->create()->id,
        'doctor_id' => Doctor::factory()->create()->id,
        'name' => 'Pasien Master Data Isolasi',
        'branch_id' => $this->branchB->id,
        'manual_rm_number' => '88123',
        'registered_at' => '2026-10-01',
        'ktp_scan_token' => $token,
        'is_active' => 1,
    ])->assertSessionMissing('warning');

    $document = Patient::where('name', 'Pasien Master Data Isolasi')->sole()->documents()->sole();
    expect($document->captured_branch_id)->toBe($this->branchA->id);
});

it('stamps the patient branch when a governance account registers through master data', function () {
    $admin = superAdmin();
    $token = test()->actingAs($admin)->postJson(route('settings.patients.ktp-scan.upload-temp'), [
        'document_type' => 'ktp',
        'image_base64' => 'iVBORw0KGgoAAAANSUhEUgAAAAoAAAAKCAYAAACNMs+9AAAAFUlEQVR42mNk+M9Qz0AEYBxVSF+FABJADveWkH6oAAAAAElFTkSuQmCC',
        'filename' => 'ktp.png',
    ])->assertOk()->json('token');

    $this->actingAs($admin)->post(route('settings.patients.store'), [
        'clinic_id' => Clinic::factory()->create()->id,
        'doctor_id' => Doctor::factory()->create()->id,
        'name' => 'Pasien Super Admin Isolasi',
        'branch_id' => $this->branchB->id,
        'manual_rm_number' => '88124',
        'registered_at' => '2026-10-01',
        'ktp_scan_token' => $token,
        'is_active' => 1,
    ])->assertSessionMissing('warning');

    $document = Patient::where('name', 'Pasien Super Admin Isolasi')->sole()->documents()->sole();
    expect($document->captured_branch_id)->toBe($this->branchB->id);
});

// --- 7. Backfill: evidence or nothing ----------------------------------------------

it('backfills provenance only when independent evidence agrees, and only on apply', function () {
    $patient = Patient::factory()->create([
        'branch_id' => $this->branchA->id,
        'medical_record_number' => 'DG-SPN4-2026-77002',
    ]);
    ClinicVisit::factory()->create([
        'patient_id' => $patient->id,
        'branch_id' => $this->branchA->id,
        'created_at' => $patient->created_at,
    ]);
    $document = kbiDocument($patient, null);
    $document->forceFill(['created_at' => $patient->created_at->copy()->addMinute()])->saveQuietly();
    $actor = superAdmin();

    Artisan::call('patient-documents:backfill-capture-branch', ['--json' => true]);
    $dry = json_decode(Artisan::output(), true);
    expect($dry['mode'])->toBe('dry-run')
        ->and($dry['resolvable'])->toBe(1)
        ->and($document->fresh()->captured_branch_id)->toBeNull();

    Artisan::call('patient-documents:backfill-capture-branch', ['--apply' => true, '--actor' => $actor->id, '--json' => true]);
    $applied = json_decode(Artisan::output(), true);
    $row = AuditLog::where('action', PatientDocumentAccessService::AUDIT_PROVENANCE_BACKFILLED)->sole();
    expect($applied['applied'])->toBe(1)
        ->and($document->fresh()->captured_branch_id)->toBe($this->branchA->id)
        ->and((int) $row->performed_by)->toBe($actor->id);

    Artisan::call('patient-documents:backfill-capture-branch', ['--apply' => true, '--actor' => $actor->id, '--json' => true]);
    expect(json_decode(Artisan::output(), true)['applied'])->toBe(0);
});

it('leaves provenance unknown when the medical record number disagrees with the patient branch', function () {
    $patient = Patient::factory()->create([
        'branch_id' => $this->branchA->id,
        'medical_record_number' => 'DG-LDK2-2026-77003',
    ]);
    // A registration visit at A, so this reaches the RM check rather than
    // stopping at "no registration visit".
    ClinicVisit::factory()->create(['patient_id' => $patient->id, 'branch_id' => $this->branchA->id, 'created_at' => $patient->created_at]);
    $document = kbiDocument($patient, null);

    Artisan::call('patient-documents:backfill-capture-branch', ['--apply' => true, '--actor' => superAdmin()->id, '--json' => true]);
    $report = json_decode(Artisan::output(), true);

    expect($report['applied'])->toBe(0)
        ->and($report['unresolved_by_reason'])->toHaveKey('medical_record_number_branch_disagrees')
        ->and($document->fresh()->captured_branch_id)->toBeNull();
});

it('leaves provenance unknown for a card archived long after registration', function () {
    $patient = Patient::factory()->create([
        'branch_id' => $this->branchA->id,
        'medical_record_number' => 'DG-SPN4-2026-77004',
    ]);
    // Registered through the visit flow at A, but the card was attached 30
    // days later — the registration cannot vouch for where it was captured.
    ClinicVisit::factory()->create(['patient_id' => $patient->id, 'branch_id' => $this->branchA->id, 'created_at' => $patient->created_at]);
    $document = kbiDocument($patient, null);
    $document->forceFill(['created_at' => $patient->created_at->copy()->addDays(30)])->saveQuietly();

    Artisan::call('patient-documents:backfill-capture-branch', ['--apply' => true, '--actor' => superAdmin()->id, '--json' => true]);
    $report = json_decode(Artisan::output(), true);

    expect($document->fresh()->captured_branch_id)->toBeNull()
        ->and($report['unresolved_by_reason'])->toHaveKey('not_archived_at_registration');
});

it('leaves provenance unknown when the registration visit was at another branch', function () {
    $patient = Patient::factory()->create([
        'branch_id' => $this->branchA->id,
        'medical_record_number' => 'DG-SPN4-2026-77005',
    ]);
    ClinicVisit::factory()->create([
        'patient_id' => $patient->id,
        'branch_id' => $this->branchB->id,
        'created_at' => $patient->created_at,
    ]);
    $document = kbiDocument($patient, null);

    Artisan::call('patient-documents:backfill-capture-branch', ['--apply' => true, '--actor' => superAdmin()->id, '--json' => true]);

    expect($document->fresh()->captured_branch_id)->toBeNull();
});

it('leaves a master data card unknown even when every editable patient field agrees', function () {
    // No registration visit: the branch the card was captured at is not
    // recorded anywhere the unscoped patient form cannot rewrite.
    $patient = Patient::factory()->create([
        'branch_id' => $this->branchA->id,
        'medical_record_number' => 'DG-SPN4-2026-77007',
    ]);
    $document = kbiDocument($patient, null);

    Artisan::call('patient-documents:backfill-capture-branch', ['--apply' => true, '--actor' => superAdmin()->id, '--json' => true]);
    $report = json_decode(Artisan::output(), true);

    expect($document->fresh()->captured_branch_id)->toBeNull()
        ->and($report['unresolved_by_reason'])->toHaveKey('no_registration_visit');
});

it('refuses to apply the backfill without a named super admin actor', function () {
    $patient = Patient::factory()->create(['branch_id' => $this->branchA->id, 'medical_record_number' => 'DG-SPN4-2026-77008']);
    ClinicVisit::factory()->create(['patient_id' => $patient->id, 'branch_id' => $this->branchA->id, 'created_at' => $patient->created_at]);
    $document = kbiDocument($patient, null);

    expect(Artisan::call('patient-documents:backfill-capture-branch', ['--apply' => true, '--json' => true]))->not->toBe(0)
        ->and(Artisan::call('patient-documents:backfill-capture-branch', ['--apply' => true, '--actor' => userInRole('Supervisor RME')->id, '--json' => true]))->not->toBe(0)
        ->and($document->fresh()->captured_branch_id)->toBeNull();
});

it('reports the backfill without patient identifiers', function () {
    $patient = Patient::factory()->create([
        'name' => 'Pasien Laporan Backfill',
        'branch_id' => $this->branchA->id,
        'medical_record_number' => 'DG-SPN4-2026-77006',
        'ktp_number' => '7371099909900002',
    ]);
    kbiDocument($patient, null);

    Artisan::call('patient-documents:backfill-capture-branch', ['--json' => true]);
    $output = Artisan::output();

    expect($output)->not->toContain('Pasien Laporan Backfill')
        ->and($output)->not->toContain('7371099909900002')
        ->and($output)->not->toContain('DG-SPN4-2026-77006')
        ->and($output)->not->toContain('patient-documents/');
});

// --- 8. The per-account grant is governed ------------------------------------------

function kbiGrant(array $args): array
{
    Artisan::call('rbac:patient-ktp-cross-branch-access', $args + ['--json' => true]);

    return json_decode(Artisan::output(), true) ?? [];
}

it('previews a grant without writing anything', function () {
    $supervisor = userInRole('Supervisor RME');

    $report = kbiGrant(['action' => 'grant', '--user' => $supervisor->id, '--actor' => superAdmin()->id, '--reason' => 'Audit lintas cabang disetujui.']);

    expect($report['mode'])->toBe('dry-run')
        ->and($report['eligible'])->toBeTrue()
        ->and($supervisor->fresh()->hasDirectPermission(PatientDocumentAccessService::CROSS_BRANCH_PERMISSION))->toBeFalse();
});

it('grants one supervisor rme account, audited without the reason text', function () {
    $supervisor = userInRole('Supervisor RME');
    $actor = superAdmin();
    $reason = 'Audit lintas cabang disetujui pemilik.';

    $report = kbiGrant(['action' => 'grant', '--user' => $supervisor->id, '--actor' => $actor->id, '--reason' => $reason, '--apply' => true]);

    expect($report['changed'])->toBeTrue()
        ->and($report['holds_cross_branch_ktp_access'])->toBeTrue();

    $row = AuditLog::where('action', 'PATIENT_KTP_CROSS_BRANCH_ACCESS_GRANTED')->sole();
    expect((int) $row->entity_id)->toBe($supervisor->id)
        ->and((int) $row->performed_by)->toBe($actor->id)
        ->and($row->new_values['reason_length'])->toBe(mb_strlen($reason))
        ->and(json_encode($row->new_values))->not->toContain('lintas cabang');

    // Only that account: another Supervisor RME still reads nothing.
    $this->actingAs($supervisor->fresh())->get(kbiShowUrl($this->patient, $this->document))->assertOk();
    $this->actingAs(userInRole('Supervisor RME'))->get(kbiShowUrl($this->patient, $this->document))->assertNotFound();
});

it('refuses a grant to anyone but a supervisor rme, or by anyone but super admin', function () {
    $supervisor = userInRole('Supervisor RME');
    $frontOffice = kbiFrontOffice($this->branchA);
    $reason = 'Permintaan akses lintas cabang.';

    expect(kbiGrant(['action' => 'grant', '--user' => $frontOffice->id, '--actor' => superAdmin()->id, '--reason' => $reason, '--apply' => true])['ok'] ?? null)->toBeFalse()
        ->and(kbiGrant(['action' => 'grant', '--user' => $supervisor->id, '--actor' => userInRole('Supervisor RME')->id, '--reason' => $reason, '--apply' => true])['ok'] ?? null)->toBeFalse()
        ->and(kbiGrant(['action' => 'grant', '--user' => $supervisor->id, '--actor' => superAdmin()->id, '--reason' => 'pendek', '--apply' => true])['ok'] ?? null)->toBeFalse();

    expect($frontOffice->fresh()->hasDirectPermission(PatientDocumentAccessService::CROSS_BRANCH_PERMISSION))->toBeFalse()
        ->and($supervisor->fresh()->hasDirectPermission(PatientDocumentAccessService::CROSS_BRANCH_PERMISSION))->toBeFalse()
        ->and(AuditLog::where('action', 'PATIENT_KTP_CROSS_BRANCH_ACCESS_GRANTED')->count())->toBe(0);
});

it('revokes the grant and the card closes on the next request', function () {
    $supervisor = userInRole('Supervisor RME');
    $actor = superAdmin();
    kbiGrant(['action' => 'grant', '--user' => $supervisor->id, '--actor' => $actor->id, '--reason' => 'Audit lintas cabang disetujui.', '--apply' => true]);
    $this->actingAs($supervisor->fresh())->get(kbiShowUrl($this->patient, $this->document))->assertOk();

    $report = kbiGrant(['action' => 'revoke', '--user' => $supervisor->id, '--actor' => $actor->id, '--reason' => 'Audit lintas cabang selesai.', '--apply' => true]);

    expect($report['changed'])->toBeTrue()
        ->and(AuditLog::where('action', 'PATIENT_KTP_CROSS_BRANCH_ACCESS_REVOKED')->count())->toBe(1);
    $this->actingAs($supervisor->fresh())->get(kbiShowUrl($this->patient, $this->document))->assertNotFound();
});
