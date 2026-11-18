<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| DMS-RME-COMPREHENSIVE-CLOSURE-1 memo M3.1 — PR C2 (rule 243 §3)
|--------------------------------------------------------------------------
| RM page 1. Plan default 3, accepted by the owner (2026-11-17):
|  - a NEW page 1 is drawn with the allowed fields only, for every audience
|    (the canvas template baked into the saved PNG; a Front Office print of a
|    new page 1 loses the address and phone);
|  - an OLD page 1 (saved since Hotfix 60.5 with the address, phone/WhatsApp
|    and email in its pixels) is streamed to a Doctor with those biodata cells
|    blanked, server-side, in a copy (RmPageOneBiodataRedactor); every other
|    role gets the stored bytes; the stored object is never written;
|  - responses stay `private, no-store`, `nosniff`; the oversight door keeps
|    its audit row and marks a redacted read.
| Scanned legacy archive pages cannot be redacted (recorded in rule 243 §3).
|
| Selected by the existing `DoctorPatientScope` critical-gate token in both
| runner variants; declared in critical_gate_mandatory_suites. Helper prefix
| `dpor` is unique (the critical gate loads every suite into one process).
*/

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Clinic\Models\Clinic;
use App\Modules\ClinicOversight\Services\OversightAuditService;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Models\MedicalRecordHandwriting;
use App\Modules\MedicalRecord\Services\MedicalRecordHandwritingService;
use App\Modules\MedicalRecord\Services\RmPageOneBiodataRedactor;
use App\Modules\Patient\Models\Patient;
use App\Modules\Patient\Services\PatientIdentityPresenter;
use App\Modules\RmeOnlineContext\Middleware\EnsureRmeOnlineContext;
use App\Support\Storage\ClinicalEvidenceStorage;
use Carbon\Carbon;
use Database\Seeders\BranchSeeder;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

const DPOR_HIDDEN = [
    'phone' => '081200007411',
    'whatsapp' => '081299007422',
    'email' => 'dpor.pasien@example.test',
    'address' => 'Jl. Alamat Rahasia Dpor No. 8',
    'nik' => '7371015502900043',
];

/** Sample points on the 900 x 1273 template: [x, y]. */
const DPOR_HIDDEN_CELLS = [
    'marital label' => [75, 194], 'marital value' => [310, 194],
    'address label' => [75, 222], 'address value' => [310, 222],
    'religion label' => [545, 138], 'religion value' => [760, 138],
    'phone label' => [545, 166], 'phone value' => [760, 166],
    'email label' => [545, 194], 'email value' => [760, 194],
];

const DPOR_KEPT = [
    'name value' => [310, 110], 'birth value' => [310, 138], 'occupation value' => [310, 166],
    'gender value' => [760, 110], 'RM value' => [760, 222],
    'grid line x=150' => [150, 222], 'grid line y=208' => [310, 208], 'grid line x=620' => [620, 166],
    'header' => [450, 40], 'handwriting' => [450, 600],
];

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-18 02:00:00', 'UTC')); // 10:00 WITA

    test()->seed(BranchSeeder::class);
    seedAccessControl();
    Storage::fake(ClinicalEvidenceStorage::diskName());
    Storage::fake('public');

    test()->dporBranch = Branch::factory()->create(['code' => 'PO01', 'is_active' => true, 'is_rme_enabled' => true]);
    test()->dporRoom = ClinicRoom::factory()->create(['branch_id' => test()->dporBranch->id, 'status' => ClinicRoom::STATUS_ACTIVE]);
    $clinic = Clinic::factory()->create();

    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'branch_id' => test()->dporBranch->id, 'name' => 'drg. Dokter Halaman Dpor']);
    $user = User::factory()->create()->assignRole('Doctor');
    $doctor->update(['user_id' => $user->id]);
    $doctor->branches()->sync([(int) test()->dporBranch->id]);
    rmeMakeDoctorOnline($doctor, test()->dporBranch, test()->dporRoom, $user);
    test()->dporDoctor = $doctor->refresh();
    test()->dporDoctorUser = $user->refresh();

    test()->dporFrontOffice = userInRole('Front Office');
    rmeMakeFrontOfficeActive(test()->dporFrontOffice, test()->dporBranch);

    test()->dporPatient = Patient::factory()->create([
        'branch_id' => test()->dporBranch->id,
        'name' => 'Pasien Halaman Dpor',
        'medical_record_number' => 'DG-PO01-2026-00043',
        'place_of_birth' => 'Bulukumba',
        'date_of_birth' => '1988-03-04',
        'occupation' => 'Nelayan Dpor',
        'gender' => 'Male',
        'phone' => DPOR_HIDDEN['phone'],
        'whatsapp_number' => DPOR_HIDDEN['whatsapp'],
        'email' => DPOR_HIDDEN['email'],
        'address' => DPOR_HIDDEN['address'],
        'ktp_number' => DPOR_HIDDEN['nik'],
    ]);

    test()->dporVisit = ClinicVisit::factory()->create([
        'branch_id' => test()->dporBranch->id,
        'patient_id' => test()->dporPatient->id,
        'doctor_id' => test()->dporDoctor->id,
        'clinic_room_id' => test()->dporRoom->id,
        'status' => ClinicVisit::STATUS_IN_PROGRESS,
        'visit_date' => '2026-11-18',
    ])->refresh();

    test()->dporSheet = MedicalRecord::factory()->create([
        'clinic_visit_id' => test()->dporVisit->id,
        'branch_id' => test()->dporBranch->id,
        'patient_id' => test()->dporPatient->id,
        'doctor_id' => test()->dporDoctor->id,
        'canonical_visit_id' => test()->dporVisit->id,
    ])->refresh();
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * An OLD page 1 as the Hotfix 60.5 canvas saved it: white, the grid lines,
 * black "text" in every biodata cell, a header and handwriting below the band.
 * `$grid` false draws Sprint 60's grid-less template instead (7502c84d: columns
 * at x = 135 and 755, a header line at y = 80) with the same ink — handwriting
 * where the band would be. `$hiddenInk` false leaves the hidden cells blank: a
 * page 1 drawn after rule 243 §3.
 */
function dporOldPageOnePng(int $width = 900, int $height = 1273, bool $grid = true, bool $hiddenInk = true, bool $antiAliased = false): string
{
    $image = imagecreatetruecolor($width, $height);
    $white = imagecolorallocate($image, 255, 255, 255);
    $black = imagecolorallocate($image, 17, 24, 39);
    $line = $antiAliased ? imagecolorallocate($image, 136, 140, 147) : $black;
    imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, $white);
    $s = $width / 900;

    if ($grid) {
        // Grid: rows 96 + 28k, columns 0|150|470|620|900 (an anti-aliased 1 px
        // canvas stroke lands as two grey rows).
        for ($k = 0; $k <= 5; $k++) {
            $y = (int) round((96 + 28 * $k) * $s);
            imageline($image, 0, $y, $width - 1, $y, $line);
            if ($antiAliased) {
                imageline($image, 0, $y - 1, $width - 1, $y - 1, $line);
            }
        }
        foreach ([150, 470, 620] as $x) {
            imageline($image, (int) round($x * $s), (int) round(96 * $s), (int) round($x * $s), (int) round(236 * $s), $line);
        }
    } else {
        imageline($image, (int) round(135 * $s), 0, (int) round(135 * $s), $height - 1, $black);
        imageline($image, (int) round(755 * $s), 0, (int) round(755 * $s), $height - 1, $black);
        imageline($image, 0, (int) round(80 * $s), $width - 1, (int) round(80 * $s), $black);
    }

    // "Text" in every cell: a block around the cell's sample points.
    $cells = ['name value' => [310, 110], 'birth value' => [310, 138], 'occupation value' => [310, 166], 'gender value' => [760, 110], 'RM value' => [760, 222]];
    foreach ($hiddenInk ? array_merge(DPOR_HIDDEN_CELLS, $cells) : $cells as [$x, $y]) {
        imagefilledrectangle($image, (int) round(($x - 40) * $s), (int) round(($y - 5) * $s), (int) round(($x + 40) * $s), (int) round(($y + 5) * $s), $black);
    }
    imagefilledrectangle($image, (int) round(300 * $s), (int) round(30 * $s), (int) round(600 * $s), (int) round(50 * $s), $black);
    imagefilledrectangle($image, (int) round(200 * $s), (int) round(590 * $s), (int) round(700 * $s), (int) round(610 * $s), $black);

    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

function dporPageOne(string $png, bool $inline = false): MedicalRecordHandwriting
{
    $key = 'handwritings/'.test()->dporBranch->id.'/'.test()->dporVisit->id.'/dpor_page1.png';

    if (! $inline) {
        Storage::disk(ClinicalEvidenceStorage::diskName())->put($key, $png);
    }

    return MedicalRecordHandwriting::query()->forceCreate([
        'medical_record_id' => test()->dporSheet->id,
        'clinic_visit_id' => test()->dporVisit->id,
        'branch_id' => test()->dporBranch->id,
        'handwriting_path' => $inline ? 'data:image/png;base64,'.base64_encode($png) : $key,
        'handwriting_hash' => hash('sha256', $png),
        'saved_at' => now()->subDay(),
        'authored_clinic_visit_id' => test()->dporVisit->id,
    ]);
}

/** The streamed body of a StreamedResponse or a file response. */
function dporBody($response): string
{
    return $response->streamedContent();
}

/** [r, g, b] of a pixel. */
function dporPixel(string $png, int $x, int $y): array
{
    $image = imagecreatefromstring($png);
    $rgb = imagecolorat($image, $x, $y);

    return [($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF];
}

/*
|--------------------------------------------------------------------------
| 1 — an old page 1 streamed to a Doctor is redacted; other roles get the original
|--------------------------------------------------------------------------
*/

it('DPOR-01 streams an old page 1 to a Doctor with the hidden biodata cells blanked and everything else kept', function () {
    $png = dporOldPageOnePng();
    $page = dporPageOne($png);

    $response = $this->actingAs(test()->dporDoctorUser)->get(route('rme.handwritings.image', $page))->assertOk();
    $body = dporBody($response);

    expect($response->headers->get('Content-Type'))->toBe('image/png')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and((string) $response->headers->get('Cache-Control'))->toContain('no-store')
        ->and((string) $response->headers->get('Cache-Control'))->toContain('private')
        ->and($body)->not->toBe($png)
        ->and(getimagesizefromstring($body)[0])->toBe(900)
        ->and(getimagesizefromstring($body)[1])->toBe(1273);

    foreach (DPOR_HIDDEN_CELLS as $name => [$x, $y]) {
        expect(dporPixel($png, $x, $y))->toBe([17, 24, 39], "fixture {$name}")
            ->and(dporPixel($body, $x, $y))->toBe([255, 255, 255], "redacted {$name}");
    }
    foreach (DPOR_KEPT as $name => [$x, $y]) {
        expect(dporPixel($body, $x, $y))->toBe(dporPixel($png, $x, $y), "kept {$name}")
            ->and(dporPixel($body, $x, $y))->toBe([17, 24, 39], "kept {$name} is ink");
    }
});

it('DPOR-02 gives Front Office and Supervisor RME the stored bytes, and never writes the stored object', function () {
    $png = dporOldPageOnePng();
    $page = dporPageOne($png);
    $key = $page->handwriting_path;
    $supervisor = rmeClinicalAuthor();

    $this->actingAs(test()->dporDoctorUser)->get(route('rme.handwritings.image', $page))->assertOk();
    $frontOffice = dporBody($this->actingAs(test()->dporFrontOffice)->get(route('rme.handwritings.image', $page))->assertOk());
    $exempt = dporBody($this->actingAs($supervisor)->get(route('rme.handwritings.image', $page))->assertOk());

    expect($frontOffice)->toBe($png)
        ->and($exempt)->toBe($png)
        ->and(hash('sha256', (string) Storage::disk(ClinicalEvidenceStorage::diskName())->get($key)))->toBe(hash('sha256', $png))
        ->and(Storage::disk(ClinicalEvidenceStorage::diskName())->allFiles())->toBe([$key]);
});

it('DPOR-03 redacts for a Doctor who also holds Front Office, and reads a page 1 kept inline in its row the same way', function () {
    $png = dporOldPageOnePng();
    test()->dporDoctorUser->assignRole('Front Office');
    $dual = test()->dporDoctorUser->fresh();

    $stored = dporBody($this->actingAs($dual)->get(route('rme.handwritings.image', dporPageOne($png)))->assertOk());
    // A template-sized page cannot be kept inline in a varchar(255) row on
    // PostgreSQL; the inline read path is proven on the service itself.
    $inline = app(RmPageOneBiodataRedactor::class)->redactStored('data:image/png;base64,'.base64_encode($png));

    expect($inline['status'])->toBe(RmPageOneBiodataRedactor::STATUS_OK);

    foreach ([$stored, $inline['bytes']] as $body) {
        expect(dporPixel($body, 310, 222))->toBe([255, 255, 255])
            ->and(dporPixel($body, 760, 166))->toBe([255, 255, 255])
            ->and(dporPixel($body, 310, 110))->toBe([17, 24, 39]);
    }
});

it('DPOR-04 scales the band with the page and leaves an image of another shape (before the template) unchanged', function () {
    $half = (new RmPageOneBiodataRedactor)->redact(dporOldPageOnePng(450, 637));
    $sprint60 = dporOldPageOnePng(900, 1100);

    expect(getimagesizefromstring($half)[0])->toBe(450)
        ->and(dporPixel($half, 155, 111))->toBe([255, 255, 255])   // address value, scaled x0.5
        ->and(dporPixel($half, 380, 83))->toBe([255, 255, 255])    // phone value, scaled x0.5
        ->and(dporPixel($half, 155, 55))->toBe([17, 24, 39])       // name value, scaled x0.5
        ->and(dporPixel($half, 380, 111))->toBe([17, 24, 39])      // RM value, scaled x0.5
        ->and((new RmPageOneBiodataRedactor)->redact($sprint60))->toBe($sprint60);
});

it('DPOR-05 never serves a Doctor a page 1 it cannot redact (404), while Front Office still gets the stored bytes', function () {
    $corrupt = "\x89PNG\r\n\x1a\n".str_repeat('x', 64);
    $page = dporPageOne($corrupt);

    $this->actingAs(test()->dporDoctorUser)->get(route('rme.handwritings.image', $page))->assertNotFound();

    expect(dporBody($this->actingAs(test()->dporFrontOffice)->get(route('rme.handwritings.image', $page))->assertOk()))->toBe($corrupt);
});

it('DPOR-06 answers a Doctor 503 when the stored page 1 cannot be read (a fault to retry, never a missing page or the original)', function () {
    $page = dporPageOne(dporOldPageOnePng());
    $broken = Mockery::mock(Filesystem::class);
    $broken->shouldReceive('exists')->andReturn(true);
    $broken->shouldReceive('get')->andThrow(new RuntimeException('dpor clinical disk offline'));
    Storage::set(ClinicalEvidenceStorage::diskName(), $broken);

    $this->actingAs(test()->dporDoctorUser)->get(route('rme.handwritings.image', $page))->assertStatus(503);
});

it('DPOR-07 redacts on the oversight door for a Doctor who also holds an oversight role, and audits the redacted variant', function () {
    $png = dporOldPageOnePng();
    $page = dporPageOne($png);
    $analyst = userInRole('Data Analyst');
    test()->dporDoctorUser->assignRole('Data Analyst');
    $dual = test()->dporDoctorUser->fresh();
    // The RME online-context middleware is orthogonal to the oversight door.
    $this->withoutMiddleware(EnsureRmeOnlineContext::class);

    $redacted = dporBody($this->actingAs($dual)->get(route('oversight.rme.handwritings.image', $page))->assertOk());
    $dualAudit = AuditLog::query()->where('action', OversightAuditService::NATIVE_FILE_STREAMED)->latest('id')->first();
    $original = dporBody($this->actingAs($analyst)->get(route('oversight.rme.handwritings.image', $page))->assertOk());
    $analystAudit = AuditLog::query()->where('action', OversightAuditService::NATIVE_FILE_STREAMED)->latest('id')->first();

    expect(dporPixel($redacted, 310, 222))->toBe([255, 255, 255])
        ->and(dporPixel($redacted, 310, 110))->toBe([17, 24, 39])
        ->and($original)->toBe($png)
        ->and($dualAudit?->new_values['variant'] ?? null)->toBe('handwriting_redacted')
        ->and($analystAudit?->new_values['variant'] ?? null)->toBe('handwriting')
        ->and((int) $dualAudit?->id)->not->toBe((int) $analystAudit?->id);
});

/*
|--------------------------------------------------------------------------
| 2 — a NEW page 1 is drawn with the allowed fields only
|--------------------------------------------------------------------------
*/

it('DPOR-08 draws a new page 1 with the allowed fields only (the canvas template the treating Doctor saves)', function () {
    rmeSignedConsentFor(test()->dporVisit);

    $html = $this->actingAs(test()->dporDoctorUser)->get(route('rme.visits.medical-record.show', test()->dporVisit))->assertOk()->getContent();
    preg_match('/const RM_BIO = (\{.*?\});/s', $html, $m);

    expect($m)->toHaveCount(2)
        ->and(json_decode($m[1], true))->toBe([
            'left' => [['Nama', 'Pasien Halaman Dpor'], ['TTL / Umur', 'Bulukumba, 04-03-1988 / 38 tahun'], ['Pekerjaan', 'Nelayan Dpor'], ['', ''], ['', '']],
            'right' => [['Jenis Kelamin', 'Laki-laki'], ['', ''], ['', ''], ['', ''], ['No. RM', 'DG-PO01-2026-00043']],
        ])
        // The grid takes no viewer: whoever draws page 1 draws these fields.
        ->and((new ReflectionMethod(PatientIdentityPresenter::class, 'rmPageOneBiodata'))->getNumberOfParameters())->toBe(1);
});

it('DPOR-09 keeps the new page 1 blank exactly where an old one is redacted (one geometry)', function () {
    $bio = app(PatientIdentityPresenter::class)->rmPageOneBiodata(test()->dporPatient);
    $blank = [];

    foreach (['left' => [0, 1], 'right' => [2, 3]] as $side => $columns) {
        foreach ($bio[$side] as $row => [$label, $value]) {
            if ($label === '' && $value === '') {
                $blank[] = [$row, $columns[0]];
                $blank[] = [$row, $columns[1]];
            }
        }
    }

    sort($blank);
    $redacted = RmPageOneBiodataRedactor::BLANKED_CELLS;
    sort($redacted);

    expect($blank)->toBe($redacted);
});

it('DPOR-10 renders the whole RM workspace, canvas template included, to a Doctor without any contact detail or NIK', function () {
    rmeSignedConsentFor(test()->dporVisit);
    dporPageOne(dporOldPageOnePng());

    $html = $this->actingAs(test()->dporDoctorUser)->get(route('rme.visits.medical-record.show', test()->dporVisit))->assertOk()->getContent();

    expect($html)->toContain('const RM_BIO = ');

    foreach (DPOR_HIDDEN as $name => $value) {
        expect(str_contains($html, $value) || str_contains($html, e($value)))->toBeFalse($name);
    }
});

/*
|--------------------------------------------------------------------------
| 3 — review fixes: only a real grid is redacted; a doctor never saves the
|     redacted copy over the page; a failed load never saves; GD present
|--------------------------------------------------------------------------
*/

it('DPOR-11 redacts only where the Hotfix 60.5 grid is really drawn: a grid-less 900 x 1273 page keeps its ink in the band, an anti-aliased grid is redacted', function () {
    $gridless = dporOldPageOnePng(grid: false);
    $page = dporPageOne($gridless);
    $redactor = new RmPageOneBiodataRedactor;

    $body = dporBody($this->actingAs(test()->dporDoctorUser)->get(route('rme.handwritings.image', $page))->assertOk());
    $antiAliased = $redactor->redact(dporOldPageOnePng(antiAliased: true));
    $asData = fn (string $png): string => 'data:image/png;base64,'.base64_encode($png);

    expect($body)->toBe($gridless)
        ->and(dporPixel($body, 310, 222))->toBe([17, 24, 39])
        ->and(dporPixel($antiAliased, 310, 222))->toBe([255, 255, 255])
        ->and(dporPixel($antiAliased, 760, 166))->toBe([255, 255, 255])
        ->and(dporPixel($antiAliased, 310, 110))->toBe([17, 24, 39])
        // The save refusal reads the same grid: none without it, none on blank cells.
        ->and($redactor->storedPageShowsHiddenCells($asData($gridless)))->toBeFalse()
        ->and($redactor->storedPageShowsHiddenCells($asData(dporOldPageOnePng())))->toBeTrue()
        ->and($redactor->storedPageShowsHiddenCells($asData(dporOldPageOnePng(hiddenInk: false))))->toBeFalse();
});

it('DPOR-12 refuses a Doctor\'s overwrite of an old page 1 that still shows hidden fields and writes nothing; a page 1 drawn after rule 243 §3 stays editable', function () {
    rmeSignedConsentFor(test()->dporVisit);
    $old = dporOldPageOnePng();
    $page = dporPageOne($old);
    $disk = Storage::disk(ClinicalEvidenceStorage::diskName());
    $store = fn () => $this->actingAs(test()->dporDoctorUser)->post(
        route('rme.visits.medical-record.handwriting.store', [test()->dporVisit, test()->dporSheet]),
        ['handwriting_data' => 'data:image/png;base64,'.base64_encode(dporOldPageOnePng(hiddenInk: false)), 'page_number' => 1],
    );

    $store()->assertSessionHasErrors(['handwriting_data' => MedicalRecordHandwritingService::MESSAGE_PAGE_ONE_HIDDEN_FIELDS]);

    expect($disk->allFiles())->toBe([$page->handwriting_path])
        ->and(MedicalRecordHandwriting::query()->count())->toBe(1)
        ->and($page->fresh()->handwriting_path)->toBe($page->handwriting_path)
        ->and(hash('sha256', (string) $disk->get($page->handwriting_path)))->toBe(hash('sha256', $old));

    // The same page drawn after rule 243 §3 (hidden cells blank): saved.
    $disk->put($page->handwriting_path, dporOldPageOnePng(hiddenInk: false));
    $store()->assertSessionHasNoErrors()->assertRedirect();

    expect(test()->dporSheet->fresh()->latestHandwriting()?->handwriting_path)->not->toBe($page->handwriting_path)
        ->and($disk->allFiles())->toHaveCount(2);
});

it('DPOR-13 binds the refusal to the doctor audience in the service: a non-doctor author overwrites the same page 1', function () {
    $page = dporPageOne(dporOldPageOnePng());
    $service = app(MedicalRecordHandwritingService::class);
    $png = dporOldPageOnePng(hiddenInk: false);
    $target = fn () => [test()->dporSheet->fresh(), test()->dporVisit, test()->dporVisit];

    expect(fn () => $service->save($target, 1, $png, test()->dporDoctorUser->id))
        ->toThrow(ValidationException::class, MedicalRecordHandwritingService::MESSAGE_PAGE_ONE_HIDDEN_FIELDS);

    $saved = $service->save($target, 1, $png, test()->dporFrontOffice->id);

    expect($saved['page_number'])->toBe(1)
        ->and(test()->dporSheet->fresh()->latestHandwriting()?->handwriting_path)->not->toBe($page->handwriting_path);
});

it('DPOR-14 mirrors it in the workspace: the Doctor\'s page 1 is read-only with the reason and a new page stays offered; Front Office and a new-template page are unaffected', function () {
    rmeSignedConsentFor(test()->dporVisit);
    $page = dporPageOne(dporOldPageOnePng());
    $show = fn ($user) => $this->actingAs($user)->get(route('rme.visits.medical-record.show', test()->dporVisit))->assertOk();

    $doctor = $show(test()->dporDoctorUser);

    expect($doctor->viewData('activePageLockMessage'))->toBe(MedicalRecordHandwritingService::MESSAGE_PAGE_ONE_HIDDEN_FIELDS)
        ->and($doctor->viewData('editorPageNumber'))->toBe(2)
        ->and($doctor->getContent())->toContain(e(MedicalRecordHandwritingService::MESSAGE_PAGE_ONE_HIDDEN_FIELDS))
        ->and($show(test()->dporFrontOffice)->viewData('activePageLockMessage'))->toBeNull();

    Storage::disk(ClinicalEvidenceStorage::diskName())->put($page->handwriting_path, dporOldPageOnePng(hiddenInk: false));

    expect($show(test()->dporDoctorUser)->viewData('activePageLockMessage'))->toBeNull();
});

it('DPOR-15 wires the canvas never to save over a stored page whose image did not load (tests/js/rm-page-save-guard.test.mjs runs the guard)', function () {
    rmeSignedConsentFor(test()->dporVisit);
    dporPageOne(dporOldPageOnePng(hiddenInk: false));

    $html = $this->actingAs(test()->dporDoctorUser)->get(route('rme.visits.medical-record.show', test()->dporVisit))->assertOk()->getContent();

    expect($html)->toContain('function rmSaveRefusal(state)')
        ->and($html)->toContain('const refusal = rmSaveRefusal({')
        ->and($html)->toContain('baselineFailed: baselineFailed,')
        ->and(preg_match('/img\.onerror = function \(\) \{\s*baselineLoaded = false;\s*baselineFailed = true;/', $html))->toBe(1)
        ->and($html)->not->toContain('if (existingSrc && !baselineLoaded && !userDrew)');
});

it('DPOR-16 runs where GD is loaded (a doctor is refused page 1 with 503 without it): CI proves the extension', function () {
    expect(extension_loaded('gd'))->toBeTrue();
});
