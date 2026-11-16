<?php

/**
 * DMS-RME-COMPREHENSIVE-CLOSURE-1 — memo M2.4, doctor-scope plan PR "B — downloads"
 * (rule 243 §1; owner revision of rule 93 §12, 2026-11-17).
 *
 * A Doctor may READ a published legacy archive (as inline page images) and a
 * signed PTM, but may never print, export or download them: the legacy RME
 * print/export/source PDF, the legacy odontogram source PDF and the PTM print
 * are added to the rule 138 §6 print-deny list. The prescription print stays
 * available to the doctor; Front Office, Admin Klinik and Supervisor RME keep
 * their print path; Owner, Super Admin and every out-of-scope answer are pinned
 * exactly as they were.
 *
 * "Doctor" is rule 138's one definition (DoctorRoomScopeService delegating to
 * DoctorPatientScopeService::shouldApplyDoctorScope): the Doctor role, unless the
 * account also holds Owner, Super Admin or Supervisor RME.
 *
 * Three layers: the route permission stays; the policy denies (`download` on the
 * legacy records, `print` on the consent) so `@can` hides the controls; and the
 * serving controller re-asserts through DoctorRoomScopeService, so a crafted URL
 * is refused even when the policy is bypassed (Gate::before).
 */

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Consent\Models\RmeVisitConsent;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\LegacyOdontogram\Models\LegacyOdontogramRecord;
use App\Modules\LegacyOdontogram\Models\LegacyOdontogramRecordPage;
use App\Modules\LegacyRme\Models\LegacyRmeImport;
use App\Modules\LegacyRme\Models\LegacyRmeRecord;
use App\Modules\LegacyRme\Models\LegacyRmeRecordPage;
use App\Modules\Patient\Models\Patient;
use App\Modules\Prescription\Models\RmePrescription;
use App\Modules\RmeOnlineContext\Middleware\EnsureRmeOnlineContext;
use App\Support\Storage\ClinicalEvidenceStorage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

const DRSACP_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

const DRSACP_DENY_MESSAGE = 'Dokter tidak diizinkan mencetak atau mengunduh rekam medis.';

beforeEach(function () {
    seedAccessControl();
    legacyRmeArchiveFlag(true);
    Storage::fake('legacy_rme_private');
    Storage::fake('legacy_odontogram_private');
    Storage::fake(ClinicalEvidenceStorage::diskName());

    // MAIN is never an RME branch; leaving it RME-enabled would let an unpinned
    // BranchContext fall back into scope and hide a real failure.
    Branch::query()->where('code', Branch::MAIN_CODE)->update(['is_rme_enabled' => false]);

    $this->branch = Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => true]);
    $this->otherBranch = Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => true]);
    $this->room = ClinicRoom::factory()->create([
        'branch_id' => $this->branch->id,
        'status' => ClinicRoom::STATUS_ACTIVE,
    ]);

    $this->doctorRecord = Doctor::factory()->withAllowedBranches([$this->branch])->create();
    $this->doctor = rmeMakeDoctorOnline($this->doctorRecord, $this->branch, $this->room);
    // The Doctor role holds the legacy RME clinical-read permission; the legacy
    // odontogram one is granted here so that door is reachable at all and the
    // refusal below can only come from the download rule.
    $this->doctor->givePermissionTo('view_legacy_odontogram_archive');

    $this->patient = Patient::factory()->create(['branch_id' => $this->branch->id]);

    // The doctor's own encounter: the treating relationship (DoctorPatientScope)
    // and the room scope (rule 138) both admit it, so only the download rule is
    // under test.
    $this->visit = ClinicVisit::factory()->create([
        'branch_id' => $this->branch->id,
        'clinic_room_id' => $this->room->id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctorRecord->id,
        'status' => ClinicVisit::STATUS_IN_PROGRESS,
    ]);

    $this->legacyRme = drsacpLegacyRme($this->patient, $this->branch);
    $this->legacyOdontogram = drsacpLegacyOdontogram($this->patient, $this->branch);
    $this->consent = RmeVisitConsent::factory()->create(['clinic_visit_id' => $this->visit->id]);
});

function drsacpLegacyRme(Patient $patient, Branch $branch): LegacyRmeRecord
{
    $record = LegacyRmeRecord::factory()->create([
        'patient_id' => $patient->id,
        'origin_branch_id' => $branch->id,
        'source_disk' => 'legacy_rme_private',
        'source_pdf_path' => 'drsacp/rme/source.pdf',
        'page_count' => 1,
        'status' => LegacyRmeRecord::STATUS_PUBLISHED,
    ]);

    LegacyRmeRecordPage::factory()->create([
        'rme_legacy_record_id' => $record->id,
        'page_number' => 1,
        'background_disk' => 'legacy_rme_private',
        'background_path' => 'drsacp/rme/page-1.png',
    ]);

    Storage::disk('legacy_rme_private')->put('drsacp/rme/source.pdf', legacyRmePdfBytes(1));
    Storage::disk('legacy_rme_private')->put('drsacp/rme/page-1.png', base64_decode(DRSACP_PNG));

    return $record->refresh();
}

function drsacpLegacyOdontogram(Patient $patient, Branch $branch): LegacyOdontogramRecord
{
    $record = LegacyOdontogramRecord::factory()->create([
        'patient_id' => $patient->id,
        'branch_id' => $branch->id,
        'source_disk' => 'legacy_odontogram_private',
        'source_pdf_path' => 'drsacp/odontogram/source.pdf',
        'page_count' => 1,
    ]);

    LegacyOdontogramRecordPage::query()->create([
        'odontogram_legacy_record_id' => $record->id,
        'page_number' => 1,
        'width' => 1,
        'height' => 1,
        'dpi' => 200,
        'rotation' => 0,
        'image_disk' => 'legacy_odontogram_private',
        'image_path' => 'drsacp/odontogram/page-1.png',
        'image_sha256' => str_repeat('b', 64),
    ]);

    Storage::disk('legacy_odontogram_private')->put('drsacp/odontogram/source.pdf', legacyRmePdfBytes(1));
    Storage::disk('legacy_odontogram_private')->put('drsacp/odontogram/page-1.png', base64_decode(DRSACP_PNG));

    return $record->refresh();
}

/** Every download door, as [route name, test-property name of its subject, audit event, action]. */
function drsacpDoors(): array
{
    return [
        'legacy RME source PDF' => ['rme.legacy-records.source', 'legacyRme', 'DOCTOR_PRINT_LEGACY_RME_REJECTED', 'source'],
        'legacy RME print' => ['rme.legacy-records.print', 'legacyRme', 'DOCTOR_PRINT_LEGACY_RME_REJECTED', 'print'],
        'legacy RME export' => ['rme.legacy-records.export', 'legacyRme', 'DOCTOR_PRINT_LEGACY_RME_REJECTED', 'export'],
        'legacy odontogram source PDF' => ['rme.legacy-odontograms.source', 'legacyOdontogram', 'DOCTOR_PRINT_LEGACY_ODONTOGRAM_REJECTED', 'source'],
        'PTM print' => ['rme.consents.print', 'consent', 'DOCTOR_PRINT_CONSENT_REJECTED', 'print'],
    ];
}

function drsacpAuditRows(string $event): Collection
{
    return DB::table('sys_audit_logs')->where('action', $event)->get();
}

function drsacpFrontOffice(Branch $branch): User
{
    $user = User::factory()->create();
    rmeMakeFrontOfficeActive($user, $branch);

    return $user->refresh();
}

function drsacpAdminKlinik(Branch $branch): User
{
    return rmeAdminClinicUser($branch)->refresh();
}

/*
|--------------------------------------------------------------------------
| A — the doctor is refused on every download door, over HTTP, and audited
|--------------------------------------------------------------------------
*/

it('refuses a treating doctor every legacy download and the PTM print with 403', function (string $route, string $subject, string $event, string $action) {
    $model = $this->{$subject};

    $this->actingAs($this->doctor)
        ->get(route($route, $model->getKey()))
        ->assertForbidden();

    $rows = drsacpAuditRows($event);

    expect($rows)->toHaveCount(1)
        ->and((int) $rows->first()->entity_id)->toBe((int) $model->getKey())
        ->and($rows->first()->entity_type)->toBe($model->getTable())
        ->and((int) $rows->first()->performed_by)->toBe((int) $this->doctor->id);

    // Identifiers only — never a name, an RM number or a KTP/NIK.
    $payload = (string) $rows->first()->new_values;
    expect($payload)->toContain($action)
        ->and($payload)->not->toContain((string) $this->patient->name)
        ->and($payload)->not->toContain((string) $this->patient->medical_record_number);
})->with(drsacpDoors());

it('refuses a doctor who crafts the URL even when the policy is bypassed (the serving path re-asserts)', function (string $route, string $subject) {
    // Simulate a policy that no longer refuses (a regression, or Gate::before
    // answering the ability): the controller must still refuse through the one
    // print-deny authority.
    Gate::before(fn () => true);

    $this->actingAs($this->doctor)
        ->get(route($route, $this->{$subject}->getKey()))
        ->assertForbidden();
})->with(drsacpDoors());

it('refuses a Doctor who also holds Front Office, as rule 138 defines a doctor', function (string $route, string $subject) {
    rmeMakeFrontOfficeActive($this->doctor, $this->branch);
    // The doctor context stays the live one for this account.
    rmeMakeDoctorOnline($this->doctorRecord, $this->branch, $this->room, $this->doctor);

    $this->actingAs($this->doctor->refresh())
        ->get(route($route, $this->{$subject}->getKey()))
        ->assertForbidden();
})->with(drsacpDoors());

it('denies the download and print abilities at the policy, with the rule 138 message', function () {
    $doctor = $this->doctor;

    foreach ([
        Gate::forUser($doctor)->inspect('download', $this->legacyRme),
        Gate::forUser($doctor)->inspect('download', $this->legacyOdontogram),
        Gate::forUser($doctor)->inspect('print', $this->consent),
    ] as $decision) {
        expect($decision->denied())->toBeTrue()
            ->and($decision->message())->toBe(DRSACP_DENY_MESSAGE);
    }

    expect(Gate::forUser($doctor)->allows('downloadAny', LegacyRmeRecord::class))->toBeFalse();
});

it('still answers 404 to a doctor outside the archive branch, before any download rule', function (string $route, string $subject) {
    $foreign = $subject === 'legacyRme'
        ? drsacpLegacyRme(Patient::factory()->create(['branch_id' => $this->otherBranch->id]), $this->otherBranch)
        : drsacpLegacyOdontogram(Patient::factory()->create(['branch_id' => $this->otherBranch->id]), $this->otherBranch);

    $this->actingAs($this->doctor)
        ->get(route($route, $foreign->getKey()))
        ->assertNotFound();

    expect(DB::table('sys_audit_logs')->where('action', 'like', 'DOCTOR_PRINT_%')->count())->toBe(0);
})->with([
    'legacy RME source PDF' => ['rme.legacy-records.source', 'legacyRme'],
    'legacy RME print' => ['rme.legacy-records.print', 'legacyRme'],
    'legacy RME export' => ['rme.legacy-records.export', 'legacyRme'],
    'legacy odontogram source PDF' => ['rme.legacy-odontograms.source', 'legacyOdontogram'],
]);

it('keeps a pure doctor out of the oversight and staging download doors (route permission, unchanged)', function (string $route, array $params) {
    $this->actingAs($this->doctor)
        ->get(route($route, $params))
        ->assertForbidden();
})->with([
    'oversight legacy RME source' => ['oversight.legacy.rme.source', ['record' => 1]],
    'oversight legacy RME print' => ['oversight.legacy.rme.print', ['record' => 1]],
    'oversight legacy RME pdf' => ['oversight.legacy.rme.pdf', ['record' => 1]],
    'oversight legacy odontogram source' => ['oversight.legacy.odontogram.source', ['record' => 1]],
    'oversight legacy odontogram print' => ['oversight.legacy.odontogram.print', ['record' => 1]],
    'staging legacy RME import source' => ['settings.rme.legacy-imports.source', ['import' => 1]],
]);

/*
|--------------------------------------------------------------------------
| B — the doctor still READS: inline page images, the viewer, the PTM
|--------------------------------------------------------------------------
*/

it('still lets the treating doctor read the archives and their page images inline, private and nosniff', function () {
    $this->actingAs($this->doctor)->get(route('rme.legacy-records.show', $this->legacyRme->id))->assertOk();
    $this->actingAs($this->doctor)->get(route('rme.legacy-odontograms.show', $this->legacyOdontogram->id))->assertOk();
    $this->actingAs($this->doctor)->get(route('rme.consents.show', $this->consent))->assertOk();

    foreach ([
        route('rme.legacy-records.pages.show', [$this->legacyRme->id, 1]),
        route('rme.legacy-odontograms.pages.show', [$this->legacyOdontogram->id, 1]),
    ] as $url) {
        $response = $this->actingAs($this->doctor)->get($url)->assertOk();

        expect($response->headers->get('content-type'))->toBe('image/png')
            ->and($response->headers->get('content-disposition'))->toStartWith('inline')
            ->and($response->headers->get('cache-control'))->toContain('no-store')
            ->and($response->headers->get('cache-control'))->toContain('private')
            ->and($response->headers->get('x-content-type-options'))->toBe('nosniff');
    }

    expect(Gate::forUser($this->doctor)->allows('viewFile', $this->legacyRme))->toBeTrue()
        ->and(Gate::forUser($this->doctor)->allows('viewFile', $this->legacyOdontogram))->toBeTrue()
        ->and(Gate::forUser($this->doctor)->allows('view', $this->consent))->toBeTrue()
        ->and(DB::table('sys_audit_logs')->where('action', 'like', 'DOCTOR_PRINT_%')->count())->toBe(0);
});

it('keeps the prescription print available to the doctor', function () {
    $prescription = RmePrescription::factory()->withStoredCanvases()->unsigned()->create([
        'clinic_visit_id' => $this->visit->id,
        'branch_id' => $this->branch->id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctorRecord->id,
    ]);

    $this->actingAs($this->doctor)
        ->get(route('rme.prescriptions.print', $prescription))
        ->assertOk();
});

it('keeps the native RME and odontogram print denied to the doctor (rule 138 unchanged)', function () {
    $this->actingAs($this->doctor)->get(route('rme.visits.print', $this->visit))->assertForbidden();
    $this->actingAs($this->doctor)->get(route('rme.visits.pdf', $this->visit))->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| C — Front Office, Admin Klinik and Supervisor RME keep their print path
|--------------------------------------------------------------------------
*/

it('lets Front Office and Admin Klinik print and download within their branch', function (string $role) {
    $actor = $role === 'Front Office' ? drsacpFrontOffice($this->branch) : drsacpAdminKlinik($this->branch);

    $this->actingAs($actor)->get(route('rme.legacy-records.source', $this->legacyRme->id))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
    $this->actingAs($actor)->get(route('rme.legacy-records.print', $this->legacyRme->id))->assertOk();
    $this->actingAs($actor)->get(route('rme.legacy-odontograms.source', $this->legacyOdontogram->id))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
    $this->actingAs($actor)->get(route('rme.consents.print', $this->consent))->assertOk();

    if (extension_loaded('gd')) {
        $this->actingAs($actor)->get(route('rme.legacy-records.export', $this->legacyRme->id))->assertOk();
    }

    expect(Gate::forUser($actor)->allows('download', $this->legacyRme))->toBeTrue()
        ->and(Gate::forUser($actor)->allows('download', $this->legacyOdontogram))->toBeTrue()
        ->and(Gate::forUser($actor)->allows('print', $this->consent))->toBeTrue()
        ->and(DB::table('sys_audit_logs')->where('action', 'like', 'DOCTOR_PRINT_%')->count())->toBe(0);
})->with(['Front Office', 'Admin Klinik']);

it('still refuses Front Office and Admin Klinik from another branch exactly as before', function (string $role) {
    $actor = $role === 'Front Office' ? drsacpFrontOffice($this->otherBranch) : drsacpAdminKlinik($this->otherBranch);

    $this->actingAs($actor)->get(route('rme.legacy-records.source', $this->legacyRme->id))->assertNotFound();
    $this->actingAs($actor)->get(route('rme.legacy-records.print', $this->legacyRme->id))->assertNotFound();
    $this->actingAs($actor)->get(route('rme.legacy-records.export', $this->legacyRme->id))->assertNotFound();
    $this->actingAs($actor)->get(route('rme.legacy-odontograms.source', $this->legacyOdontogram->id))->assertNotFound();
    $this->actingAs($actor)->get(route('rme.consents.print', $this->consent))->assertForbidden();
})->with(['Front Office', 'Admin Klinik']);

it('pins Supervisor RME and Super Admin to their unchanged print path', function (string $role) {
    $actor = $role === 'Super Admin' ? superAdmin() : userInRole('Supervisor RME');

    $this->withoutMiddleware(EnsureRmeOnlineContext::class);

    $this->actingAs($actor)->get(route('rme.legacy-records.source', $this->legacyRme->id))->assertOk();
    $this->actingAs($actor)->get(route('rme.legacy-records.print', $this->legacyRme->id))->assertOk();
    $this->actingAs($actor)->get(route('rme.legacy-odontograms.source', $this->legacyOdontogram->id))->assertOk();

    expect(Gate::forUser($actor)->allows('download', $this->legacyRme))->toBeTrue()
        ->and(Gate::forUser($actor)->allows('downloadAny', LegacyRmeRecord::class))->toBeTrue();
})->with(['Supervisor RME', 'Super Admin']);

it('exempts a Doctor who is also Supervisor RME, as rule 138 does', function () {
    $this->doctor->assignRole('Supervisor RME');

    $this->actingAs($this->doctor->refresh())
        ->get(route('rme.legacy-records.source', $this->legacyRme->id))
        ->assertOk();
});

it('pins Owner outside every download door (route permission, unchanged)', function (string $route, string $subject) {
    $this->actingAs(userInRole('Owner'))
        ->get(route($route, $this->{$subject}->getKey()))
        ->assertForbidden();

    expect(DB::table('sys_audit_logs')->where('action', 'like', 'DOCTOR_PRINT_%')->count())->toBe(0);
})->with(drsacpDoors());

/*
|--------------------------------------------------------------------------
| D — the UI mirrors the server: no download control for a doctor
|--------------------------------------------------------------------------
*/

it('hides the legacy RME source, print and export controls from the doctor but keeps the page gallery', function () {
    $html = $this->actingAs($this->doctor)
        ->get(route('rme.legacy-records.show', $this->legacyRme->id))
        ->assertOk()
        ->getContent();

    expect($html)->not->toContain(route('rme.legacy-records.source', $this->legacyRme->id))
        ->and($html)->not->toContain(route('rme.legacy-records.print', $this->legacyRme->id))
        ->and($html)->not->toContain(route('rme.legacy-records.export', $this->legacyRme->id))
        ->and($html)->not->toContain('Buka PDF Arsip')
        ->and($html)->not->toContain('Unduh PDF')
        ->and($html)->toContain(route('rme.legacy-records.pages.show', [$this->legacyRme->id, 1]));
});

it('shows the legacy RME source, print and export controls to Front Office', function () {
    $html = $this->actingAs(drsacpFrontOffice($this->branch))
        ->get(route('rme.legacy-records.show', $this->legacyRme->id))
        ->assertOk()
        ->getContent();

    expect($html)->toContain(route('rme.legacy-records.source', $this->legacyRme->id))
        ->and($html)->toContain(route('rme.legacy-records.print', $this->legacyRme->id))
        ->and($html)->toContain(route('rme.legacy-records.export', $this->legacyRme->id));
});

it('hides the legacy odontogram source control from the doctor and shows it to Front Office', function () {
    $sourceUrl = route('rme.legacy-odontograms.source', $this->legacyOdontogram->id);

    $doctorHtml = $this->actingAs($this->doctor)
        ->get(route('rme.legacy-odontograms.show', $this->legacyOdontogram->id))
        ->assertOk()
        ->getContent();

    $frontOfficeHtml = $this->actingAs(drsacpFrontOffice($this->branch))
        ->get(route('rme.legacy-odontograms.show', $this->legacyOdontogram->id))
        ->assertOk()
        ->getContent();

    expect($doctorHtml)->not->toContain($sourceUrl)
        ->and($doctorHtml)->not->toContain('Buka PDF Sumber')
        ->and($doctorHtml)->toContain(route('rme.legacy-odontograms.pages.show', [$this->legacyOdontogram->id, 1]))
        ->and($frontOfficeHtml)->toContain($sourceUrl);
});

it('hides the PTM print control from the doctor and shows it to Front Office', function () {
    $printUrl = route('rme.consents.print', $this->consent);

    $doctorHtml = $this->actingAs($this->doctor)->get(route('rme.consents.show', $this->consent))->assertOk()->getContent();
    $frontOfficeHtml = $this->actingAs(drsacpFrontOffice($this->branch))->get(route('rme.consents.show', $this->consent))->assertOk()->getContent();

    expect($doctorHtml)->not->toContain($printUrl)
        ->and($doctorHtml)->not->toContain('Cetak Persetujuan')
        ->and($frontOfficeHtml)->toContain($printUrl)
        ->and($frontOfficeHtml)->toContain('Cetak Persetujuan');
});

it('never renders the legacy source PDF URL in the doctor workspace, while keeping the page images', function () {
    $html = $this->followingRedirects()
        ->actingAs($this->doctor)
        ->get(route('rme.visits.medical-record.show', $this->visit))
        ->assertOk()
        ->getContent();

    $sourcePath = '/rme/legacy-records/'.$this->legacyRme->id.'/source';
    $pagePath = '/rme/legacy-records/'.$this->legacyRme->id.'/pages/';

    expect($html)->not->toContain($sourcePath)
        ->and($html)->not->toContain(str_replace('/', '\/', $sourcePath))
        ->and(str_contains($html, $pagePath) || str_contains($html, str_replace('/', '\/', $pagePath)))->toBeTrue();
});

it('still renders the legacy source PDF URL in the workspace for a reader who may download', function () {
    $reader = rmeClinicalAuthor(['manage_clinic_visits', 'view_clinic_visits', 'view_legacy_rme_archive']);
    $reader->forceFill(['branch_id' => $this->branch->id])->save();

    $html = $this->followingRedirects()
        ->actingAs($reader->refresh())
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('rme.visits.medical-record.show', $this->visit))
        ->assertOk()
        ->getContent();

    $sourcePath = '/rme/legacy-records/'.$this->legacyRme->id.'/source';

    expect(str_contains($html, $sourcePath) || str_contains($html, str_replace('/', '\/', $sourcePath)))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| E — independent review: every other door a dual-role Doctor could use
|--------------------------------------------------------------------------
| A Doctor who also holds an intake role (Front Office, Admin Klinik) holds
| `view_legacy_rme_imports`, so the STAGING source of the very same PDF is a
| door; one who also holds an oversight role holds `export_oversight_data`, so
| the OVERSIGHT print/pdf/source doors are too. Rule 243 §1.2 refuses them all.
*/

function drsacpStagingImport(Patient $patient, Branch $branch): LegacyRmeImport
{
    $import = LegacyRmeImport::factory()->readyForReview()->create([
        'patient_id' => $patient->id,
        'origin_branch_id' => $branch->id,
        'source_disk' => 'legacy_rme_private',
        'source_pdf_path' => 'drsacp/staging/source.pdf',
    ]);

    Storage::disk('legacy_rme_private')->put('drsacp/staging/source.pdf', legacyRmePdfBytes(1));

    return $import->refresh();
}

/** The treating doctor, additionally holding $role (its context started, then the doctor one again). */
function drsacpDualRoleDoctor(string $role): User
{
    $test = test();
    $doctor = $test->doctor;

    match ($role) {
        'Front Office' => rmeMakeFrontOfficeActive($doctor, $test->branch),
        'Admin Klinik' => rmeMakeAdminClinicActive($doctor, $test->branch),
        default => $doctor->assignRole($role),
    };

    rmeMakeDoctorOnline($test->doctorRecord, $test->branch, $test->room, $doctor);

    return $doctor->refresh();
}

/** Every oversight download door, as [route name, subject property, audit event, action]. */
function drsacpOversightDoors(): array
{
    return [
        'oversight legacy RME source' => ['oversight.legacy.rme.source', 'legacyRme', 'DOCTOR_PRINT_LEGACY_RME_REJECTED', 'oversight_source'],
        'oversight legacy RME print' => ['oversight.legacy.rme.print', 'legacyRme', 'DOCTOR_PRINT_LEGACY_RME_REJECTED', 'oversight_print'],
        'oversight legacy RME pdf' => ['oversight.legacy.rme.pdf', 'legacyRme', 'DOCTOR_PRINT_LEGACY_RME_REJECTED', 'oversight_pdf'],
        'oversight legacy odontogram source' => ['oversight.legacy.odontogram.source', 'legacyOdontogram', 'DOCTOR_PRINT_LEGACY_ODONTOGRAM_REJECTED', 'oversight_source'],
        'oversight legacy odontogram print' => ['oversight.legacy.odontogram.print', 'legacyOdontogram', 'DOCTOR_PRINT_LEGACY_ODONTOGRAM_REJECTED', 'oversight_print'],
    ];
}

it('refuses a Doctor who also holds an intake role the staging source PDF, audited', function (string $role) {
    $import = drsacpStagingImport($this->patient, $this->branch);
    $actor = drsacpDualRoleDoctor($role);

    $this->actingAs($actor)
        ->get(route('settings.rme.legacy-imports.source', $import->getKey()))
        ->assertForbidden();

    $rows = drsacpAuditRows('DOCTOR_PRINT_LEGACY_RME_REJECTED');

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->entity_type)->toBe($import->getTable())
        ->and((int) $rows->first()->entity_id)->toBe((int) $import->getKey())
        ->and((string) $rows->first()->new_values)->toContain('staging_source')
        ->and(Gate::forUser($actor)->inspect('download', $import)->message())->toBe(DRSACP_DENY_MESSAGE);
})->with(['Front Office', 'Admin Klinik']);

it('still serves the staging source PDF to pure Front Office and Admin Klinik', function (string $role) {
    $import = drsacpStagingImport($this->patient, $this->branch);
    $actor = $role === 'Front Office' ? drsacpFrontOffice($this->branch) : drsacpAdminKlinik($this->branch);

    $this->actingAs($actor)
        ->get(route('settings.rme.legacy-imports.source', $import->getKey()))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    expect(Gate::forUser($actor)->allows('download', $import))->toBeTrue()
        ->and(DB::table('sys_audit_logs')->where('action', 'like', 'DOCTOR_PRINT_%')->count())->toBe(0);
})->with(['Front Office', 'Admin Klinik']);

it('hides the staging source control from a dual-role doctor and shows it to Front Office', function () {
    $import = drsacpStagingImport($this->patient, $this->branch);
    $sourceUrl = route('settings.rme.legacy-imports.source', $import->getKey());

    $frontOfficeHtml = $this->actingAs(drsacpFrontOffice($this->branch))
        ->get(route('settings.rme.legacy-imports.show', $import->getKey()))
        ->assertOk()
        ->getContent();

    $doctorHtml = $this->actingAs(drsacpDualRoleDoctor('Front Office'))
        ->get(route('settings.rme.legacy-imports.show', $import->getKey()))
        ->assertOk()
        ->getContent();

    expect($frontOfficeHtml)->toContain($sourceUrl)
        ->and($doctorHtml)->not->toContain($sourceUrl);
});

it('refuses a Doctor who also holds an oversight role every oversight download door, audited', function (string $route, string $subject, string $event, string $action, string $role) {
    $model = $this->{$subject};
    $actor = drsacpDualRoleDoctor($role);

    $this->actingAs($actor)
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route($route, $model->getKey()))
        ->assertForbidden();

    $rows = drsacpAuditRows($event);

    expect($rows)->toHaveCount(1)
        ->and((int) $rows->first()->entity_id)->toBe((int) $model->getKey())
        ->and((string) $rows->first()->new_values)->toContain($action);
})->with(drsacpOversightDoors())->with(['Finance Klinik', 'Data Analyst']);

it('still serves every oversight download door to a pure oversight role', function (string $route, string $subject, string $event, string $action, string $role) {
    if ($route === 'oversight.legacy.rme.pdf' && ! extension_loaded('gd')) {
        $this->markTestSkipped('requires the GD extension (dompdf image decoding)');
    }

    $actor = User::factory()->create()->assignRole($role);

    $this->actingAs($actor)
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route($route, $this->{$subject}->getKey()))
        ->assertOk();

    expect(DB::table('sys_audit_logs')->where('action', 'like', 'DOCTOR_PRINT_%')->count())->toBe(0);
})->with(drsacpOversightDoors())->with(['Finance Klinik', 'Data Analyst']);

it('hides the oversight print, pdf and source controls from a dual-role doctor and shows them to Finance Klinik', function () {
    $urls = [
        route('oversight.legacy.rme.print', $this->legacyRme->id),
        route('oversight.legacy.rme.pdf', $this->legacyRme->id),
        route('oversight.legacy.rme.source', $this->legacyRme->id),
        route('oversight.legacy.odontogram.print', $this->legacyOdontogram->id),
        route('oversight.legacy.odontogram.source', $this->legacyOdontogram->id),
    ];

    $render = fn (User $actor): string => $this->actingAs($actor)
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('oversight.legacy.rme.show', $this->legacyRme->id))
        ->assertOk()
        ->getContent()
        .$this->actingAs($actor)
            ->withoutMiddleware(EnsureRmeOnlineContext::class)
            ->get(route('oversight.legacy.odontogram.show', $this->legacyOdontogram->id))
            ->assertOk()
            ->getContent();

    $financeHtml = $render(User::factory()->create()->assignRole('Finance Klinik'));
    $doctorHtml = $render(drsacpDualRoleDoctor('Finance Klinik'));

    foreach ($urls as $url) {
        expect($financeHtml)->toContain($url)
            ->and($doctorHtml)->not->toContain($url);
    }
});

it('answers a VOID archive 404 to a doctor before any download rule, with no doctor-print audit', function (string $route) {
    $this->legacyRme->forceFill([
        'status' => LegacyRmeRecord::STATUS_VOID,
        'voided_at' => now(),
        'void_reason' => 'Salah pasien, ditarik kembali.',
    ])->save();

    $this->actingAs($this->doctor)
        ->get(route($route, $this->legacyRme->id))
        ->assertNotFound();

    expect(DB::table('sys_audit_logs')->where('action', 'like', 'DOCTOR_PRINT_%')->count())->toBe(0);
})->with([
    'legacy RME print' => ['rme.legacy-records.print'],
    'legacy RME export' => ['rme.legacy-records.export'],
]);

it('degrades a broken page image to the "no preview" message when the reader has no source PDF', function () {
    $html = $this->followingRedirects()
        ->actingAs($this->doctor)
        ->get(route('rme.visits.medical-record.show', $this->visit))
        ->assertOk()
        ->getContent();

    // The viewer's image fallback must switch to the 'pdf' mode unconditionally:
    // with no source URL that mode renders the "no preview" message rather than
    // leaving a broken <img> in a clinical viewer.
    expect($html)->toContain('data-rme-legacy-no-preview')
        ->and(preg_match("/fallBackToPdf\\(\\)\\s*\\{(?:\\s*\\/\\/[^\\n]*)*\\s*this\\.mode = 'pdf';/", $html))->toBe(1);
});
