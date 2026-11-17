<?php

/*
|--------------------------------------------------------------------------
| DMS-RME-COMPREHENSIVE-CLOSURE-1 memo M3.3 PR6 — the handwriting amendment page
|--------------------------------------------------------------------------
|
| Rule 240 §8 (owner decision 2026-11-17, "BUILD AMENDMENT PAGE FIRST"). A sheet
| amendment may carry ONE new handwriting correction page. The request validates
| the canvas with the handwriting decoder and bounds (rule 237 §5) plus a strict
| structure check (no polyglot), writes it to the private clinical disk under a
| fresh server key and stores the key and its SHA-256 on the amendment row, as one
| unit (rule 224: a failure in the body removes only this call's object). Approving
| verifies the object against its SHA-256 and appends it to the patient's book as
| a NEW page, sealed at once and stamped with the amendment and the amended
| encounter, inside the one approve transaction (visit → sheet → amendment → book).
| The original pages never change; the next version's snapshot lists the new page;
| a rejected or cancelled correction is kept and never becomes a book page.
|
| Selected by the existing `MedicalRecordFinalization` critical-gate token in both
| runner variants and declared in critical_gate_mandatory_suites (CG-19). Helper
| prefix `m3pg`.
*/

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\MedicalRecord\Interfaces\MedicalRecordHandwritingRepositoryInterface;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Models\MedicalRecordHandwriting;
use App\Modules\MedicalRecord\Models\MedicalRecordHandwritingPage;
use App\Modules\MedicalRecord\Services\HandwritingPayloadGuard;
use App\Modules\MedicalRecord\Services\MedicalRecordHandwritingService;
use App\Modules\MedicalRecord\Services\PatientRmWorkspaceResolver;
use App\Modules\Odontogram\Models\Odontogram;
use App\Modules\Patient\Models\Patient;
use App\Modules\RmeAmendment\Models\RmeAmendment;
use App\Modules\RmeAmendment\Models\RmeRecordVersion;
use App\Modules\RmeAmendment\Services\RmeAmendmentService;
use App\Modules\RmeAmendment\Services\RmeRecordVersionService;
use App\Support\Storage\ClinicalEvidenceStorage;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    seedAccessControl();
    Storage::fake(ClinicalEvidenceStorage::diskName());

    $this->branch = Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => true]);
    $this->room = ClinicRoom::factory()->create(['branch_id' => $this->branch->id, 'status' => ClinicRoom::STATUS_ACTIVE]);
    $this->patient = Patient::factory()->create(['branch_id' => $this->branch->id]);
    $this->doctorUser = userInRole('Doctor');
    $this->doctor = Doctor::factory()->create(['user_id' => $this->doctorUser->id, 'branch_id' => $this->branch->id, 'is_active' => true]);
    $this->supervisor = userInRole('Supervisor RME');
});

function m3pgService(): RmeAmendmentService
{
    return app(RmeAmendmentService::class);
}

/** A non-blank RGBA PNG (one colour per row), distinct per seed; optional extra chunks and a tail after IEND. */
function m3pgPng(int $seed, int $width = 10, int $height = 10, array $extraChunks = [], string $tail = ''): string
{
    $raw = '';
    for ($y = 0; $y < $height; $y++) {
        $raw .= "\0".str_repeat(pack('C4', ($seed * 41 + $y) % 256, 20, 20, 255), $width);
    }

    return m3pgAssemble($width, $height, gzcompress($raw), $extraChunks).$tail;
}

function m3pgAssemble(int $width, int $height, string $idat, array $extraChunks = []): string
{
    $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    $png = "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 6, 0, 0, 0));
    foreach ($extraChunks as $type => $data) {
        $png .= $chunk($type, $data);
    }

    return $png.$chunk('IDAT', $idat).$chunk('IEND', '');
}

/** A fully transparent canvas — the blank guard refuses it. */
function m3pgBlankPng(): string
{
    return m3pgAssemble(10, 10, gzcompress(str_repeat("\0".str_repeat(pack('C4', 0, 0, 0, 0), 10), 10)));
}

function m3pgUri(string $png): string
{
    return 'data:image/png;base64,'.base64_encode($png);
}

/** A final sheet of a finished visit — the patient's only sheet, so it is the book. */
function m3pgSheet(?User $finalizer = null): MedicalRecord
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
        'assessment' => 'm3pg pulpitis',
    ]);
    $sheet->forceFill([
        'status' => MedicalRecord::STATUS_FINAL,
        'finalized_at' => now()->subDay(),
        'finalized_by' => ($finalizer ?? test()->doctorUser)->id,
    ])->save();

    return $sheet->fresh();
}

/** A sealed page of the book with its object on the private disk. */
function m3pgSealedPage(MedicalRecord $book, int $pageNumber, int $seed): MedicalRecordHandwriting|MedicalRecordHandwritingPage
{
    $bytes = m3pgPng($seed);
    $key = 'handwritings/'.$book->branch_id.'/'.$book->clinic_visit_id.'/m3pg_original_p'.$pageNumber.'_'.$seed.'.png';
    Storage::disk(ClinicalEvidenceStorage::diskName())->put($key, $bytes);

    $attributes = [
        'medical_record_id' => $book->id,
        'clinic_visit_id' => $book->clinic_visit_id,
        'branch_id' => $book->branch_id,
        'handwriting_path' => $key,
        'handwriting_hash' => hash('sha256', $bytes),
        'saved_at' => now()->subDays(2),
        'authored_clinic_visit_id' => $book->clinic_visit_id,
        'sealed_at' => now()->subDay(),
        'sealed_by_medical_record_id' => $book->id,
    ];

    return $pageNumber === 1
        ? MedicalRecordHandwriting::query()->forceCreate($attributes)
        : MedicalRecordHandwritingPage::query()->forceCreate($attributes + ['page_number' => $pageNumber]);
}

function m3pgRequest(MedicalRecord $sheet, ?string $page, ?int $corrects = null, array $proposal = [], ?User $by = null): RmeAmendment
{
    return m3pgService()->request($sheet, $by ?? test()->doctorUser, 'm3pg koreksi tulisan tangan halaman', $proposal, $page, $corrects);
}

/** @return array<string, list<string>> */
function m3pgErrors(callable $act): array
{
    try {
        $act();
    } catch (ValidationException $e) {
        return $e->errors();
    }

    test()->fail('Expected a refusal, the action succeeded.');
}

function m3pgObjects(): array
{
    $files = Storage::disk(ClinicalEvidenceStorage::diskName())->allFiles();
    sort($files);

    return $files;
}

function m3pgAmendmentPages(): int
{
    return MedicalRecordHandwritingPage::query()->whereNotNull('rme_amendment_id')->count();
}

// ─── Request: the pending correction page ─────────────────────────────────────

it('files a sheet amendment carrying one correction page: private object under a fresh server key, key and SHA-256 on the row, the book untouched', function () {
    $sheet = m3pgSheet();
    $original = m3pgSealedPage($sheet, 1, 1);
    $before = $original->fresh()->getAttributes();
    $bytes = m3pgPng(7);

    // A page-only amendment: no field and no diagnosis changes.
    $amendment = m3pgRequest($sheet, m3pgUri($bytes), 1);

    expect($amendment->status)->toBe(RmeAmendment::STATUS_PENDING)
        ->and($amendment->proposed_content)->toBe([])
        ->and($amendment->corrects_page_number)->toBe(1)
        ->and($amendment->correction_page_sha256)->toBe(hash('sha256', $bytes))
        ->and($amendment->correction_page_path)->toStartWith('handwritings/'.$sheet->branch_id.'/'.$sheet->clinic_visit_id.'/amendment_')
        ->and($amendment->correction_page_path)->toEndWith('.png')
        ->and(Storage::disk(ClinicalEvidenceStorage::diskName())->get($amendment->correction_page_path))->toBe($bytes)
        // The key is never serialised with the row.
        ->and($amendment->toArray())->not->toHaveKey('correction_page_path');

    // Nothing in the book changed: no new page, the original row as it was.
    expect(MedicalRecordHandwritingPage::query()->count())->toBe(0)
        ->and(MedicalRecordHandwriting::query()->count())->toBe(1)
        ->and($original->fresh()->getAttributes())->toBe($before);

    // Two requests of the same drawing never share a key (fresh random suffix).
    m3pgService()->cancel($amendment, $this->doctorUser);
    $second = m3pgRequest($sheet->fresh(), m3pgUri($bytes));
    expect($second->correction_page_path)->not->toBe($amendment->correction_page_path)
        ->and($second->corrects_page_number)->toBeNull();

    $audit = AuditLog::query()->where('entity_type', RmeAmendmentService::AUDIT_ENTITY)->where('entity_id', $amendment->id)
        ->where('action', RmeAmendmentService::AUDIT_REQUESTED)->sole();
    $payload = json_encode($audit->new_values);
    expect($audit->new_values['correction_page_sha256'])->toBe(hash('sha256', $bytes))
        ->and($audit->new_values['corrects_page_number'])->toBe(1)
        ->and($payload)->not->toContain($amendment->correction_page_path)
        ->and($payload)->not->toContain(base64_encode($bytes));
});

it('refuses a canvas that is not a PNG, a polyglot, an oversized or a blank one, before anything is written', function () {
    $sheet = m3pgSheet();
    m3pgSealedPage($sheet, 1, 1);
    $objects = m3pgObjects();

    $huge = m3pgAssemble(3000, 3000, gzcompress('x'));
    $valid = m3pgPng(3);
    $badCrc = substr($valid, 0, -1).chr(ord($valid[strlen($valid) - 1]) ^ 0xFF);

    $cases = [
        'svg script' => ['data:image/png;base64,'.base64_encode('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'), HandwritingPayloadGuard::MESSAGE_INVALID],
        'gif' => ['data:image/gif;base64,'.base64_encode('GIF89a'.str_repeat("\0", 40)), HandwritingPayloadGuard::MESSAGE_INVALID],
        'not base64' => ['data:image/png;base64,@@@@', HandwritingPayloadGuard::MESSAGE_INVALID],
        'html after IEND' => [m3pgUri(m3pgPng(4, tail: '<html><script>alert(1)</script></html>')), HandwritingPayloadGuard::MESSAGE_INVALID],
        'script in a text chunk' => [m3pgUri(m3pgPng(5, extraChunks: ['tEXt' => "Comment\0<script>alert(1)</script>"])), HandwritingPayloadGuard::MESSAGE_INVALID],
        'broken chunk CRC' => [m3pgUri($badCrc), HandwritingPayloadGuard::MESSAGE_INVALID],
        'oversized dimensions' => [m3pgUri($huge), HandwritingPayloadGuard::MESSAGE_TOO_LARGE],
        'oversized payload' => [str_repeat('A', HandwritingPayloadGuard::MAX_HANDWRITING_PAYLOAD_LENGTH + 1), HandwritingPayloadGuard::MESSAGE_TOO_LARGE],
        'blank canvas' => [m3pgUri(m3pgBlankPng()), HandwritingPayloadGuard::MESSAGE_BLANK],
    ];

    foreach ($cases as $label => [$canvas, $message]) {
        $errors = m3pgErrors(fn () => m3pgRequest($sheet, $canvas, 1));
        expect(in_array($message, $errors['handwriting_data'] ?? [], true))->toBeTrue($label);
    }

    expect(RmeAmendment::query()->count())->toBe(0)
        ->and(m3pgObjects())->toBe($objects);
});

it('admits only the chunks a canvas export carries, IDAT consecutive and a 13-byte IHDR (review L1)', function () {
    $sheet = m3pgSheet();
    $objects = m3pgObjects();
    $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    $raw = '';
    for ($y = 0; $y < 10; $y++) {
        $raw .= "\0".str_repeat(pack('C4', (12 + $y) % 256, 20, 20, 255), 10);
    }
    $idat = gzcompress($raw);
    $ihdr = $chunk('IHDR', pack('NNCCCCC', 10, 10, 8, 6, 0, 0, 0));
    $split = $chunk('IDAT', substr($idat, 0, 10)).$chunk('pHYs', str_repeat("\0", 9)).$chunk('IDAT', substr($idat, 10));

    $refused = [
        'tEXT' => m3pgPng(6, extraChunks: ['tEXT' => '<script>alert(1)</script>']),
        'htMl' => m3pgPng(6, extraChunks: ['htMl' => '<html><script>alert(1)</script></html>']),
        'oversized iCCP' => m3pgPng(6, extraChunks: ['iCCP' => "icc\0\0".str_repeat('x', 70000)]),
        'IDAT split by another chunk' => "\x89PNG\r\n\x1a\n".$ihdr.$split.$chunk('IEND', ''),
    ];
    foreach ($refused as $label => $png) {
        $errors = m3pgErrors(fn () => m3pgRequest($sheet, m3pgUri($png)));
        expect(in_array(HandwritingPayloadGuard::MESSAGE_INVALID, $errors['handwriting_data'] ?? [], true))->toBeTrue($label);
    }

    // A short IHDR is a validation answer on every canvas path, never a server error (INFO).
    $shortIhdr = "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NN', 10, 10)).$chunk('IDAT', $idat).$chunk('IEND', '');
    expect(fn () => app(HandwritingPayloadGuard::class)->decodeCanvas(m3pgUri($shortIhdr)))->toThrow(ValidationException::class);
    $errors = m3pgErrors(fn () => m3pgRequest($sheet, m3pgUri($shortIhdr)));
    expect($errors['handwriting_data'] ?? [])->toContain(HandwritingPayloadGuard::MESSAGE_INVALID)
        ->and(RmeAmendment::query()->count())->toBe(0)
        ->and(m3pgObjects())->toBe($objects);

    // What a browser canvas may carry passes: colour-space and density chunks, a small iCCP, IDAT split in a row.
    $browser = "\x89PNG\r\n\x1a\n".$ihdr.$chunk('sRGB', "\0").$chunk('gAMA', pack('N', 45455)).$chunk('pHYs', pack('NNC', 2835, 2835, 1))
        .$chunk('iCCP', "icc\0\0".gzcompress(str_repeat('p', 400))).$chunk('IDAT', substr($idat, 0, 10)).$chunk('IDAT', substr($idat, 10)).$chunk('IEND', '');
    expect(m3pgRequest($sheet, m3pgUri($browser))->correction_page_sha256)->toBe(hash('sha256', $browser));
});

it('validates the corrected page against the book and refuses a correction page on a chart', function () {
    $sheet = m3pgSheet();
    m3pgSealedPage($sheet, 1, 1);
    m3pgSealedPage($sheet, 2, 2);
    $objects = m3pgObjects();

    // Page 3 does not exist; 0 is no page; a page number with no correction page is refused too.
    foreach ([3, 0, -1] as $page) {
        $errors = m3pgErrors(fn () => m3pgRequest($sheet, m3pgUri(m3pgPng(9)), $page));
        expect($errors['corrects_page_number'] ?? [])->toContain(RmeAmendmentService::MESSAGE_CORRECTS_PAGE);
    }
    $errors = m3pgErrors(fn () => m3pgRequest($sheet, null, 1, ['fields' => ['assessment' => 'm3pg pulpitis akut']]));
    expect($errors['corrects_page_number'] ?? [])->toContain(RmeAmendmentService::MESSAGE_CORRECTS_PAGE_WITHOUT_PAGE);

    // Without a correction page an amendment still has to change something.
    $errors = m3pgErrors(fn () => m3pgRequest($sheet, null));
    expect($errors['proposed'] ?? [])->toContain(RmeAmendmentService::MESSAGE_NO_CHANGE);

    $visit = ClinicVisit::factory()->create(['branch_id' => $this->branch->id, 'patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id, 'clinic_room_id' => $this->room->id, 'status' => ClinicVisit::STATUS_COMPLETED]);
    $chart = Odontogram::factory()->create(['clinic_visit_id' => $visit->id, 'branch_id' => $visit->branch_id, 'status' => Odontogram::STATUS_DRAFT, 'summary_notes' => 'm3pg ringkasan', 'tooth_map_payload' => ['teeth' => []]]);
    $chart->forceFill(['status' => Odontogram::STATUS_FINALIZED, 'finalized_at' => now()->subDay(), 'finalized_by' => $this->doctorUser->id])->save();
    $errors = m3pgErrors(fn () => m3pgService()->request($chart->fresh(), $this->doctorUser, 'm3pg koreksi odontogram', ['summary_notes' => 'm3pg ringkasan baru'], m3pgUri(m3pgPng(9))));
    expect($errors['handwriting_data'] ?? [])->toContain(RmeAmendmentService::MESSAGE_CORRECTION_PAGE_SHEET_ONLY);

    expect(RmeAmendment::query()->count())->toBe(0)
        ->and(m3pgObjects())->toBe($objects);

    // Page 2 exists: accepted.
    expect(m3pgRequest($sheet, m3pgUri(m3pgPng(9)), 2)->corrects_page_number)->toBe(2);
});

it('lets only the doctor of record or the finalizer file a correction page, writing no object for anyone else', function () {
    $sheet = m3pgSheet();
    $objects = m3pgObjects();
    $page = m3pgUri(m3pgPng(11));

    // A Super Admin who is neither, and a finalizer without the permission.
    $errors = m3pgErrors(fn () => m3pgRequest($sheet, $page, null, [], userInRole('Super Admin')));
    expect($errors['amendment'] ?? [])->toContain(RmeAmendmentService::MESSAGE_NOT_REQUESTER);
    $supervisorFinalized = m3pgSheet($this->supervisor);
    $errors = m3pgErrors(fn () => m3pgRequest($supervisorFinalized, $page, null, [], $this->supervisor));
    expect($errors['amendment'] ?? [])->toContain(RmeAmendmentService::MESSAGE_NO_REQUEST_PERMISSION);

    // A doctor who never treated the patient may not even see the record.
    $stranger = userInRole('Doctor');
    Doctor::factory()->create(['user_id' => $stranger->id, 'branch_id' => $this->branch->id, 'is_active' => true]);
    $refused = false;
    try {
        m3pgRequest($sheet, $page, null, [], $stranger);
    } catch (Throwable) {
        $refused = true;
    }

    expect($refused)->toBeTrue()
        ->and(RmeAmendment::query()->count())->toBe(0)
        ->and(m3pgObjects())->toBe($objects);

    // The doctor of record, and a finalizer holding the permission, may.
    expect(m3pgRequest($sheet, $page)->status)->toBe(RmeAmendment::STATUS_PENDING);
    $finalizer = userInRole('Supervisor RME');
    $finalizer->givePermissionTo(RmeAmendmentService::PERMISSION_REQUEST);
    expect(m3pgRequest(m3pgSheet($finalizer), $page, null, [], $finalizer->fresh())->status)->toBe(RmeAmendment::STATUS_PENDING);
});

// ─── Approve: append a sealed amendment page ──────────────────────────────────

it('appends the correction as a new sealed page of the book on approval; the original pages never change', function () {
    $sheet = m3pgSheet();
    $pageOne = m3pgSealedPage($sheet, 1, 1);
    $pageTwo = m3pgSealedPage($sheet, 2, 2);
    $disk = Storage::disk(ClinicalEvidenceStorage::diskName());
    $rowsBefore = [$pageOne->fresh()->getAttributes(), $pageTwo->fresh()->getAttributes()];
    $bytesBefore = [$disk->get($pageOne->handwriting_path), $disk->get($pageTwo->handwriting_path)];
    $bytes = m3pgPng(21);

    $amendment = m3pgRequest($sheet, m3pgUri($bytes), 1, ['fields' => ['assessment' => 'm3pg pulpitis ireversibel']]);
    $applied = m3pgService()->approve($amendment, $this->supervisor, 'm3pg sesuai');

    $page = MedicalRecordHandwritingPage::query()->where('rme_amendment_id', $amendment->id)->sole();
    expect($applied->status)->toBe(RmeAmendment::STATUS_APPLIED)
        ->and($page->medical_record_id)->toBe($sheet->id)
        ->and($page->page_number)->toBe(3)
        ->and($page->clinic_visit_id)->toBe($sheet->clinic_visit_id)
        ->and((int) $page->authored_clinic_visit_id)->toBe($sheet->clinic_visit_id)
        ->and($page->sealed_at)->not->toBeNull()
        ->and((int) $page->sealed_by_medical_record_id)->toBe($sheet->id)
        ->and($page->handwriting_path)->toBe($amendment->correction_page_path)
        ->and($page->handwriting_hash)->toBe(hash('sha256', $bytes))
        ->and($disk->get($page->handwriting_path))->toBe($bytes)
        ->and($sheet->fresh()->assessment)->toBe('m3pg pulpitis ireversibel')
        ->and($sheet->fresh()->status)->toBe(MedicalRecord::STATUS_FINAL);

    // The original pages: rows and bytes exactly as before.
    expect([$pageOne->fresh()->getAttributes(), $pageTwo->fresh()->getAttributes()])->toBe($rowsBefore)
        ->and([$disk->get($pageOne->handwriting_path), $disk->get($pageTwo->handwriting_path)])->toBe($bytesBefore);

    // Sealed at once: no encounter may overwrite it, the live one included.
    $repository = app(MedicalRecordHandwritingRepositoryInterface::class);
    expect($repository->pageSlotLock($sheet->id, 3, $sheet->patient_id, $sheet->clinic_visit_id))->toBe(MedicalRecordHandwritingRepositoryInterface::LOCK_SEALED);
    $visit = $sheet->clinicVisit;
    $errors = m3pgErrors(fn () => app(MedicalRecordHandwritingService::class)->save(fn () => [$sheet->fresh(), $visit, $visit], 3, m3pgPng(22), $this->doctorUser->id));
    expect($errors['handwriting_data'] ?? [])->toContain(MedicalRecordHandwritingService::MESSAGE_SEALED_PAGE)
        ->and($page->fresh()->handwriting_path)->toBe($amendment->correction_page_path);

    // Version 2 lists the new page with its key and SHA-256, chained and verifiable.
    $version = RmeRecordVersion::query()->where('subject_type', RmeRecordVersion::SUBJECT_MEDICAL_RECORD)->where('subject_id', $sheet->id)->where('version', 2)->sole();
    $listed = collect($version->snapshot['pages'])->firstWhere('rme_amendment_id', $amendment->id);
    expect($listed)->not->toBeNull()
        ->and($listed['page_number'])->toBe(3)
        ->and($listed['object_key'])->toBe($amendment->correction_page_path)
        ->and($listed['sha256'])->toBe(hash('sha256', $bytes))
        ->and(app(RmeRecordVersionService::class)->verifyChain($sheet->fresh()))->toBeTrue();
    // Version 1 (the baseline) does not list it.
    $baseline = RmeRecordVersion::query()->where('subject_type', RmeRecordVersion::SUBJECT_MEDICAL_RECORD)->where('subject_id', $sheet->id)->where('version', 1)->sole();
    expect(collect($baseline->snapshot['pages'])->pluck('page_number')->all())->toBe([1, 2]);

    $audit = AuditLog::query()->where('entity_type', RmeAmendmentService::AUDIT_ENTITY)->where('entity_id', $amendment->id)
        ->where('action', RmeAmendmentService::AUDIT_APPLIED)->sole();
    expect($audit->new_values['correction_page_id'])->toBe($page->id)
        ->and($audit->new_values['correction_page_number'])->toBe(3)
        ->and($audit->new_values['correction_page_medical_record_id'])->toBe($sheet->id)
        ->and($audit->new_values['correction_page_sha256'])->toBe(hash('sha256', $bytes))
        ->and(json_encode($audit->new_values))->not->toContain($amendment->correction_page_path);
});

it('applies a page-only amendment without touching the sheet\'s text or diagnoses', function () {
    $sheet = m3pgSheet();
    $fields = $sheet->only(['subjective', 'objective', 'assessment', 'plan', 'notes']);
    $amendment = m3pgRequest($sheet, m3pgUri(m3pgPng(31)));

    m3pgService()->approve($amendment, $this->supervisor);

    // The sheet has no page yet: the book's next page is 2 (page 1 is the legacy slot).
    $page = MedicalRecordHandwritingPage::query()->where('rme_amendment_id', $amendment->id)->sole();
    expect($page->page_number)->toBe(2)
        ->and($sheet->fresh()->only(array_keys($fields)))->toBe($fields)
        ->and(app(RmeRecordVersionService::class)->amendmentCount($sheet->fresh()))->toBe(1)
        ->and(app(RmeRecordVersionService::class)->verifyChain($sheet->fresh()))->toBeTrue();
});

it('appends to the patient\'s book when the amended sheet is a later sheet, stamped with the amended encounter', function () {
    $book = m3pgSheet();
    m3pgSealedPage($book, 1, 1);
    $later = m3pgSheet();
    $later->forceFill(['canonical_visit_id' => $book->clinic_visit_id])->save();
    expect(app(PatientRmWorkspaceResolver::class)->canonicalMedicalRecord($this->patient->id)?->id)->toBe($book->id);

    // R7-1 — the later encounter wrote its page 2 onto the book; that is the page it may correct.
    $own = m3pgSealedPage($book, 2, 2);
    $own->forceFill(['authored_clinic_visit_id' => $later->clinic_visit_id])->save();

    $amendment = m3pgRequest($later->fresh(), m3pgUri(m3pgPng(41)), 2);
    m3pgService()->approve($amendment, $this->supervisor);

    $page = MedicalRecordHandwritingPage::query()->where('rme_amendment_id', $amendment->id)->sole();
    expect($page->medical_record_id)->toBe($book->id)
        ->and($page->clinic_visit_id)->toBe($book->clinic_visit_id)
        ->and((int) $page->authored_clinic_visit_id)->toBe($later->clinic_visit_id)
        ->and((int) $page->sealed_by_medical_record_id)->toBe($later->id)
        ->and($page->page_number)->toBe(3);
});

// ─── Security review M2 — the corrected page belongs to the amended encounter ─

it('lets a correction name only a page of the amended encounter: in the form, at request and at approval (review M2)', function () {
    $book = m3pgSheet();
    $later = m3pgSheet();
    $later->forceFill(['canonical_visit_id' => $book->clinic_visit_id])->save();
    $later = $later->fresh();
    $bookPage = m3pgSealedPage($book, 1, 1);           // written by the book's encounter (doctor A)
    $ownPage = m3pgSealedPage($book, 2, 2);            // written by the later encounter
    $ownPage->forceFill(['authored_clinic_visit_id' => $later->clinic_visit_id])->save();
    $preRuleOwn = m3pgSealedPage($book, 3, 3);         // unstamped, sealed by the later sheet
    $preRuleOwn->forceFill(['authored_clinic_visit_id' => null, 'sealed_by_medical_record_id' => $later->id])->save();
    $preRuleOther = m3pgSealedPage($book, 4, 4);       // unstamped, sealed by the book's sheet
    $preRuleOther->forceFill(['authored_clinic_visit_id' => null])->save();

    // The form offers only the later encounter's pages.
    expect(collect(m3pgService()->requestFormFor($later)['book_pages'])->pluck('number')->all())->toBe([2, 3]);

    foreach ([1, 4] as $foreign) {
        $errors = m3pgErrors(fn () => m3pgRequest($later, m3pgUri(m3pgPng(45)), $foreign));
        expect($errors['corrects_page_number'] ?? [])->toContain(RmeAmendmentService::MESSAGE_CORRECTS_PAGE_ENCOUNTER);
    }
    expect(RmeAmendment::query()->count())->toBe(0);

    // Re-decided at approval: the page no longer belongs to the amended encounter.
    $amendment = m3pgRequest($later, m3pgUri(m3pgPng(46)), 2);
    DB::table('trx_medical_record_handwriting_pages')->where('id', $ownPage->id)->update(['authored_clinic_visit_id' => $book->clinic_visit_id]);
    $errors = m3pgErrors(fn () => m3pgService()->approve($amendment, $this->supervisor));
    expect($errors['amendment'] ?? [])->toContain(RmeAmendmentService::MESSAGE_PROPOSAL_CHANGED)
        ->and(m3pgAmendmentPages())->toBe(0);

    m3pgService()->cancel($amendment, $this->doctorUser);
    expect(m3pgRequest($later->fresh(), m3pgUri(m3pgPng(47)), 3)->corrects_page_number)->toBe(3)
        ->and($bookPage->fresh()->handwriting_path)->not->toBeNull();
});

it('tells a storage read error apart from tampering when applying (review L2)', function () {
    $sheet = m3pgSheet();
    $amendment = m3pgRequest($sheet, m3pgUri(m3pgPng(57)));

    $broken = Mockery::mock(Filesystem::class);
    $broken->shouldReceive('exists')->andReturn(true);
    $broken->shouldReceive('get')->andThrow(new RuntimeException('m3pg clinical disk offline'));
    Storage::set(ClinicalEvidenceStorage::diskName(), $broken);

    $errors = m3pgErrors(fn () => m3pgService()->approve($amendment, $this->supervisor));
    expect($errors['amendment'] ?? [])->toContain(RmeAmendmentService::MESSAGE_CORRECTION_PAGE_UNREADABLE)
        ->and($errors['amendment'] ?? [])->not->toContain(RmeAmendmentService::MESSAGE_CORRECTION_PAGE_INTEGRITY)
        ->and(RmeAmendmentService::MESSAGE_CORRECTION_PAGE_UNREADABLE)->not->toContain('amandemen baru')
        ->and(HandwritingPayloadGuard::storedPng($amendment->correction_page_path, $amendment->correction_page_sha256)['status'])->toBe(HandwritingPayloadGuard::STORED_UNREADABLE)
        ->and($amendment->fresh()->status)->toBe(RmeAmendment::STATUS_PENDING)
        ->and(m3pgAmendmentPages())->toBe(0);
});

it('refuses to apply a tampered or missing correction object, writing nothing', function () {
    $sheet = m3pgSheet();
    $disk = Storage::disk(ClinicalEvidenceStorage::diskName());

    $tampered = m3pgRequest($sheet, m3pgUri(m3pgPng(51)), null, ['fields' => ['assessment' => 'm3pg pulpitis akut']]);
    $disk->put($tampered->correction_page_path, m3pgPng(52));

    $errors = m3pgErrors(fn () => m3pgService()->approve($tampered, $this->supervisor));
    expect($errors['amendment'] ?? [])->toContain(RmeAmendmentService::MESSAGE_CORRECTION_PAGE_INTEGRITY)
        ->and($tampered->fresh()->status)->toBe(RmeAmendment::STATUS_PENDING)
        ->and($sheet->fresh()->assessment)->toBe('m3pg pulpitis')
        ->and(m3pgAmendmentPages())->toBe(0)
        ->and(RmeRecordVersion::query()->where('version', 2)->count())->toBe(0);

    m3pgService()->cancel($tampered, $this->doctorUser);
    $missing = m3pgRequest($sheet->fresh(), m3pgUri(m3pgPng(53)));
    $disk->delete($missing->correction_page_path);
    $errors = m3pgErrors(fn () => m3pgService()->approve($missing, $this->supervisor));
    expect($errors['amendment'] ?? [])->toContain(RmeAmendmentService::MESSAGE_CORRECTION_PAGE_INTEGRITY)
        ->and(m3pgAmendmentPages())->toBe(0);
});

it('refuses to append a page onto a broken version chain, writing nothing (rule 240 §5.7)', function () {
    $sheet = m3pgSheet();
    $amendment = m3pgRequest($sheet, m3pgUri(m3pgPng(55)));

    // Tamper with version 1's stored text, bypassing the model and, on PostgreSQL, the trigger.
    $pg = DB::connection()->getDriverName() === 'pgsql';
    if ($pg) {
        DB::statement('ALTER TABLE trx_rme_record_versions DISABLE TRIGGER USER');
    }
    try {
        DB::table('trx_rme_record_versions')->where('subject_type', RmeRecordVersion::SUBJECT_MEDICAL_RECORD)
            ->where('subject_id', $sheet->id)->where('version', 1)
            ->update(['snapshot' => json_encode(['tampered' => 'm3pg isi diubah diam-diam'])]);
    } finally {
        if ($pg) {
            DB::statement('ALTER TABLE trx_rme_record_versions ENABLE TRIGGER USER');
        }
    }

    $errors = m3pgErrors(fn () => m3pgService()->approve($amendment, $this->supervisor));
    expect($errors['amendment'] ?? [])->toContain(RmeAmendmentService::MESSAGE_CHAIN_BROKEN)
        ->and($amendment->fresh()->status)->toBe(RmeAmendment::STATUS_PENDING)
        ->and(m3pgAmendmentPages())->toBe(0)
        ->and(RmeRecordVersion::query()->where('subject_id', $sheet->id)->count())->toBe(1);
});

it('refuses the requester, the doctor of record, the finalizer and Super Admin on their own request as checkers, appending nothing', function () {
    $sheet = m3pgSheet();
    $amendment = m3pgRequest($sheet, m3pgUri(m3pgPng(61)));

    $this->doctorUser->givePermissionTo(RmeAmendmentService::PERMISSION_APPROVE);
    $errors = m3pgErrors(fn () => m3pgService()->approve($amendment, $this->doctorUser->fresh()));
    expect($errors['amendment'] ?? [])->toContain(RmeAmendmentService::MESSAGE_SELF_DECISION);

    $finalizedBySupervisor = m3pgSheet($this->supervisor);
    $own = m3pgRequest($finalizedBySupervisor, m3pgUri(m3pgPng(62)));
    $errors = m3pgErrors(fn () => m3pgService()->approve($own, $this->supervisor));
    expect($errors['amendment'] ?? [])->toContain(RmeAmendmentService::MESSAGE_FINALIZER);

    $superAdmin = userInRole('Super Admin');
    $superOwn = m3pgRequest(m3pgSheet($superAdmin), m3pgUri(m3pgPng(63)), null, [], $superAdmin);
    $errors = m3pgErrors(fn () => m3pgService()->approve($superOwn, $superAdmin));
    expect($errors['amendment'] ?? [])->toContain(RmeAmendmentService::MESSAGE_SELF_DECISION)
        ->and(m3pgAmendmentPages())->toBe(0);

    // Super Admin who is none of them applies.
    m3pgService()->approve($amendment, $superAdmin);
    expect(m3pgAmendmentPages())->toBe(1);
});

// ─── Concurrency (stale copies; the decision is on the locked row) ────────────

it('appends one page for two approvals, none after a cancel or a reject, and keeps the refused object', function () {
    $sheet = m3pgSheet();
    $disk = Storage::disk(ClinicalEvidenceStorage::diskName());

    $amendment = m3pgRequest($sheet, m3pgUri(m3pgPng(71)));
    $stale = RmeAmendment::query()->findOrFail($amendment->id);
    m3pgService()->approve($amendment, $this->supervisor);
    $errors = m3pgErrors(fn () => m3pgService()->approve($stale, userInRole('Supervisor RME')));
    expect($errors['amendment'] ?? [])->toContain(RmeAmendmentService::MESSAGE_NOT_PENDING)
        ->and(m3pgAmendmentPages())->toBe(1);

    $cancelled = m3pgRequest($sheet->fresh(), m3pgUri(m3pgPng(72)));
    $staleCancelled = RmeAmendment::query()->findOrFail($cancelled->id);
    m3pgService()->cancel($cancelled, $this->doctorUser);
    $errors = m3pgErrors(fn () => m3pgService()->approve($staleCancelled, $this->supervisor));
    expect($errors['amendment'] ?? [])->toContain(RmeAmendmentService::MESSAGE_NOT_PENDING)
        ->and(m3pgAmendmentPages())->toBe(1)
        ->and($disk->exists($cancelled->correction_page_path))->toBeTrue();

    $rejected = m3pgRequest($sheet->fresh(), m3pgUri(m3pgPng(73)));
    $staleRejected = RmeAmendment::query()->findOrFail($rejected->id);
    m3pgService()->reject($rejected, $this->supervisor, 'm3pg bukan koreksi yang tepat');
    $errors = m3pgErrors(fn () => m3pgService()->approve($staleRejected, $this->supervisor));
    expect($errors['amendment'] ?? [])->toContain(RmeAmendmentService::MESSAGE_NOT_PENDING)
        ->and(m3pgAmendmentPages())->toBe(1)
        ->and($disk->exists($rejected->correction_page_path))->toBeTrue();

    // Neither the rejected nor the cancelled drawing is a page of the book.
    $book = app(PatientRmWorkspaceResolver::class)->orderedHandwritingBookForPatient($this->patient->id);
    expect($book->where('has_content', true)->count())->toBe(1);
});

it('refuses a second pending request before writing its object, and a raced one inside a savepoint, removing only that call\'s object', function () {
    $sheet = m3pgSheet();
    m3pgSealedPage($sheet, 1, 1);
    m3pgRequest($sheet, m3pgUri(m3pgPng(81)));
    $objects = m3pgObjects();

    $errors = m3pgErrors(fn () => m3pgRequest($sheet->fresh(), m3pgUri(m3pgPng(82))));
    expect($errors['amendment'] ?? [])->toContain(RmeAmendmentService::MESSAGE_PENDING_EXISTS)
        ->and(m3pgObjects())->toBe($objects);

    // A competing pending row committed between the check and the insert: the
    // partial unique index refuses it inside the savepoint; this call's object goes.
    $other = m3pgSheet();
    $objects = m3pgObjects();
    $fired = false;
    RmeAmendment::creating(function (RmeAmendment $row) use (&$fired): void {
        if ($fired) {
            return;
        }
        $fired = true;
        DB::table('trx_rme_amendments')->insert([
            'subject_type' => $row->subject_type,
            'subject_id' => $row->subject_id,
            'branch_id' => $row->branch_id,
            'clinic_visit_id' => $row->clinic_visit_id,
            'base_version_id' => $row->base_version_id,
            'base_version' => $row->base_version,
            'status' => RmeAmendment::STATUS_PENDING,
            'reason' => 'm3pg permintaan bersaing',
            'proposed_content' => '{"fields":{"notes":"m3pg"}}',
            'requested_by' => $row->requested_by,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $errors = m3pgErrors(fn () => m3pgRequest($other, m3pgUri(m3pgPng(83))));
    expect($errors['amendment'] ?? [])->toContain(RmeAmendmentService::MESSAGE_PENDING_EXISTS)
        ->and(m3pgObjects())->toBe($objects)
        ->and(RmeAmendment::query()->where('subject_id', $other->id)->count())->toBe(0);
});

it('removes only this call\'s uncommitted object when the request fails after writing it (rule 224)', function () {
    $sheet = m3pgSheet();
    $original = m3pgSealedPage($sheet, 1, 1);
    $objects = m3pgObjects();

    RmeAmendment::creating(function (): void {
        throw new RuntimeException('m3pg failure after the object was written');
    });

    expect(fn () => m3pgRequest($sheet, m3pgUri(m3pgPng(91)), 1))->toThrow(RuntimeException::class, 'm3pg failure');
    expect(m3pgObjects())->toBe($objects)
        ->and(Storage::disk(ClinicalEvidenceStorage::diskName())->exists($original->handwriting_path))->toBeTrue()
        ->and(RmeAmendment::query()->count())->toBe(0);
});

// ─── Display (the model the workspace and the print read) ─────────────────────

it('labels the applied page for the workspace and the print, and prints only verified bytes', function () {
    $sheet = m3pgSheet();
    $bytes = m3pgPng(101);
    $amendment = m3pgRequest($sheet, m3pgUri($bytes));
    expect($sheet->fresh()->correctionPagePointers())->toBe([]);
    m3pgService()->approve($amendment, $this->supervisor);
    $applied = $amendment->fresh();

    $entry = $sheet->fresh()->orderedHandwritingPages(true)->firstWhere('page_number', 2);
    $visit = $sheet->clinicVisit;
    // Review M3 — the label links back to the amended encounter (and the corrected page, when named).
    $label = 'Halaman koreksi — amandemen #'.$amendment->id.' untuk kunjungan '.$visit->visit_number
        .' ('.$visit->visit_date->format('d/m/Y').' WITA); disetujui '
        .$applied->decided_at->copy()->setTimezone('Asia/Makassar')->format('d/m/Y H:i').' WITA oleh '.$this->supervisor->name;
    expect($entry['amendment_label'])->toBe($label)
        ->and($sheet->fresh()->correctionPagePointers())->toBe(['Halaman koreksi: buku RM halaman 2 (amandemen #'.$amendment->id.')'])
        ->and($entry['amendment_id'])->toBe($amendment->id)
        ->and($entry['preview_url'])->toBe(m3pgUri($bytes));

    Storage::disk(ClinicalEvidenceStorage::diskName())->put($applied->correction_page_path, m3pgPng(102));
    $entry = $sheet->fresh()->orderedHandwritingPages(true)->firstWhere('page_number', 2);
    expect($entry['preview_url'])->toBeNull()
        ->and($entry['has_content'])->toBeTrue();

    // With a named corrected page the label says which one (book numbering).
    m3pgSealedPage($sheet, 3, 3);
    $second = m3pgRequest($sheet->fresh(), m3pgUri(m3pgPng(103)), 3);
    m3pgService()->approve($second, userInRole('Supervisor RME'));
    expect($second->fresh()->load('decider', 'clinicVisit')->correctionPageLabel())->toContain(', mengoreksi halaman buku RM 3; disetujui ');
});

// ─── Immutability and constraints ─────────────────────────────────────────────

it('keeps the correction columns immutable through the model', function () {
    $amendment = m3pgRequest(m3pgSheet(), m3pgUri(m3pgPng(111)), null);

    foreach (['correction_page_path' => 'handwritings/x.png', 'correction_page_sha256' => str_repeat('b', 64), 'corrects_page_number' => 2] as $column => $value) {
        $copy = RmeAmendment::query()->findOrFail($amendment->id);
        $copy->forceFill([$column => $value]);
        expect(fn () => $copy->save())->toThrow(LogicException::class);
    }
});

it('refuses a second page for one amendment (unique link)', function () {
    $sheet = m3pgSheet();
    $amendment = m3pgRequest($sheet, m3pgUri(m3pgPng(121)));
    m3pgService()->approve($amendment, $this->supervisor);
    $page = MedicalRecordHandwritingPage::query()->where('rme_amendment_id', $amendment->id)->sole();

    expect(fn () => DB::table('trx_medical_record_handwriting_pages')->insert([
        'medical_record_id' => $sheet->id,
        'clinic_visit_id' => $sheet->clinic_visit_id,
        'branch_id' => $sheet->branch_id,
        'page_number' => $page->page_number + 1,
        'handwriting_path' => 'handwritings/m3pg-copy.png',
        'handwriting_hash' => str_repeat('c', 64),
        'rme_amendment_id' => $amendment->id,
        'sealed_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('refuses on PostgreSQL a raw change of the correction columns, a malformed SHA-256 and an unsealed amendment page', function () {
    $sheet = m3pgSheet();
    $amendment = m3pgRequest($sheet, m3pgUri(m3pgPng(131)));

    foreach (["correction_page_sha256 = '".str_repeat('d', 64)."'", "correction_page_path = 'handwritings/x.png'", 'corrects_page_number = 1'] as $set) {
        expect(fn () => DB::transaction(fn () => DB::statement("UPDATE trx_rme_amendments SET {$set} WHERE id = ?", [$amendment->id])))
            ->toThrow(QueryException::class);
    }

    $constraints = collect(DB::select("SELECT conname FROM pg_constraint WHERE conname LIKE 'trx_rme_amd_correction%' OR conname LIKE 'trx_mrhp_rme_amendment%'"))->pluck('conname')->sort()->values()->all();
    expect($constraints)->toBe(['trx_mrhp_rme_amendment_fk', 'trx_mrhp_rme_amendment_sealed_chk', 'trx_mrhp_rme_amendment_uq', 'trx_rme_amd_correction_page_chk', 'trx_rme_amd_correction_subject_chk']);

    m3pgService()->cancel($amendment, $this->doctorUser);
    $base = RmeRecordVersion::query()->where('subject_id', $sheet->id)->firstOrFail();
    expect(fn () => DB::transaction(fn () => DB::table('trx_rme_amendments')->insert([
        'subject_type' => 'medical_record', 'subject_id' => $sheet->id, 'branch_id' => $sheet->branch_id, 'clinic_visit_id' => $sheet->clinic_visit_id,
        'base_version_id' => $base->id, 'base_version' => 1, 'status' => 'pending', 'reason' => 'm3pg sha tidak sah',
        'proposed_content' => '[]', 'requested_by' => $this->doctorUser->id,
        'correction_page_path' => 'handwritings/x.png', 'correction_page_sha256' => 'not-a-sha',
        'created_at' => now(), 'updated_at' => now(),
    ])))->toThrow(QueryException::class);

    expect(fn () => DB::transaction(fn () => DB::table('trx_medical_record_handwriting_pages')->insert([
        'medical_record_id' => $sheet->id, 'clinic_visit_id' => $sheet->clinic_visit_id, 'branch_id' => $sheet->branch_id,
        'page_number' => 9, 'handwriting_path' => 'handwritings/m3pg-unsealed.png', 'handwriting_hash' => str_repeat('e', 64),
        'rme_amendment_id' => $amendment->id, 'created_at' => now(), 'updated_at' => now(),
    ])))->toThrow(QueryException::class);
})->skip(fn () => DB::connection()->getDriverName() !== 'pgsql', 'PostgreSQL trigger and CHECK constraints');

it('locks the visit, the sheet, the amendment and the book before appending the page and writing the version (PostgreSQL)', function () {
    $sheet = m3pgSheet();
    $amendment = m3pgRequest($sheet, m3pgUri(m3pgPng(141)));
    $sql = [];
    Event::listen(QueryExecuted::class, function (QueryExecuted $q) use (&$sql): void {
        $sql[] = strtolower($q->sql);
    });

    m3pgService()->approve($amendment, $this->supervisor);

    $at = fn (callable $match, int $from = 0): int|false => collect($sql)->search(fn ($s, $i) => $i >= $from && $match($s));
    $visit = $at(fn ($s) => str_contains($s, 'from "trx_clinic_visits"') && str_contains($s, 'for no key update'));
    $record = $at(fn ($s) => str_contains($s, 'from "trx_medical_records"') && str_contains($s, 'for no key update'));
    $row = $at(fn ($s) => str_contains($s, 'from "trx_rme_amendments"') && str_contains($s, 'for update'));
    $book = $row === false ? false : $at(fn ($s) => str_contains($s, 'from "trx_medical_records"') && str_contains($s, 'for no key update'), $row);
    $page = $at(fn ($s) => str_starts_with($s, 'insert into "trx_medical_record_handwriting_pages"'));
    $version = $at(fn ($s) => str_starts_with($s, 'insert into "trx_rme_record_versions"'));

    expect($visit)->toBeInt()
        ->and($record)->toBeInt()
        ->and($row)->toBeInt()
        ->and($book)->toBeInt()
        ->and($page)->toBeInt()
        ->and($version)->toBeInt()
        ->and($visit)->toBeLessThan($record)
        ->and($record)->toBeLessThan($row)
        ->and($row)->toBeLessThan($book)
        ->and($book)->toBeLessThan($page)
        ->and($page)->toBeLessThan($version);
})->skip(fn () => DB::connection()->getDriverName() !== 'pgsql', 'PostgreSQL row locks');
