<?php

/*
|--------------------------------------------------------------------------
| DMS-RME-COMPREHENSIVE-CLOSURE-1 memo M3.3 PR7 — the amendment page in the oversight and cashier displays
|--------------------------------------------------------------------------
|
| Rule 240 §8.10 / §9 (security review M1, L4). A handwriting correction page
| appended by an approved amendment (rule 240 §8) is shown with its label —
| the amendment, the amended visit, the corrected book page, the approval — on
| the read-only oversight patient and visit pages (Finance Klinik, Data
| Analyst) and in the cashier's clinical summary. The oversight image route
| serves an amendment page only when its bytes match the recorded SHA-256
| (HandwritingPayloadGuard::storedPng()): 404 when missing or tampered, 503 when
| the storage read fails; an ordinary page streams exactly as before.
|
| Selected by the existing `ClinicOversight` critical-gate token in both runner
| variants and declared in critical_gate_mandatory_suites (CG-19). Helper prefix
| `m3po`.
*/

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicOversight\Interfaces\OversightClinicalRecordRepositoryInterface;
use App\Modules\ClinicOversight\Services\OversightAuditService;
use App\Modules\ClinicOversight\Services\OversightClinicalRecordService;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Models\MedicalRecordHandwriting;
use App\Modules\MedicalRecord\Models\MedicalRecordHandwritingPage;
use App\Modules\RmeAmendment\Models\RmeAmendment;
use App\Modules\RmeAmendment\Services\RmeAmendmentService;
use App\Support\Storage\ClinicalEvidenceStorage;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    seedAccessControl();
    Storage::fake(ClinicalEvidenceStorage::diskName());

    $this->branch = covBranch('AMO1', 'Cabang Amandemen');
    $this->room = ClinicRoom::factory()->create(['branch_id' => $this->branch->id, 'status' => ClinicRoom::STATUS_ACTIVE]);
    $this->patient = covPatient($this->branch);
    $this->doctorUser = userInRole('Doctor');
    $this->doctor = Doctor::factory()->create(['user_id' => $this->doctorUser->id, 'branch_id' => $this->branch->id, 'is_active' => true]);
    $this->supervisor = userInRole('Supervisor RME');

    // A final sheet (the patient's book) with its sealed page 1.
    $this->visit = ClinicVisit::factory()->create([
        'branch_id' => $this->branch->id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
        'clinic_room_id' => $this->room->id,
        'status' => ClinicVisit::STATUS_CASHIER_PENDING,
    ]);
    $this->sheet = MedicalRecord::factory()->create([
        'clinic_visit_id' => $this->visit->id,
        'branch_id' => $this->branch->id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
        'assessment' => 'm3po pulpitis',
    ]);
    $this->sheet->forceFill(['status' => MedicalRecord::STATUS_FINAL, 'finalized_at' => now()->subDay(), 'finalized_by' => $this->doctorUser->id])->save();

    $original = m3poPng(1);
    $originalKey = 'handwritings/'.$this->branch->id.'/'.$this->visit->id.'/m3po_original_p1.png';
    Storage::disk(ClinicalEvidenceStorage::diskName())->put($originalKey, $original);
    $this->pageOne = MedicalRecordHandwriting::query()->forceCreate([
        'medical_record_id' => $this->sheet->id,
        'clinic_visit_id' => $this->visit->id,
        'branch_id' => $this->branch->id,
        'handwriting_path' => $originalKey,
        'handwriting_hash' => hash('sha256', $original),
        'saved_at' => now()->subDays(2),
        'authored_clinic_visit_id' => $this->visit->id,
        'sealed_at' => now()->subDay(),
        'sealed_by_medical_record_id' => $this->sheet->id,
    ]);

    // An approved correction of page 1, appended as book page 2.
    $this->png = m3poPng(9);
    $this->amendment = app(RmeAmendmentService::class)->request(
        $this->sheet->fresh(),
        $this->doctorUser,
        'm3po koreksi halaman tulisan tangan',
        [],
        'data:image/png;base64,'.base64_encode($this->png),
        1,
    );
    app(RmeAmendmentService::class)->approve($this->amendment, $this->supervisor);
    $this->amendment = $this->amendment->fresh();
    $this->page = MedicalRecordHandwritingPage::query()->where('rme_amendment_id', $this->amendment->id)->sole();
});

function m3poPng(int $seed): string
{
    $raw = '';
    for ($y = 0; $y < 10; $y++) {
        $raw .= "\0".str_repeat(pack('C4', ($seed * 53 + $y) % 256, 40, 40, 255), 10);
    }
    $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

    return "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', 10, 10, 8, 6, 0, 0, 0)).$chunk('IDAT', gzcompress($raw)).$chunk('IEND', '');
}

function m3poLabel(): string
{
    $amendment = test()->amendment;
    $visit = test()->visit;

    return 'Halaman koreksi — amandemen #'.$amendment->id.' untuk kunjungan '.$visit->visit_number
        .' ('.$visit->visit_date->format('d/m/Y').' WITA), mengoreksi halaman buku RM 1; disetujui '
        .$amendment->decided_at->copy()->setTimezone('Asia/Makassar')->format('d/m/Y H:i').' WITA oleh '.test()->supervisor->name;
}

function m3poBrokenDisk(): void
{
    $broken = Mockery::mock(Filesystem::class);
    $broken->shouldReceive('exists')->andReturn(true);
    $broken->shouldReceive('get')->andThrow(new RuntimeException('m3po clinical disk offline'));
    $broken->shouldReceive('response')->andThrow(new RuntimeException('m3po clinical disk offline'));
    Storage::set(ClinicalEvidenceStorage::diskName(), $broken);
}

it('shows the amendment page with its label on the oversight patient and visit pages, never its key', function (string $role) {
    covActingAs(covUser($role));

    $this->get(route('oversight.rme.patients.show', $this->patient->id))
        ->assertOk()
        ->assertSee(m3poLabel())
        ->assertSee('data-amendment-page="'.$this->amendment->id.'"', false)
        ->assertSee(route('oversight.rme.handwriting-pages.image', $this->page->id), false)
        ->assertDontSee($this->amendment->correction_page_path);

    $this->get(route('oversight.rme.visits.show', $this->visit->id))
        ->assertOk()
        ->assertSee(m3poLabel())
        ->assertSee('data-amendment-page="'.$this->amendment->id.'"', false)
        ->assertDontSee($this->amendment->correction_page_path);
})->with(['Finance Klinik', 'Data Analyst']);

it('serves an amendment page on the oversight route only when it verifies: 404 tampered, 503 on a read fault', function (string $role) {
    covActingAs(covUser($role));
    $url = route('oversight.rme.handwriting-pages.image', $this->page->id);

    $response = $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($response->streamedContent())->toBe($this->png)
        ->and(AuditLog::query()->where('action', OversightAuditService::NATIVE_FILE_STREAMED)->count())->toBe(1);

    // The original page streams exactly as before.
    $this->get(route('oversight.rme.handwritings.image', $this->pageOne->id))->assertOk();

    Storage::disk(ClinicalEvidenceStorage::diskName())->put($this->amendment->correction_page_path, m3poPng(10));
    $this->get($url)->assertNotFound();

    m3poBrokenDisk();
    $this->get($url)->assertStatus(503);

    // Nothing was streamed, so nothing more was audited.
    expect(AuditLog::query()->where('action', OversightAuditService::NATIVE_FILE_STREAMED)->count())->toBe(2);
})->with(['Finance Klinik', 'Data Analyst']);

it('keeps the oversight image route out of scope for a branch the reader cannot see', function () {
    $other = covBranch('AMO2', 'Cabang Lain', rme: false);
    $this->sheet->forceFill(['branch_id' => $other->id])->save();

    covActingAs(covUser('Data Analyst'));
    $this->get(route('oversight.rme.handwriting-pages.image', $this->page->id))->assertNotFound();
});

it('shows the amendment page label in the cashier clinical summary', function () {
    $cashier = userWith(['manage_rme_billing']);

    $this->actingAs($cashier)->get(route('rme.cashier.create', $this->visit))
        ->assertOk()
        ->assertSee('RME Tulisan Tangan')
        ->assertSee(m3poLabel())
        ->assertSee('data-amendment-page="'.$this->amendment->id.'"', false)
        ->assertDontSee($this->amendment->correction_page_path);
});

it('keeps a pending and a rejected amendment out of every display; only an applied one is read for a label', function () {
    $service = app(RmeAmendmentService::class);
    $rejected = $service->request($this->sheet->fresh(), $this->doctorUser, 'm3po koreksi kedua halaman', [], 'data:image/png;base64,'.base64_encode(m3poPng(11)));
    $service->reject($rejected, $this->supervisor, 'm3po bukan koreksi yang tepat');
    $pending = $service->request($this->sheet->fresh(), $this->doctorUser, 'm3po koreksi ketiga halaman', [], 'data:image/png;base64,'.base64_encode(m3poPng(12)));

    expect(RmeAmendment::query()->findOrFail($rejected->id)->status)->toBe(RmeAmendment::STATUS_REJECTED)
        ->and(RmeAmendment::query()->findOrFail($pending->id)->status)->toBe(RmeAmendment::STATUS_PENDING)
        // No page row ever links a pending or rejected amendment: only approve()
        // appends one, in the transaction that applies it (rule 240 §8.4).
        ->and(MedicalRecordHandwritingPage::query()->whereNotNull('rme_amendment_id')->pluck('rme_amendment_id')->map(fn ($id): int => (int) $id)->all())->toBe([$this->amendment->id]);

    $responses = [];
    covActingAs(covUser('Data Analyst'));
    foreach (m3poLabelSurfaces() as $url) {
        $responses[] = $this->get($url);
    }
    $responses[] = $this->actingAs(userWith(['manage_rme_billing']))->get(route('rme.cashier.create', $this->visit));

    foreach ($responses as $response) {
        $response->assertOk()->assertSee(m3poLabel());
        foreach ([$rejected, $pending] as $other) {
            $response->assertDontSee('data-amendment-page="'.$other->id.'"', false)
                ->assertDontSee('amandemen #'.$other->id.' ')
                ->assertDontSee('amandemen #'.$other->id.')');
        }
    }

    // So the label reader's own filter is what keeps them out: asked for all
    // three, it answers the applied amendment only (rule 240 §9.1).
    expect(app(OversightClinicalRecordRepositoryInterface::class)->correctionPageAmendments([$this->amendment->id, $rejected->id, $pending->id])->keys()->all())
        ->toBe([$this->amendment->id]);
});

/**
 * Rule 240 §9.4 — a correction page appended to the book (branch AMO1) by an
 * approved amendment of a later sheet in another branch (R7-1: one book per
 * patient), approved by its own Supervisor RME. With $sheetRowInBookBranch the
 * amended sheet's own row says the book's branch while its visit is in the
 * other one (a drifted row), so the amendment row records the book's branch.
 *
 * @return array{branch: Branch, visit: ClinicVisit, amendment: RmeAmendment, page: MedicalRecordHandwritingPage, approver: User}
 */
function m3poForeignCorrection(bool $sheetRowInBookBranch = false): array
{
    $other = covBranch('AMO3', 'Cabang Amandemen Lain');
    $room = ClinicRoom::factory()->create(['branch_id' => $other->id, 'status' => ClinicRoom::STATUS_ACTIVE]);
    $doctorUser = userInRole('Doctor');
    $doctor = Doctor::factory()->create(['user_id' => $doctorUser->id, 'branch_id' => $other->id, 'is_active' => true]);
    $visit = ClinicVisit::factory()->create([
        'branch_id' => $other->id,
        'patient_id' => test()->patient->id,
        'doctor_id' => $doctor->id,
        'clinic_room_id' => $room->id,
        'status' => ClinicVisit::STATUS_COMPLETED,
        'visit_number' => 'VIS-M3PO-B-0907',
    ]);
    $sheet = MedicalRecord::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $sheetRowInBookBranch ? test()->branch->id : $other->id,
        'patient_id' => test()->patient->id,
        'doctor_id' => $doctor->id,
        'assessment' => 'm3po karies cabang lain',
    ]);
    // A later sheet anchored to the book's visit (R7-1): its pages go to the book.
    $sheet->forceFill([
        'canonical_visit_id' => test()->visit->id,
        'status' => MedicalRecord::STATUS_FINAL,
        'finalized_at' => now()->subHours(3),
        'finalized_by' => $doctorUser->id,
    ])->save();

    $approver = userInRole('Supervisor RME');
    $approver->forceFill(['name' => 'm3po Penyetuju Cabang Lain'])->save();
    $amendment = app(RmeAmendmentService::class)->request($sheet->fresh(), $doctorUser, 'm3po koreksi dari cabang lain', [], 'data:image/png;base64,'.base64_encode(m3poPng(21)));
    app(RmeAmendmentService::class)->approve($amendment, $approver->fresh());

    return [
        'branch' => $other,
        'visit' => $visit,
        'amendment' => $amendment->fresh(),
        'page' => MedicalRecordHandwritingPage::query()->where('rme_amendment_id', $amendment->id)->sole(),
        'approver' => $approver,
    ];
}

/** The oversight surfaces that render the book's labels for the book's visit. */
function m3poLabelSurfaces(): array
{
    return [
        'patient' => route('oversight.rme.patients.show', test()->patient->id),
        'visit' => route('oversight.rme.visits.show', test()->visit->id),
        'print' => route('oversight.rme.visits.print', test()->visit->id),
    ];
}

it('shows another branch\'s correction page label in full while that branch is in the oversight reader\'s set (§9.4)', function () {
    $foreign = m3poForeignCorrection();
    expect((int) $foreign['page']->medical_record_id)->toBe($this->sheet->id)
        ->and((int) $foreign['page']->authored_clinic_visit_id)->toBe($foreign['visit']->id);
    $fullLabel = $foreign['amendment']->correctionPageLabel();
    expect($fullLabel)->toContain($foreign['visit']->visit_number)->toContain($foreign['approver']->name);

    $reader = covUser('Data Analyst');
    expect(app(OversightClinicalRecordService::class)->branchIdsFor($reader))->toContain($this->branch->id)->toContain($foreign['branch']->id);
    covActingAs($reader);

    foreach (m3poLabelSurfaces() as $url) {
        $this->get($url)->assertOk()->assertSee(m3poLabel())->assertSee('data-amendment-page="'.$foreign['amendment']->id.'"', false)->assertSee($fullLabel);
    }
});

it('never names a visit, date or approver of a branch outside the oversight reader\'s set in a correction page label (§9.4)', function (bool $sheetRowInBookBranch) {
    $foreign = m3poForeignCorrection($sheetRowInBookBranch);
    expect((int) $foreign['amendment']->branch_id)->toBe($sheetRowInBookBranch ? $this->branch->id : $foreign['branch']->id);
    $marker = 'data-amendment-page="'.$foreign['amendment']->id.'"';

    // That branch leaves RME: it is outside every oversight reader's set, the book is not.
    $foreign['branch']->forceFill(['is_rme_enabled' => false])->save();
    $reader = covUser('Data Analyst');
    expect(app(OversightClinicalRecordService::class)->branchIdsFor($reader))->toContain($this->branch->id)->not->toContain($foreign['branch']->id);
    covActingAs($reader);

    foreach (m3poLabelSurfaces() as $surface => $url) {
        $response = $this->get($url)
            ->assertOk()
            ->assertSee(m3poLabel())
            ->assertSee($marker, false)
            ->assertDontSee($foreign['visit']->visit_number)
            ->assertDontSee($foreign['approver']->name)
            ->assertDontSee('amandemen #'.$foreign['amendment']->id);
        $surface === 'print'
            ? $response->assertSee($marker.'>'.OversightClinicalRecordService::NEUTRAL_CORRECTION_PAGE_LABEL.'<', false)
            : $response->assertSee('Halaman buku RM '.$foreign['page']->page_number.' · '.OversightClinicalRecordService::NEUTRAL_CORRECTION_PAGE_LABEL);
    }

    // The PDF export renders the same print body with the same reader-scoped pages.
    $pdfHtml = view('rme.visits.print-pdf', app(OversightClinicalRecordService::class)->printData($this->visit->fresh(), $reader))->render();
    expect($pdfHtml)->toContain($marker.'>'.OversightClinicalRecordService::NEUTRAL_CORRECTION_PAGE_LABEL.'<')
        ->toContain(m3poLabel())
        ->not->toContain($foreign['visit']->visit_number)
        ->not->toContain($foreign['approver']->name);

    // The page image itself stays as the R7-1 book shows it, verified.
    $this->get(route('oversight.rme.handwriting-pages.image', $foreign['page']->id))->assertOk();
})->with(['amendment of the other branch' => false, 'amended visit of the other branch, drifted sheet row' => true]);
