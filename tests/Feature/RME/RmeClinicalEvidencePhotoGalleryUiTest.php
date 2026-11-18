<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| DMS memo M4.4 / M1.x, PTM PR5 (rule 242 §10) — clinical photos: the UI.
|--------------------------------------------------------------------------
|
| The "Foto Klinis" card on Detail Kunjungan (`rme.visits.show`). Everything it
| shows is decided in the controller through ClinicalPhotoGalleryPresenter,
| which asks RmeClinicalPhotoService (CG-04: no service call and no decision in
| Blade):
|
|  - flag OFF: no markup and no photo route at all, for every role;
|  - the upload control only when uploadRefusal() is null; an account that may
|    upload here at all but is refused by the encounter state (PTM awaiting the
|    doctor, documentation TIDAK, a finished visit) sees the server's reason and
|    a disabled control, never a form the server would refuse (rule 135);
|  - the gallery lists live photos only, thumbnails through the private image
|    route (lazy, never base64, never a storage URL), uploader and WITA time;
|  - the "Foto dibatalkan" list only for void-permission holders, with who and
|    when, and the reason (rule 242 §7: read deliberately for holders only);
|  - the void form (with a confirm step) only for holders, never on a photo the
|    viewer uploaded (maker ≠ checker mirrored from voidAccountRefusal());
|  - the read scope (roles and the doctor-patient scope) still applies;
|  - a constant number of queries however many photos.
|
| Selected by the existing `ClinicalEvidence` critical-gate token in both runner
| variants and declared in critical_gate_mandatory_suites (CG-19). Helper prefix
| `rcpg` is unique because the critical gate loads every suite into one process.
*/

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicalPhoto\Models\RmeClinicalPhoto;
use App\Modules\ClinicalPhoto\Services\RmeClinicalPhotoService;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Consent\Services\RmeVisitConsentService;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\Patient\Models\Patient;
use App\Modules\RME\Services\DoctorPatientScopeService;
use App\Modules\RmeOnlineContext\Services\DoctorUserResolver;
use App\Support\Storage\ClinicalEvidenceStorage;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

const RCPG_FLAG = 'rme.clinical_photos';
const RCPG_REASON = 'Foto tertukar dengan pasien lain, alasan rahasia RCPG.';

beforeEach(function () {
    seedAccessControl();
    Storage::fake('local');
    Storage::fake(ClinicalEvidenceStorage::diskName());

    // Pinned clinic clock: 10:30 WITA (02:30 UTC) on 2026-11-18.
    rcpgClock('2026-11-18 10:30:00');

    $this->branch = Branch::factory()->create(['code' => 'CPG1', 'is_active' => true, 'is_rme_enabled' => true]);
    $this->room = ClinicRoom::factory()->create(['branch_id' => $this->branch->id, 'status' => ClinicRoom::STATUS_ACTIVE]);
    $this->doctorRecord = Doctor::factory()->withAllowedBranches([$this->branch])->create(['name' => 'drg. Galeri Foto']);
    $this->doctor = rmeMakeDoctorOnline($this->doctorRecord, $this->branch, $this->room)->fresh();
    $this->frontOffice = tap(User::factory()->create(), fn (User $u) => rmeMakeFrontOfficeActive($u, $this->branch))->fresh();
    $this->perawat = tap(userInRole('Perawat'), fn (User $u) => rmeMakePerawatActive($u, $this->branch))->fresh();
    $this->supervisor = userInRole('Supervisor RME');

    rcpgFlag(true);
});

afterEach(function () {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

/** Pin both clocks to a clinic wall-clock instant (WITA). */
function rcpgClock(string $wita): void
{
    // A UTC instant: a Makassar-zoned test "now" would leak into now() itself.
    $instant = CarbonImmutable::parse($wita, 'Asia/Makassar')->utc();
    Carbon::setTestNow($instant);
    CarbonImmutable::setTestNow($instant);
}

function rcpgFlag(bool $on): void
{
    $flags = config('feature_flags.flags', []);
    $flags[RCPG_FLAG]['default'] = $on;
    $flags[RCPG_FLAG]['env_value'] = null;
    config()->set('feature_flags.flags', $flags);
}

function rcpgJpeg(): string
{
    $image = imagecreatetruecolor(180, 120);
    imagefilledrectangle($image, 0, 0, 179, 119, imagecolorallocate($image, 120, 60, 160));
    ob_start();
    imagejpeg($image, null, 85);
    $bytes = (string) ob_get_clean();
    imagedestroy($image);

    return $bytes;
}

/** An in-progress visit with the patient's PTM through the real service; the doctor countersigns unless told not to. */
function rcpgEncounter(bool $documentation = true, bool $countersign = true): ClinicVisit
{
    $patient = Patient::factory()->create(['branch_id' => test()->branch->id, 'name' => 'Pasien Galeri Rahasia']);
    $visit = ClinicVisit::factory()->create([
        'branch_id' => test()->branch->id,
        'patient_id' => $patient->id,
        'doctor_id' => test()->doctorRecord->id,
        'clinic_room_id' => test()->room->id,
        'status' => ClinicVisit::STATUS_IN_PROGRESS,
    ])->refresh();

    $consents = app(RmeVisitConsentService::class);
    $consent = $consents->sign($visit, test()->frontOffice, [
        'template_code' => 'PERSETUJUAN_TINDAKAN_MEDIS',
        'consenter_relationship' => 'self',
        'medical_action' => 'Perawatan saluran akar',
        'treatment_summary' => 'Perawatan saluran akar',
        'documentation_consent' => $documentation ? '1' : '0',
        'consenter_signature' => validPodSignatureData(),
    ]);

    if ($countersign) {
        $consents->countersign($consent->fresh(), test()->doctor, ['doctor_signature' => validPodSignatureData()]);
    }

    return $visit->fresh();
}

function rcpgUpload(ClinicVisit $visit, ?User $uploader = null): RmeClinicalPhoto
{
    return app(RmeClinicalPhotoService::class)->upload($visit->fresh(), $uploader ?? test()->doctor, UploadedFile::fake()->createWithContent('foto.jpg', rcpgJpeg()));
}

function rcpgVoid(RmeClinicalPhoto $photo, ?User $voider = null): RmeClinicalPhoto
{
    return app(RmeClinicalPhotoService::class)->void($photo->fresh(), $voider ?? test()->supervisor, RCPG_REASON);
}

function rcpgPage(User $user, ClinicVisit $visit)
{
    return test()->actingAs($user)->get(route('rme.visits.show', $visit));
}

function rcpgImageUrl(RmeClinicalPhoto $photo): string
{
    return route('rme.clinical-photos.image', $photo);
}

function rcpgVoidUrl(RmeClinicalPhoto $photo): string
{
    return route('rme.clinical-photos.void', $photo);
}

/** One element's own markup by its data-testid ('' when absent), parsed from the page. */
function rcpgSection(string $html, string $testId): string
{
    $previous = libxml_use_internal_errors(true);
    $dom = new DOMDocument;
    $dom->loadHTML($html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    $node = (new DOMXPath($dom))->query('//*[@data-testid="'.$testId.'"]')->item(0);

    return $node === null ? '' : (string) $dom->saveHTML($node);
}

/** The "Foto Klinis" card's own markup, or '' when it is absent. */
/**
 * A person's name as rcpgSection() serialises it: DOMDocument::saveHTML()
 * escapes &, < and > in text but writes quotes back literally, so e() (which
 * gives &#039;) never matches a generated name such as "O'Hara".
 */
function rcpgDomText(string $text): string
{
    return htmlspecialchars($text, ENT_NOQUOTES, 'UTF-8');
}

function rcpgCard(string $html): string
{
    return rcpgSection($html, 'clinical-photos-card');
}

it('CPG-01 with the flag OFF the visit page renders no photo markup and no photo route, for every role', function () {
    $visit = rcpgEncounter();
    $photo = rcpgUpload($visit);
    $voided = rcpgUpload($visit, $this->perawat);
    rcpgVoid($voided);

    // Non-vacuous: with the flag ON the same viewers see the card.
    foreach ([$this->doctor, $this->perawat, $this->supervisor] as $user) {
        rcpgPage($user->fresh(), $visit)->assertOk()->assertSee('data-testid="clinical-photos-card"', false);
    }

    rcpgFlag(false);

    foreach ([$this->doctor, $this->perawat, $this->supervisor, userInRole('Super Admin')] as $user) {
        rcpgPage($user->fresh(), $visit)
            ->assertOk()
            ->assertDontSee('clinical-photos', false)
            ->assertDontSee('clinical-photo', false)
            ->assertDontSee('Foto Klinis')
            ->assertDontSee('Foto dibatalkan')
            ->assertDontSee(rcpgImageUrl($photo), false)
            ->assertDontSee(rcpgImageUrl($voided), false)
            ->assertDontSee(route('rme.visits.clinical-photos.store', $visit), false)
            ->assertDontSee(RCPG_REASON);
    }
});

it('CPG-02 the uploader gets the upload form, the form posts to the server, and the photo appears with uploader and WITA time', function () {
    $visit = rcpgEncounter();

    $card = rcpgCard(rcpgPage($this->doctor, $visit)->assertOk()->getContent());

    expect($card)->not->toBe('')
        ->and($card)->toContain('action="'.route('rme.visits.clinical-photos.store', $visit).'"')
        ->and($card)->toContain('enctype="multipart/form-data"')
        ->and($card)->toContain('name="photo"')
        ->and($card)->toContain('accept="image/jpeg,image/png"')
        ->and($card)->toContain('name="_token"')
        ->and($card)->toContain('Belum ada foto klinis');

    // The form's own POST (not XHR) answers a redirect back with the status.
    $this->actingAs($this->doctor)
        ->from(route('rme.visits.show', $visit))
        ->post(route('rme.visits.clinical-photos.store', $visit), [
            'photo' => UploadedFile::fake()->createWithContent('foto.jpg', rcpgJpeg()),
        ])
        ->assertRedirect(route('rme.visits.show', $visit))
        ->assertSessionHas('status');

    $photo = RmeClinicalPhoto::query()->where('clinic_visit_id', $visit->id)->sole();

    $card = rcpgCard(rcpgPage($this->doctor, $visit)->assertOk()->getContent());

    expect($card)->toContain('src="'.rcpgImageUrl($photo).'"')
        ->and($card)->toContain('loading="lazy"')
        ->and($card)->toContain(rcpgDomText($this->doctor->name))
        ->and($card)->toContain('18/11/2026 10:30 WITA')
        ->and($card)->not->toContain('Belum ada foto klinis');
});

it('CPG-03 a refused account sees the server reason and a disabled control, never a form; a non-uploader sees neither', function () {
    $storeUrl = fn (ClinicVisit $v) => route('rme.visits.clinical-photos.store', $v);

    // PTM awaiting the treating doctor's signature.
    $awaiting = rcpgEncounter(countersign: false);
    $card = rcpgCard(rcpgPage($this->doctor, $awaiting)->assertOk()->getContent());
    expect($card)->toContain(e(RmeVisitConsentService::MESSAGE_PHOTO_AWAITING_DOCTOR))
        ->and($card)->toContain('data-testid="clinical-photo-upload-refused"')
        ->and($card)->not->toContain($storeUrl($awaiting))
        ->and($card)->not->toContain('name="photo"');

    // Clinical documentation answered TIDAK on an executed PTM.
    $refused = rcpgEncounter(documentation: false);
    $card = rcpgCard(rcpgPage($this->doctor, $refused)->assertOk()->getContent());
    expect($card)->toContain(e(RmeVisitConsentService::MESSAGE_PHOTO_DOCUMENTATION_REFUSED))
        ->and($card)->not->toContain($storeUrl($refused))
        ->and($card)->not->toContain('name="photo"');

    // The server agrees: the same POST is refused.
    $this->actingAs($this->doctor)->withHeader('Accept', 'application/json')
        ->post($storeUrl($refused), ['photo' => UploadedFile::fake()->createWithContent('foto.jpg', rcpgJpeg())])
        ->assertStatus(422);

    // A finished visit: history, never a new photo.
    $finished = rcpgEncounter();
    $photo = rcpgUpload($finished);
    DB::table('trx_clinic_visits')->where('id', $finished->id)->update(['status' => ClinicVisit::STATUS_CASHIER_PENDING]);
    $card = rcpgCard(rcpgPage($this->doctor, $finished->fresh())->assertOk()->getContent());
    expect($card)->toContain(e(RmeVisitConsentService::MESSAGE_PHOTO_NOT_IN_EXAMINATION))
        ->and($card)->toContain('src="'.rcpgImageUrl($photo).'"')
        ->and($card)->not->toContain($storeUrl($finished));

    // Supervisor RME reads but never uploads: no form, no refusal noise.
    $live = rcpgEncounter();
    $card = rcpgCard(rcpgPage($this->supervisor, $live)->assertOk()->getContent());
    expect($card)->not->toBe('')
        ->and($card)->not->toContain($storeUrl($live))
        ->and($card)->not->toContain('data-testid="clinical-photo-upload-refused"')
        ->and($card)->not->toContain(e(RmeClinicalPhotoService::MESSAGE_UPLOAD_PERMISSION));

    // Non-vacuous: the Perawat on the same live encounter gets the form.
    expect(rcpgCard(rcpgPage($this->perawat, $live)->assertOk()->getContent()))->toContain($storeUrl($live));
});

it('CPG-04 the gallery lists live photos only', function () {
    $visit = rcpgEncounter();
    $live = rcpgUpload($visit);
    $voided = rcpgVoid(rcpgUpload($visit, $this->perawat));

    foreach ([$this->doctor, $this->perawat, $this->supervisor] as $user) {
        $gallery = rcpgSection(rcpgPage($user->fresh(), $visit)->assertOk()->getContent(), 'clinical-photos-gallery');

        expect($gallery)->toContain('src="'.rcpgImageUrl($live).'"')
            ->and($gallery)->not->toContain(rcpgImageUrl($voided))
            ->and($gallery)->not->toContain(RCPG_REASON);
    }
});

it('CPG-05 the "Foto dibatalkan" list is shown to void holders only, with who voided and when', function () {
    // A name with an apostrophe every time, so the assertion below cannot pass or fail by the generator's luck.
    $this->doctor->forceFill(['name' => "drg. Dewi O'Hara"])->save();
    $visit = rcpgEncounter();
    $photo = rcpgUpload($visit);
    rcpgClock('2026-11-18 11:45:00');
    $voided = rcpgVoid($photo);

    $holder = rcpgCard(rcpgPage($this->supervisor, $visit)->assertOk()->getContent());
    $list = rcpgSection($holder, 'clinical-photos-voided');
    expect($list)->toContain('Foto dibatalkan')
        ->and($list)->toContain(rcpgDomText($this->supervisor->name))
        ->and($list)->toContain(rcpgDomText($this->doctor->name))
        ->and($list)->toContain('18/11/2026 11:45 WITA')
        ->and($list)->toContain('18/11/2026 10:30 WITA')
        ->and($list)->toContain('href="'.rcpgImageUrl($voided).'"');

    foreach ([$this->doctor, $this->perawat] as $user) {
        $card = rcpgCard(rcpgPage($user->fresh(), $visit)->assertOk()->getContent());

        expect($card)->not->toBe('')
            ->and($card)->not->toContain('data-testid="clinical-photos-voided"')
            ->and($card)->not->toContain('Foto dibatalkan')
            ->and($card)->not->toContain(rcpgImageUrl($voided));
    }
});

it('CPG-06 the void form is offered to holders only, never on a photo the viewer uploaded', function () {
    $visit = rcpgEncounter();
    $byDoctor = rcpgUpload($visit);
    $byPerawat = rcpgUpload($visit, $this->perawat);

    // The supervisor (holder, uploaded neither) gets a form per photo, with a confirm step.
    $card = rcpgCard(rcpgPage($this->supervisor, $visit)->assertOk()->getContent());
    expect($card)->toContain('action="'.rcpgVoidUrl($byDoctor).'"')
        ->and($card)->toContain('action="'.rcpgVoidUrl($byPerawat).'"')
        ->and($card)->toContain('name="void_reason"')
        ->and($card)->toContain('minlength="10"')
        ->and($card)->toContain('maxlength="1000"')
        ->and($card)->toContain('data-testid="clinical-photo-void-confirm"');

    // Non-holders: no form.
    foreach ([$this->doctor, $this->perawat] as $user) {
        $card = rcpgCard(rcpgPage($user->fresh(), $visit)->assertOk()->getContent());
        expect($card)->not->toContain(rcpgVoidUrl($byDoctor))
            ->and($card)->not->toContain(rcpgVoidUrl($byPerawat))
            ->and($card)->not->toContain('name="void_reason"');
    }

    // A holder who uploaded one of them (a runtime grant): never on their own photo.
    $this->doctor->givePermissionTo(RmeClinicalPhotoService::VOID_PERMISSION);
    $card = rcpgCard(rcpgPage($this->doctor->fresh(), $visit)->assertOk()->getContent());
    expect($card)->not->toContain(rcpgVoidUrl($byDoctor))
        ->and($card)->toContain('action="'.rcpgVoidUrl($byPerawat).'"');

    // The server agrees on both sides.
    $this->actingAs($this->doctor->fresh())->withHeader('Accept', 'application/json')
        ->post(rcpgVoidUrl($byDoctor), ['void_reason' => RCPG_REASON])->assertForbidden();
});

it('CPG-07 the void reason never reaches a non-holder, the live gallery or a page while the flag is OFF', function () {
    $visit = rcpgEncounter();
    rcpgVoid(rcpgUpload($visit));
    rcpgUpload($visit, $this->perawat);

    foreach ([$this->doctor, $this->perawat] as $user) {
        rcpgPage($user->fresh(), $visit)->assertOk()->assertDontSee(RCPG_REASON);
    }

    // Rule 242 §7 — read deliberately for the holders' own list only.
    $html = rcpgPage($this->supervisor, $visit)->assertOk()->getContent();
    expect(substr_count($html, e(RCPG_REASON)))->toBe(1)
        ->and(rcpgSection($html, 'clinical-photos-voided'))->toContain(e(RCPG_REASON))
        ->and(rcpgSection($html, 'clinical-photos-gallery'))->not->toContain(e(RCPG_REASON));

    rcpgFlag(false);
    rcpgPage($this->supervisor, $visit)->assertOk()->assertDontSee(RCPG_REASON);
});

it('CPG-08 every thumbnail and link uses the private image route: never base64, a storage URL or the object key', function () {
    $visit = rcpgEncounter();
    $photos = collect([rcpgUpload($visit), rcpgUpload($visit, $this->perawat)]);
    $voided = rcpgVoid(rcpgUpload($visit));

    $card = rcpgCard(rcpgPage($this->supervisor, $visit)->assertOk()->getContent());

    preg_match_all('/<img\b[^>]*\bsrc="([^"]+)"/', $card, $sources);
    preg_match_all('/<a\b[^>]*\bhref="([^"]+)"/', $card, $links);

    expect($sources[1])->toHaveCount(2)
        ->and($sources[1])->toEqualCanonicalizing($photos->map(fn ($p) => rcpgImageUrl($p))->all())
        ->and($links[1])->toContain(rcpgImageUrl($voided));

    foreach ([...$sources[1], ...$links[1]] as $url) {
        expect($url)->toMatch('#/rme/clinical-photos/\d+/image$#');
    }

    expect($card)->not->toContain('data:image')
        ->and($card)->not->toContain('/storage/')
        ->and($card)->not->toContain(ClinicalEvidenceStorage::diskName().'/')
        ->and($card)->not->toContain(RmeClinicalPhotoService::DIRECTORY);

    foreach (RmeClinicalPhoto::query()->get() as $row) {
        expect($card)->not->toContain((string) $row->getRawOriginal('photo_path'))
            ->and($card)->not->toContain((string) $row->sha256);
    }
});

it('CPG-09 the read scope still applies: Front Office with a grant, an unrelated doctor and the doctor-patient scope see no photo', function () {
    $visit = rcpgEncounter();
    $photo = rcpgUpload($visit);

    // Front Office reads the visit page, never the photos, even with a runtime grant.
    $this->frontOffice->givePermissionTo(RmeClinicalPhotoService::VIEW_PERMISSION);
    rcpgPage($this->frontOffice->fresh(), $visit)->assertOk()
        ->assertDontSee('data-testid="clinical-photos-card"', false)
        ->assertDontSee(rcpgImageUrl($photo), false);

    // An unrelated doctor in another room: no photo of a visit they cannot read.
    $otherRoom = ClinicRoom::factory()->create(['branch_id' => $this->branch->id, 'status' => ClinicRoom::STATUS_ACTIVE]);
    $other = rmeMakeDoctorOnline(Doctor::factory()->withAllowedBranches([$this->branch])->create(), $this->branch, $otherRoom)->fresh();
    $response = rcpgPage($other, $visit);
    expect($response->getStatusCode())->not->toBe(200)
        ->and((string) $response->getContent())->not->toContain(rcpgImageUrl($photo));

    // The card itself asks the doctor-patient scope: the page stays readable,
    // the photos do not (non-vacuous: the same doctor sees them without the mock).
    rcpgPage($this->doctor, $visit)->assertOk()->assertSee(rcpgImageUrl($photo), false);

    $scope = Mockery::mock(DoctorPatientScopeService::class, [app(DoctorUserResolver::class)])->makePartial();
    $scope->shouldReceive('authorizeVisitAccess')->andReturn(true);
    $scope->shouldReceive('doctorCanAccessVisit')->andReturn(false);
    app()->instance(DoctorPatientScopeService::class, $scope);
    // The router keeps the controller (and the services it was built with)
    // between requests of one test; rebuild it so the mock is the one asked.
    Route::getRoutes()->getByName('rme.visits.show')->flushController();

    rcpgPage($this->doctor->fresh(), $visit)->assertOk()
        ->assertDontSee('data-testid="clinical-photos-card"', false)
        ->assertDontSee(rcpgImageUrl($photo), false);
});

it('CPG-10 the card renders in a constant number of queries however many photos, uploaders and voiders', function () {
    $visit = rcpgEncounter();
    rcpgUpload($visit);
    rcpgVoid(rcpgUpload($visit, $this->perawat));

    $counter = new class
    {
        public int $queries = 0;
    };
    DB::listen(function () use ($counter) {
        $counter->queries++;
    });

    $count = function () use ($visit, $counter): int {
        $counter->queries = 0;
        rcpgPage($this->supervisor->fresh(), $visit->fresh())->assertOk()->assertSee('data-testid="clinical-photos-voided"', false);

        return $counter->queries;
    };

    $count(); // warm the permission cache
    $before = $count();

    $secondSupervisor = userInRole('Supervisor RME');
    $secondPerawat = tap(userInRole('Perawat'), fn (User $u) => rmeMakePerawatActive($u, $this->branch))->fresh();
    rcpgUpload($visit);
    rcpgUpload($visit, $secondPerawat);
    rcpgUpload($visit, $this->perawat);
    rcpgVoid(rcpgUpload($visit, $secondPerawat), $secondSupervisor);
    rcpgVoid(rcpgUpload($visit), $secondSupervisor);

    expect(RmeClinicalPhoto::query()->whereNull('voided_at')->count())->toBe(4)
        ->and(RmeClinicalPhoto::query()->whereNotNull('voided_at')->count())->toBe(3);

    $after = $count();

    expect($after)->toBe($before);
});

it('CPG-11 a refused form post comes back to the visit page with the server message on the card', function () {
    $visit = rcpgEncounter();
    $photo = rcpgUpload($visit);
    $page = route('rme.visits.show', $visit);

    // Non-vacuous: no message before the refused post.
    expect(rcpgCard(rcpgPage($this->supervisor, $visit)->assertOk()->getContent()))
        ->not->toContain(e(RmeClinicalPhotoService::MESSAGE_VOID_REASON));

    $this->actingAs($this->supervisor)->from($page)
        ->post(rcpgVoidUrl($photo), ['void_reason' => 'pendek'])
        ->assertRedirect($page)
        ->assertSessionHasErrors('void_reason');

    $card = rcpgCard($this->actingAs($this->supervisor)->get($page)->assertOk()->getContent());
    expect($card)->toContain(e(RmeClinicalPhotoService::MESSAGE_VOID_REASON))
        ->and($photo->fresh()->isVoided())->toBeFalse();
});
