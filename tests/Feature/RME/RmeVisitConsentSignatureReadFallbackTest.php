<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| DMS-RME-COMPREHENSIVE-CLOSURE-1 release 2 — consent signature READ fallback
|--------------------------------------------------------------------------
|
| Owner decision 2026-11-17 (rule 237 §14.5a, a temporary exception to rule 121
| §5): until `rme:consent-signatures-migrate --apply` has run and been verified,
| a consent signed before the OD-5-9 change keeps rendering its signatures.
|
|  - The clinical evidence disk is read first, whenever it holds the object
|    (even a damaged copy there is "not available", never silently replaced).
|  - Only an object the clinical disk does not hold yet is read, read-only, from
|    `rme_consent.legacy_signature_disk` while `rme_consent.legacy_read_fallback`
|    is on — never from the public disk.
|  - Nothing is written, copied, moved or deleted by a read; every authorization
|    and branch rule is decided before the read, exactly as before.
|  - New signatures are still written only to the clinical evidence disk.
|
|  - Security review: only the key shape sign() writes is ever looked up; the old
|    disk is an allowlist (`local`); authorization and branch scope come first.
|
| Selected by the existing `RmeVisitConsent` critical-gate token in both runner
| variants and declared in critical_gate_mandatory_suites (CG-19). Helper prefix
| `rcsf` is unique because the critical gate loads every suite into one process.
*/

use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Consent\Models\RmeVisitConsent;
use App\Modules\Consent\Services\RmeVisitConsentService;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Support\Storage\ClinicalEvidenceStorage;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();
    config()->set('rme_consent.legacy_signature_disk', 'local');
    config()->set('rme_consent.legacy_read_fallback', true);
    Storage::fake('local');
    Storage::fake('public');
    Storage::fake(ClinicalEvidenceStorage::diskName());

    $this->branch = Branch::factory()->create([
        'code' => 'CSF1',
        'is_active' => true,
        'is_rme_enabled' => true,
    ]);

    $this->reader = userWith([
        'view_clinic_visits',
        'manage_rme_billing',
        'manage_rme_consents',
        'view_rme_consents',
    ]);
});

function rcsfPng(string $tag): string
{
    return "\x89PNG\r\n\x1a\n".$tag;
}

function rcsfClinical()
{
    return Storage::disk(ClinicalEvidenceStorage::diskName());
}

/** A consent signed through the real route (its object lands on the clinical disk). */
function rcsfSignedConsent(): RmeVisitConsent
{
    $visit = ClinicVisit::factory()->create([
        'branch_id' => test()->branch->id,
        'status' => ClinicVisit::STATUS_IN_PROGRESS,
    ]);

    MedicalRecord::factory()->final()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => test()->branch->id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
    ]);

    test()->actingAs(test()->reader)
        ->post(route('rme.visits.consent.store', $visit->refresh()), [
            'template_code' => 'PERSETUJUAN_TINDAKAN_MEDIS',
            'consenter_relationship' => 'self',
            'medical_action' => 'Pencabutan gigi 36',
            'treatment_summary' => 'Pencabutan gigi',
            'documentation_consent' => '0',
            'consenter_signature' => validPodSignatureData(),
        ])
        ->assertRedirect();

    return RmeVisitConsent::query()->where('clinic_visit_id', $visit->id)->firstOrFail();
}

/**
 * A consent signed BEFORE the OD-5-9 change: its object sits only on the
 * pre-change disk, under the same key the row names.
 */
function rcsfPreReleaseConsent(?string $bytes = null): RmeVisitConsent
{
    $consent = rcsfSignedConsent();
    $key = $consent->consenter_signature_path;

    Storage::disk('local')->put($key, $bytes ?? rcsfPng('legacy-'.$consent->id));
    rcsfClinical()->delete($key);

    return $consent->fresh();
}

it('FB-01 streams a pre-release signature from the old disk, read-only, with the same headers', function () {
    $consent = rcsfPreReleaseConsent();

    $response = $this->actingAs($this->reader)
        ->get(route('rme.consents.signature', [$consent, 'consenter']))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->streamedContent())->toBe(rcsfPng('legacy-'.$consent->id));
});

it('FB-02 embeds a pre-release signature in the consent page and the print', function () {
    $consent = rcsfPreReleaseConsent();
    $expected = 'data:image/png;base64,'.base64_encode(rcsfPng('legacy-'.$consent->id));

    $this->actingAs($this->reader)
        ->get(route('rme.consents.show', $consent))
        ->assertOk()
        ->assertSee($expected, false);

    $this->actingAs($this->reader)
        ->get(route('rme.consents.print', $consent))
        ->assertOk()
        ->assertSee($expected, false);
});

it('FB-03 prefers the clinical evidence disk whenever it holds the object', function () {
    $consent = rcsfSignedConsent();
    $key = $consent->consenter_signature_path;
    $clinicalBytes = rcsfClinical()->get($key);
    Storage::disk('local')->put($key, rcsfPng('stale-legacy-copy'));

    $response = $this->actingAs($this->reader)
        ->get(route('rme.consents.signature', [$consent, 'consenter']))
        ->assertOk();

    expect($response->streamedContent())->toBe($clinicalBytes)
        ->and(app(RmeVisitConsentService::class)->signatureDataUri($key))
        ->toBe('data:image/png;base64,'.base64_encode($clinicalBytes));
});

it('FB-04 answers 404 and renders no image when neither disk holds the object', function () {
    $consent = rcsfPreReleaseConsent();
    Storage::disk('local')->delete($consent->consenter_signature_path);

    $this->actingAs($this->reader)
        ->get(route('rme.consents.signature', [$consent, 'consenter']))
        ->assertNotFound();

    expect(app(RmeVisitConsentService::class)->signatureDataUri($consent->consenter_signature_path))->toBeNull();

    $this->actingAs($this->reader)
        ->get(route('rme.consents.show', $consent))
        ->assertOk()
        ->assertDontSee('data:image/png;base64,', false);
});

it('FB-05 refuses an invalid (non-PNG) object on the old disk: 404, never an image', function () {
    $consent = rcsfPreReleaseConsent('<?php echo "not an image"; ?>');

    $this->actingAs($this->reader)
        ->get(route('rme.consents.signature', [$consent, 'consenter']))
        ->assertNotFound();

    expect(app(RmeVisitConsentService::class)->signatureDataUri($consent->consenter_signature_path))->toBeNull();
});

it('FB-06 never replaces a damaged clinical copy with the old one', function () {
    $consent = rcsfSignedConsent();
    $key = $consent->consenter_signature_path;
    rcsfClinical()->put($key, 'damaged');
    Storage::disk('local')->put($key, rcsfPng('valid-old-copy'));

    $this->actingAs($this->reader)
        ->get(route('rme.consents.signature', [$consent, 'consenter']))
        ->assertNotFound();

    expect(app(RmeVisitConsentService::class)->signatureDataUri($key))->toBeNull();
});

it('FB-07 keeps every authorization: the fallback serves no one the policy refuses', function () {
    $consent = rcsfPreReleaseConsent();

    $outsider = userWith(['view_clinic_visits', 'manage_rme_billing']);

    $this->actingAs($outsider)
        ->get(route('rme.consents.signature', [$consent, 'consenter']))
        ->assertForbidden();

    $this->actingAs($outsider)
        ->get(route('rme.consents.show', $consent))
        ->assertForbidden();

    // An unknown kind is still 404, whatever the old disk holds.
    $this->actingAs($this->reader)
        ->get(route('rme.consents.signature', [$consent, 'anything-else']))
        ->assertNotFound();

    auth()->logout();

    $this->get(route('rme.consents.signature', [$consent, 'consenter']))
        ->assertRedirect();
});

it('FB-08 reads only: nothing is written, copied, moved or deleted by a read', function () {
    $consent = rcsfPreReleaseConsent();
    $key = $consent->consenter_signature_path;
    $legacyBefore = Storage::disk('local')->allFiles();
    $clinicalBefore = rcsfClinical()->allFiles();
    $rowBefore = $consent->getAttributes();

    $this->actingAs($this->reader)->get(route('rme.consents.signature', [$consent, 'consenter']))->assertOk();
    $this->actingAs($this->reader)->get(route('rme.consents.show', $consent))->assertOk();
    $this->actingAs($this->reader)->get(route('rme.consents.print', $consent))->assertOk();

    expect(Storage::disk('local')->allFiles())->toBe($legacyBefore)
        ->and(Storage::disk('local')->get($key))->toBe(rcsfPng('legacy-'.$consent->id))
        ->and(rcsfClinical()->allFiles())->toBe($clinicalBefore)
        ->and(rcsfClinical()->exists($key))->toBeFalse()
        ->and($consent->fresh()->getAttributes())->toBe($rowBefore);
});

it('FB-09 reads nothing from the old disk once the fallback is switched off', function () {
    $consent = rcsfPreReleaseConsent();
    config()->set('rme_consent.legacy_read_fallback', false);

    $this->actingAs($this->reader)
        ->get(route('rme.consents.signature', [$consent, 'consenter']))
        ->assertNotFound();

    expect(app(RmeVisitConsentService::class)->signatureDataUri($consent->consenter_signature_path))->toBeNull();
});

it('FB-10 never falls back to the public disk, even if it were named', function () {
    $consent = rcsfPreReleaseConsent();
    $key = $consent->consenter_signature_path;
    Storage::disk('public')->put($key, rcsfPng('public-copy'));
    Storage::disk('local')->delete($key);
    config()->set('rme_consent.legacy_signature_disk', 'public');

    $this->actingAs($this->reader)
        ->get(route('rme.consents.signature', [$consent, 'consenter']))
        ->assertNotFound();

    expect(app(RmeVisitConsentService::class)->signatureReadDisk($key))->toBeNull();
});

it('FB-11 still writes every new signature only to the clinical evidence disk', function () {
    $consent = rcsfSignedConsent();

    expect(rcsfClinical()->exists($consent->consenter_signature_path))->toBeTrue()
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('FB-12 passes an inline data URI through and never treats it as a key', function () {
    $inline = 'data:image/png;base64,'.base64_encode(rcsfPng('inline'));
    $service = app(RmeVisitConsentService::class);

    expect($service->signatureDataUri($inline))->toBe($inline)
        ->and($service->signatureReadDisk($inline))->toBeNull()
        ->and($service->signatureDataUri(null))->toBeNull()
        ->and($service->signatureDataUri(''))->toBeNull();
});

it('FB-13 ships the fallback ON in configuration, with no environment override', function () {
    $config = require base_path('config/rme_consent.php');
    $source = (string) file_get_contents(base_path('config/rme_consent.php'));

    expect($config['legacy_read_fallback'])->toBeTrue()
        ->and($config['legacy_signature_disk'])->toBe('local')
        ->and(preg_match("/'legacy_read_fallback'\\s*=>\\s*env\\(/", $source))->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Security review round (2026-11-17)
|--------------------------------------------------------------------------
*/

it('FB-14 refuses a reader pinned to another branch before any disk is touched', function () {
    $consent = rcsfPreReleaseConsent();

    $otherBranch = Branch::factory()->create([
        'code' => 'CSF9',
        'is_active' => true,
        'is_rme_enabled' => true,
    ]);

    // Holds the consent view permission, so the route middleware lets it in:
    // the refusal must come from the policy's branch scope.
    $pinned = userWith(['manage_rme_billing', 'view_rme_consents']);
    $pinned->forceFill(['branch_id' => $otherBranch->id])->save();
    rmeMakeKasirActive($pinned, $otherBranch);

    $this->partialMock(RmeVisitConsentService::class, function ($mock) {
        $mock->shouldNotReceive('signatureReadDisk');
        $mock->shouldNotReceive('readSignaturePng');
        $mock->shouldNotReceive('signatureDataUri');
    });

    $this->actingAs($pinned)
        ->get(route('rme.consents.signature', [$consent, 'consenter']))
        ->assertForbidden();

    $this->actingAs($pinned)
        ->get(route('rme.consents.show', $consent))
        ->assertForbidden();

    $this->actingAs($pinned)
        ->get(route('rme.consents.print', $consent))
        ->assertForbidden();
});

it('FB-15 never looks up a crafted key on either disk: 404, no image, no 500', function (string $crafted) {
    $consent = rcsfPreReleaseConsent();
    // A real PNG the crafted key could otherwise reach on the old disk.
    Storage::disk('local')->put('legacy-patient-imports/x.png', rcsfPng('elsewhere'));
    $consent->forceFill(['consenter_signature_path' => $crafted])->save();

    $this->actingAs($this->reader)
        ->get(route('rme.consents.signature', [$consent, 'consenter']))
        ->assertNotFound();

    $this->actingAs($this->reader)
        ->get(route('rme.consents.show', $consent))
        ->assertOk()
        ->assertDontSee('data:image/png;base64,', false);

    expect(app(RmeVisitConsentService::class)->signatureReadDisk($crafted))->toBeNull();
})->with([
    'parent traversal' => ['../../../../etc/hostname'],
    'traversal inside the directory' => ['rme-consents/../legacy-patient-imports/x.png'],
    'absolute path' => ['/etc/abs.png'],
    'another directory' => ['legacy-patient-imports/x.png'],
    'carriage return' => ["rme-consents/CONSENT-1/consenter\r\n.png"],
    'backslash' => ['rme-consents\\CONSENT-1\\consenter.png'],
    'not a png name' => ['rme-consents/CONSENT-1/consenter.txt'],
    'too deep' => ['rme-consents/CONSENT-1/sub/consenter.png'],
    'dot segment' => ['rme-consents/./consenter.png'],
]);

it('FB-16 serves a pre-release DOCTOR signature through the same fallback', function () {
    $consent = rcsfSignedConsent();
    $key = 'rme-consents/'.$consent->consent_number.'/doctor-legacydrawing.png';
    $consent->forceFill(['doctor_signature_path' => $key])->save();
    Storage::disk('local')->put($key, rcsfPng('doctor-legacy'));

    $response = $this->actingAs($this->reader)
        ->get(route('rme.consents.signature', [$consent, 'doctor']))
        ->assertOk();

    expect($response->streamedContent())->toBe(rcsfPng('doctor-legacy'));
});

it('FB-17 fails closed when the fallback setting is absent from configuration', function () {
    $consent = rcsfPreReleaseConsent();
    $settings = config('rme_consent');
    unset($settings['legacy_read_fallback']);
    config()->set('rme_consent', $settings);

    $this->actingAs($this->reader)
        ->get(route('rme.consents.signature', [$consent, 'consenter']))
        ->assertNotFound();
});

it('FB-18 opens only an allowlisted old disk, whatever configuration names', function () {
    $consent = rcsfPreReleaseConsent();
    $key = $consent->consenter_signature_path;
    Storage::fake('elsewhere');
    Storage::disk('elsewhere')->put($key, rcsfPng('elsewhere-copy'));
    config()->set('rme_consent.legacy_signature_disk', 'elsewhere');

    $this->actingAs($this->reader)
        ->get(route('rme.consents.signature', [$consent, 'consenter']))
        ->assertNotFound();

    expect(RmeVisitConsentService::LEGACY_SIGNATURE_READ_DISKS)->toBe(['local']);
});
