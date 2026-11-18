<?php

/*
|--------------------------------------------------------------------------
| DMS-RME-COMPREHENSIVE-CLOSURE-1 memo M3.3 PR6 — the handwriting amendment page over HTTP
|--------------------------------------------------------------------------
|
| Rule 240 §8. Only the doctor of record (or the finalizer) files a correction
| page with a sheet amendment; front desk roles get 403 from the route, a doctor
| who may not see the record gets 404 before any validation. The pending image
| is served by a policy-gated, `private, no-store` route to the requester and to
| an approver in scope only (404 otherwise), verified against its SHA-256. After
| the Supervisor RME approves, the page shows in the RM workspace, the RME print
| and the version history with "Halaman koreksi (amandemen #n, disetujui … WITA
| oleh …)", always through the policy-gated page route (never an object key); a
| tampered object is refused on display. A rejected correction never becomes a
| book page.
|
| Selected by the existing `RmeClinicalWriteAuthority` critical-gate token in both
| runner variants and declared in critical_gate_mandatory_suites (CG-19). Helper
| prefix `m3ph`.
*/

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Models\MedicalRecordHandwriting;
use App\Modules\MedicalRecord\Models\MedicalRecordHandwritingPage;
use App\Modules\MedicalRecord\Services\HandwritingPayloadGuard;
use App\Modules\Patient\Models\Patient;
use App\Modules\RmeAmendment\Models\RmeAmendment;
use App\Modules\RmeAmendment\Services\RmeAmendmentService;
use App\Support\Storage\ClinicalEvidenceStorage;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    seedAccessControl();
    Storage::fake(ClinicalEvidenceStorage::diskName());

    $this->branch = Branch::factory()->create(['code' => 'AMG1', 'is_active' => true, 'is_rme_enabled' => true]);
    $this->room = ClinicRoom::factory()->create(['branch_id' => $this->branch->id, 'status' => ClinicRoom::STATUS_ACTIVE]);
    $this->patient = Patient::factory()->create(['branch_id' => $this->branch->id]);
    $this->doctorUser = userInRole('Doctor');
    $this->doctor = Doctor::factory()->create(['user_id' => $this->doctorUser->id, 'branch_id' => $this->branch->id, 'is_active' => true]);
    rmeMakeDoctorOnline($this->doctor, $this->branch, $this->room, $this->doctorUser);
    $this->doctorUser = $this->doctorUser->fresh();
    $this->supervisor = userInRole('Supervisor RME');
});

function m3phPng(int $seed): string
{
    $raw = '';
    for ($y = 0; $y < 10; $y++) {
        $raw .= "\0".str_repeat(pack('C4', ($seed * 37 + $y) % 256, 30, 30, 255), 10);
    }
    $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

    return "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', 10, 10, 8, 6, 0, 0, 0)).$chunk('IDAT', gzcompress($raw)).$chunk('IEND', '');
}

function m3phSheet(?User $finalizer = null): MedicalRecord
{
    $visit = ClinicVisit::factory()->create([
        'branch_id' => test()->branch->id,
        'patient_id' => test()->patient->id,
        'doctor_id' => test()->doctor->id,
        'clinic_room_id' => test()->room->id,
        'status' => ClinicVisit::STATUS_COMPLETED,
    ]);
    $sheet = MedicalRecord::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => test()->doctor->id,
        'assessment' => 'm3ph pulpitis',
    ]);
    $sheet->forceFill(['status' => MedicalRecord::STATUS_FINAL, 'finalized_at' => now()->subDay(), 'finalized_by' => ($finalizer ?? test()->doctorUser)->id])->save();

    return $sheet->fresh();
}

/** Page 1 of the book, sealed, with its object on the private disk. */
function m3phPageOne(MedicalRecord $book): MedicalRecordHandwriting
{
    $bytes = m3phPng(1);
    $key = 'handwritings/'.$book->branch_id.'/'.$book->clinic_visit_id.'/m3ph_original_p1.png';
    Storage::disk(ClinicalEvidenceStorage::diskName())->put($key, $bytes);

    return MedicalRecordHandwriting::query()->forceCreate([
        'medical_record_id' => $book->id,
        'clinic_visit_id' => $book->clinic_visit_id,
        'branch_id' => $book->branch_id,
        'handwriting_path' => $key,
        'handwriting_hash' => hash('sha256', $bytes),
        'saved_at' => now()->subDays(2),
        'authored_clinic_visit_id' => $book->clinic_visit_id,
        'sealed_at' => now()->subDay(),
        'sealed_by_medical_record_id' => $book->id,
    ]);
}

/** The request form as the page posts it: the sheet's text unchanged, plus the drawing. */
function m3phForm(MedicalRecord $sheet, ?string $png, int|string|null $corrects = null): array
{
    return array_filter([
        'reason' => 'm3ph koreksi halaman tulisan tangan',
        'proposed' => ['fields' => ['assessment' => $sheet->assessment]],
        'handwriting_data' => $png !== null ? 'data:image/png;base64,'.base64_encode($png) : null,
        'corrects_page_number' => $corrects,
    ], fn ($value) => $value !== null);
}

function m3phPending(MedicalRecord $sheet, string $png, ?int $corrects = null): RmeAmendment
{
    return app(RmeAmendmentService::class)->request($sheet, test()->doctorUser, 'm3ph koreksi halaman tulisan tangan', [], 'data:image/png;base64,'.base64_encode($png), $corrects);
}

function m3phObjects(): array
{
    $files = Storage::disk(ClinicalEvidenceStorage::diskName())->allFiles();
    sort($files);

    return $files;
}

function m3phStranger(): User
{
    return rmeMakeDoctorOnline(Doctor::factory()->create(['branch_id' => test()->branch->id]), test()->branch);
}

/** An approver whose working branch is another one. */
function m3phOutOfScopeApprover(): User
{
    $other = Branch::factory()->create(['code' => 'AMG2', 'is_active' => true, 'is_rme_enabled' => true]);
    $pinned = userInRole('Perawat');
    $pinned->givePermissionTo(RmeAmendmentService::PERMISSION_APPROVE);
    rmeMakePerawatActive($pinned, $other);

    return $pinned->fresh();
}

/** Review M3 — the label links back to the amended encounter and the corrected page. */
function m3phLabel(RmeAmendment $amendment, User $approver, ?int $corrects = null): string
{
    $visit = ClinicVisit::query()->findOrFail($amendment->clinic_visit_id);

    return 'Halaman koreksi — amandemen #'.$amendment->id.' untuk kunjungan '.$visit->visit_number
        .' ('.$visit->visit_date->format('d/m/Y').' WITA)'
        .($corrects !== null ? ', mengoreksi halaman buku RM '.$corrects : '')
        .'; disetujui '.$amendment->decided_at->copy()->setTimezone('Asia/Makassar')->format('d/m/Y H:i').' WITA oleh '.$approver->name;
}

// ─── Request ─────────────────────────────────────────────────────────────────

it('files a correction page over HTTP as the doctor of record and refuses everyone else without writing an object', function () {
    $sheet = m3phSheet();
    m3phPageOne($sheet);
    $objects = m3phObjects();
    $png = m3phPng(5);

    $frontOffice = userInRole('Front Office');
    rmeMakeFrontOfficeActive($frontOffice, $this->branch);
    $perawat = userInRole('Perawat');
    rmeMakePerawatActive($perawat, $this->branch);
    foreach ([$frontOffice, $perawat] as $user) {
        $this->actingAs($user)->post(route('rme.amendments.store-sheet', $sheet), m3phForm($sheet, $png, 1))->assertForbidden();
    }

    // A treating doctor who is neither the doctor of record nor the finalizer: 403.
    $treating = Doctor::factory()->create(['branch_id' => $this->branch->id]);
    $otherDoctor = rmeMakeDoctorOnline($treating, $this->branch);
    ClinicVisit::factory()->create(['branch_id' => $this->branch->id, 'patient_id' => $this->patient->id, 'doctor_id' => $treating->id, 'clinic_room_id' => $this->room->id, 'status' => ClinicVisit::STATUS_COMPLETED]);
    $this->actingAs($otherDoctor)->post(route('rme.amendments.store-sheet', $sheet), m3phForm($sheet, $png, 1))->assertForbidden();

    // A doctor who may not see the record: 404, before the canvas is even validated.
    $stranger = m3phStranger();
    $this->actingAs($stranger)->post(route('rme.amendments.store-sheet', $sheet), m3phForm($sheet, $png, 1))->assertNotFound();
    $this->actingAs($stranger)->post(route('rme.amendments.store-sheet', $sheet), ['handwriting_data' => 'data:image/png;base64,@@@'] + m3phForm($sheet, null))->assertNotFound();

    $this->actingAs($this->supervisor)->post(route('rme.amendments.store-sheet', $sheet), m3phForm($sheet, $png, 1))->assertForbidden();
    $this->actingAs(userInRole('Super Admin'))->post(route('rme.amendments.store-sheet', $sheet), m3phForm($sheet, $png, 1))->assertForbidden();

    expect(RmeAmendment::query()->count())->toBe(0)
        ->and(m3phObjects())->toBe($objects);

    // The doctor of record: an invalid canvas or page number is a form error, nothing written.
    $this->actingAs($this->doctorUser)->from(route('rme.amendments.create-sheet', $sheet))
        ->post(route('rme.amendments.store-sheet', $sheet), ['handwriting_data' => 'data:image/png;base64,'.base64_encode('<html></html>')] + m3phForm($sheet, null))
        ->assertRedirect(route('rme.amendments.create-sheet', $sheet))
        ->assertSessionHasErrors(['handwriting_data' => HandwritingPayloadGuard::MESSAGE_INVALID]);
    $this->actingAs($this->doctorUser)->post(route('rme.amendments.store-sheet', $sheet), m3phForm($sheet, $png, 'satu'))
        ->assertSessionHasErrors('corrects_page_number');
    $this->actingAs($this->doctorUser)->post(route('rme.amendments.store-sheet', $sheet), m3phForm($sheet, $png, 7))
        ->assertSessionHasErrors(['corrects_page_number' => RmeAmendmentService::MESSAGE_CORRECTS_PAGE]);
    expect(RmeAmendment::query()->count())->toBe(0)
        ->and(m3phObjects())->toBe($objects);

    // The form offers the drawing canvas and the book's pages.
    $this->actingAs($this->doctorUser)->get(route('rme.amendments.create-sheet', $sheet))
        ->assertOk()
        ->assertSee('data-correction-canvas', false)
        ->assertSee('name="corrects_page_number"', false)
        ->assertSee('<option value="1"', false)
        ->assertSee('Halaman buku RM 1');

    $response = $this->actingAs($this->doctorUser)->post(route('rme.amendments.store-sheet', $sheet), m3phForm($sheet, $png, 1));
    $amendment = RmeAmendment::query()->sole();
    $response->assertRedirect(route('rme.amendments.show', $amendment));
    expect($amendment->correction_page_sha256)->toBe(hash('sha256', $png))
        ->and($amendment->corrects_page_number)->toBe(1)
        ->and($amendment->proposed_content)->toBe([]);
});

// ─── The pending image ───────────────────────────────────────────────────────

it('shows the pending correction to the requester and an approver in scope only: private, no-store, never its key', function () {
    $sheet = m3phSheet();
    m3phPageOne($sheet);
    $png = m3phPng(9);
    $amendment = m3phPending($sheet, $png, 1);
    $imageUrl = route('rme.amendments.correction-page', $amendment);

    $this->actingAs($this->supervisor)->get(route('rme.amendments.show', $amendment))
        ->assertOk()
        ->assertSee($imageUrl, false)
        ->assertSee('data-correction-page', false)
        ->assertSee('mengoreksi halaman buku RM 1')
        ->assertSee('data-amendment-approve', false)
        ->assertDontSee($amendment->correction_page_path);

    foreach ([$this->doctorUser, $this->supervisor] as $reader) {
        $response = $this->actingAs($reader)->get($imageUrl)
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private');
        expect($response->getContent())->toBe($png);
    }

    $outside = m3phOutOfScopeApprover();
    $this->actingAs($outside)->get(route('rme.amendments.show', $amendment))->assertNotFound();
    $this->actingAs($outside)->get($imageUrl)->assertNotFound();
    $this->actingAs($outside)->post(route('rme.amendments.approve', $amendment))->assertNotFound();
    $this->actingAs($outside)->post(route('rme.amendments.reject', $amendment), ['decision_reason' => 'm3ph di luar cabang'])->assertNotFound();

    $stranger = m3phStranger();
    $this->actingAs($stranger)->get(route('rme.amendments.show', $amendment))->assertNotFound();
    $this->actingAs($stranger)->get($imageUrl)->assertNotFound();

    $frontOffice = userInRole('Front Office');
    rmeMakeFrontOfficeActive($frontOffice, $this->branch);
    $this->actingAs($frontOffice)->get($imageUrl)->assertForbidden();

    // A tampered pending object is never served, and the approval is not offered.
    Storage::disk(ClinicalEvidenceStorage::diskName())->put($amendment->correction_page_path, m3phPng(10));
    $this->actingAs($this->supervisor)->get($imageUrl)->assertNotFound();
    $this->actingAs($this->supervisor)->get(route('rme.amendments.show', $amendment))
        ->assertOk()
        ->assertSee('data-correction-page-tampered', false)
        ->assertDontSee('data-amendment-approve', false);
    expect($amendment->fresh()->status)->toBe(RmeAmendment::STATUS_PENDING);
});

// ─── Approve, then the page everywhere ───────────────────────────────────────

it('approves over HTTP and shows the sealed page with its label in the workspace, the print and the version history', function () {
    $sheet = m3phSheet();
    m3phPageOne($sheet);
    $png = m3phPng(13);
    $amendment = m3phPending($sheet, $png, 1);

    $this->actingAs($this->supervisor)->post(route('rme.amendments.approve', $amendment))
        ->assertRedirect(route('rme.amendments.show', $amendment));

    $applied = $amendment->fresh();
    $page = MedicalRecordHandwritingPage::query()->where('rme_amendment_id', $amendment->id)->sole();
    $label = m3phLabel($applied, $this->supervisor, 1);
    $pointer = 'Halaman koreksi: buku RM halaman 2 (amandemen #'.$amendment->id.')';
    $key = $applied->correction_page_path;

    $image = $this->actingAs($this->supervisor)->get(route('rme.handwriting-pages.image', $page))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png');
    expect($image->headers->get('Cache-Control'))->toContain('no-store')->toContain('private')
        ->and($image->streamedContent())->toBe($png);

    foreach ([$this->supervisor, $this->doctorUser] as $reader) {
        $this->actingAs($reader)->get(route('rme.visits.medical-record.show', [$sheet->clinicVisit, 'rm_page' => 2]))
            ->assertOk()
            ->assertSee($label)
            ->assertSee('Halaman buku RM 2')
            ->assertSee('data-amendment-page="'.$amendment->id.'"', false)
            ->assertDontSee($key);
    }

    // Review M3 — the amended sheet points at its correction page beside "Diamandemen n×".
    $this->actingAs($this->doctorUser)->get(route('rme.visits.medical-record.show', $sheet->clinicVisit))
        ->assertOk()
        ->assertSee('Diamandemen 1×')
        ->assertSee($pointer);

    $this->actingAs($this->supervisor)->get(route('rme.visits.print', $sheet->clinicVisit))
        ->assertOk()
        ->assertSee($label)
        ->assertSee($pointer)
        ->assertSee('data:image/png;base64,'.base64_encode($png), false)
        ->assertDontSee($key);

    $this->actingAs($this->supervisor)->get(route('rme.amendments.versions.sheet.show', [$sheet, 2]))
        ->assertOk()
        ->assertSee($label)
        ->assertSee(route('rme.handwriting-pages.image', $page), false)
        ->assertDontSee($key);

    $this->actingAs($this->doctorUser)->get(route('rme.amendments.show', $amendment))
        ->assertOk()
        ->assertSee('Ditambahkan sebagai halaman buku RM 2')
        ->assertDontSee($key);

    // Outside the record's scope the existing page route refuses, as it does for
    // every page (its 403 is pinned by RmeClinicalDocumentReadScopeTest and
    // ClinicalEvidencePrivacyTest; the amendment routes of this PR answer 404).
    $this->actingAs(m3phStranger())->get(route('rme.handwriting-pages.image', $page))->assertForbidden();

    // A tampered object is refused on display: the route answers 404 and the print says so.
    Storage::disk(ClinicalEvidenceStorage::diskName())->put($key, m3phPng(14));
    $this->actingAs($this->supervisor)->get(route('rme.handwriting-pages.image', $page))->assertNotFound();
    $this->actingAs($this->supervisor)->get(route('rme.visits.print', $sheet->clinicVisit))
        ->assertOk()
        ->assertSee($label)
        ->assertSee('Gambar halaman ini tidak tersedia.')
        ->assertDontSee('data:image/png;base64,'.base64_encode(m3phPng(14)), false);

    // Review L3 — an applied amendment is not told it "cannot be applied".
    $this->actingAs($this->supervisor)->get(route('rme.amendments.show', $amendment))
        ->assertOk()
        ->assertSee('data-correction-page-tampered', false)
        ->assertDontSee('tidak dapat diterapkan');
});

it('tells a storage read error apart from tampering on the pending image and the detail page (review L2)', function () {
    $sheet = m3phSheet();
    $amendment = m3phPending($sheet, m3phPng(21));

    $broken = Mockery::mock(Filesystem::class);
    $broken->shouldReceive('exists')->andReturn(true);
    $broken->shouldReceive('get')->andThrow(new RuntimeException('m3ph clinical disk offline'));
    Storage::set(ClinicalEvidenceStorage::diskName(), $broken);

    $this->actingAs($this->supervisor)->get(route('rme.amendments.correction-page', $amendment))->assertStatus(503);
    $this->actingAs($this->supervisor)->get(route('rme.amendments.show', $amendment))
        ->assertOk()
        ->assertSee('data-correction-page-unreadable', false)
        ->assertSee(RmeAmendmentService::MESSAGE_CORRECTION_PAGE_UNREADABLE)
        ->assertDontSee('data-correction-page-tampered', false)
        ->assertDontSee('data-amendment-approve', false);
});

it('answers 503, never 404, on the book page route when the applied page cannot be read (review L2)', function () {
    $sheet = m3phSheet();
    m3phPageOne($sheet);
    $amendment = m3phPending($sheet, m3phPng(23), 1);
    $this->actingAs($this->supervisor)->post(route('rme.amendments.approve', $amendment))
        ->assertRedirect(route('rme.amendments.show', $amendment));
    $page = MedicalRecordHandwritingPage::query()->where('rme_amendment_id', $amendment->id)->sole();

    $this->actingAs($this->supervisor)->get(route('rme.handwriting-pages.image', $page))->assertOk();

    $broken = Mockery::mock(Filesystem::class);
    $broken->shouldReceive('exists')->andReturn(true);
    $broken->shouldReceive('get')->andThrow(new RuntimeException('m3ph clinical disk offline'));
    Storage::set(ClinicalEvidenceStorage::diskName(), $broken);

    $this->actingAs($this->supervisor)->get(route('rme.handwriting-pages.image', $page))->assertStatus(503);
});

it('never shows a rejected correction as a book page and keeps its object', function () {
    $sheet = m3phSheet();
    m3phPageOne($sheet);
    $png = m3phPng(17);
    $amendment = m3phPending($sheet, $png, 1);

    $this->actingAs($this->supervisor)->post(route('rme.amendments.reject', $amendment), ['decision_reason' => 'm3ph bukan koreksi yang tepat'])
        ->assertRedirect(route('rme.amendments.show', $amendment));

    expect(Storage::disk(ClinicalEvidenceStorage::diskName())->get($amendment->correction_page_path))->toBe($png)
        ->and(MedicalRecordHandwritingPage::query()->count())->toBe(0);

    $this->actingAs($this->supervisor)->get(route('rme.visits.print', $sheet->clinicVisit))
        ->assertOk()
        ->assertDontSee('Halaman koreksi')
        ->assertDontSee('data:image/png;base64,'.base64_encode($png), false);
    $this->actingAs($this->doctorUser)->get(route('rme.visits.medical-record.show', $sheet->clinicVisit))
        ->assertOk()
        ->assertDontSee('Halaman koreksi (amandemen');

    // The request keeps its drawing on the amendment's own page, marked as not applied.
    $this->actingAs($this->doctorUser)->get(route('rme.amendments.show', $amendment))
        ->assertOk()
        ->assertSee('data-correction-page', false)
        ->assertSee('tidak diterapkan');
});
