<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| DMS memo M4.4, PTM plan PR3 (rule 241 §6) — consent template v2026.2:
| clause 8 (clinical documentation) and clause 9 (publication / promotion) as
| two separate YA/TIDAK answers, built INACTIVE.
|--------------------------------------------------------------------------
|
| Owner decisions (binding, 2026-11-17): "M4.4 Build + split consent clause 8.
| No automatic FO/Kasir access. New consent version tested; historical consents
| unchanged." The final clause 8/9 wording is PENDING: v2026.2 is never
| activated until the clinic/management approves it word for word, and
| "jangan mengubah consent historis".
|
| So a new consent uses v2026.2 only when BOTH the risky flag
| `rme.consent_template_v2026_2` (default OFF) is on AND the candidate's wording
| is `approved` without the draft marker. Anything else signs on 2026.1 exactly
| as before (a flag-on refusal is logged, ids only). The "approved" wording in
| these tests is a TEST-ONLY override (rtvsApproveCandidate), never the owner's
| text.
|
| Selected by the existing `RmeVisitConsent` critical-gate token in both runner
| variants and declared in critical_gate_mandatory_suites (CG-19). Helper prefix
| `rtvs` is unique because the critical gate loads every suite into one process.
*/

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Consent\Models\RmeVisitConsent;
use App\Modules\Consent\Services\RmeVisitConsentService;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\Patient\Models\Patient;
use App\Services\Foundation\FeatureFlagService;
use App\Support\Storage\ClinicalEvidenceStorage;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

const RTVS_FLAG = 'rme.consent_template_v2026_2';
const RTVS_CODE = 'PERSETUJUAN_TINDAKAN_MEDIS';
const RTVS_DRAFT_MARKER = '[DRAF — menunggu persetujuan redaksi]';

beforeEach(function () {
    seedAccessControl();
    Storage::fake('local');
    Storage::fake(ClinicalEvidenceStorage::diskName());

    $this->branch = Branch::factory()->create(['code' => 'TVS1', 'is_active' => true, 'is_rme_enabled' => true]);
    $this->room = ClinicRoom::factory()->create(['branch_id' => $this->branch->id, 'status' => ClinicRoom::STATUS_ACTIVE]);
    $this->doctorRecord = Doctor::factory()->withAllowedBranches([$this->branch])->create(['name' => 'drg. Versi Templat']);
    $this->doctor = rmeMakeDoctorOnline($this->doctorRecord, $this->branch, $this->room)->fresh();

    // The front office captures the patient's signature (rule 241 §4).
    $this->frontOffice = tap(User::factory()->create(), fn (User $u) => rmeMakeFrontOfficeActive($u, $this->branch))->fresh();

    // Never inherit an environment override.
    rtvsFlag(false);
});

/** Arm or disarm the activation flag (its key contains dots). */
function rtvsFlag(bool $on): void
{
    $flags = config('feature_flags.flags', []);
    $flags[RTVS_FLAG]['default'] = $on;
    $flags[RTVS_FLAG]['env_value'] = null;
    config()->set('feature_flags.flags', $flags);
}

/**
 * TEST-ONLY stand-in for the owner's approved wording: status `approved` and
 * clauses 8/9 without the draft marker. Never the real clinic text.
 *
 * @param  array<string, mixed>  $changes  top-level keys of the definition
 * @param  array<int, string>  $clauses  clause texts by number
 */
function rtvsApproveCandidate(array $changes = [], array $clauses = []): void
{
    $candidate = config('rme_consent.candidate_templates.'.RTVS_CODE);
    $candidate['wording_status'] = 'approved';
    $candidate['clauses'] = array_replace($candidate['clauses'], [
        8 => 'Klausul uji 8: dokumentasi klinis foto/video perawatan (YA/TIDAK).',
        9 => 'Klausul uji 9: publikasi/promosi foto/video perawatan (YA/TIDAK).',
    ], $clauses);

    config()->set('rme_consent.candidate_templates.'.RTVS_CODE, array_replace($candidate, $changes));
}

function rtvsVisit(string $status = ClinicVisit::STATUS_IN_PROGRESS): ClinicVisit
{
    $patient = Patient::factory()->create(['branch_id' => test()->branch->id, 'name' => 'Pasien Rahasia Versi']);

    return ClinicVisit::factory()->create([
        'branch_id' => test()->branch->id,
        'patient_id' => $patient->id,
        'doctor_id' => test()->doctorRecord->id,
        'clinic_room_id' => test()->room->id,
        'status' => $status,
    ])->refresh();
}

/** @param  array<string, mixed>  $overrides */
function rtvsPayload(array $overrides = []): array
{
    return array_merge([
        'template_code' => RTVS_CODE,
        'consenter_relationship' => 'self',
        'medical_action' => 'Perawatan saluran akar gigi 46',
        'treatment_summary' => 'Perawatan saluran akar',
        'documentation_consent' => '1',
        'consenter_signature' => validPodSignatureData(),
    ], $overrides);
}

/** The patient's consent, captured through the real service (never a factory row). */
function rtvsSign(ClinicVisit $visit, array $overrides = [], array $unset = []): RmeVisitConsent
{
    $payload = rtvsPayload($overrides);
    foreach ($unset as $key) {
        unset($payload[$key]);
    }

    return app(RmeVisitConsentService::class)->sign($visit, test()->frontOffice, $payload);
}

function rtvsCountersign(RmeVisitConsent $consent): void
{
    app(RmeVisitConsentService::class)->countersign($consent->fresh(), test()->doctor, ['doctor_signature' => validPodSignatureData()]);
}

/** The consent document exactly as the print route serves it. */
function rtvsDocument(RmeVisitConsent $consent): string
{
    $html = test()->actingAs(test()->frontOffice)->get(route('rme.consents.print', $consent))->assertOk()->getContent();
    $start = strpos($html, '<div class="consent-document">');
    $end = strrpos($html, '</div>');

    expect($start)->not->toBeFalse();

    return substr($html, $start, $end - $start + strlen('</div>'));
}

/** The same consent through the partial as it was at 19b5d923, before v2026.2, with the controller's data. */
function rtvsOldDocument(RmeVisitConsent $consent): string
{
    $consent = $consent->fresh();
    $consent->loadMissing(['clinicVisit.branch', 'patient', 'doctor', 'signedBy', 'voidedBy', 'doctorCountersignature.doctor']);
    $service = app(RmeVisitConsentService::class);
    $countersignature = $consent->doctorCountersignature;

    $old = Blade::render((string) file_get_contents(base_path('tests/Fixtures/rme-consent-document-pre-v2026-2.blade.php.txt')), [
        'consent' => $consent,
        'branch' => $consent->clinicVisit?->branch,
        'relationships' => (array) config('rme_consent.relationships', []),
        'genderLabels' => (array) config('rme_consent.gender_labels', []),
        'consenterSignature' => $service->signatureDataUri($consent->consenter_signature_path),
        'doctorSignature' => $service->signatureDataUri($consent->doctor_signature_path),
        'doctorCountersignature' => $countersignature,
        'doctorCountersignatureImage' => $countersignature !== null ? $service->signatureDataUri($countersignature->signature_path) : null,
        'doctorCountersignaturePending' => $service->countersignaturePending($consent),
    ]);
    $start = strpos($old, '<div class="consent-document">');
    $end = strrpos($old, '</div>');

    return substr($old, $start, $end - $start + strlen('</div>'));
}

/** The error keys a refused sign() names, or [] when it did not refuse. */
function rtvsRefusedKeys(callable $sign): array
{
    try {
        $sign();
    } catch (ValidationException $e) {
        return array_keys($e->errors());
    }

    return [];
}

/** The YA/TIDAK radios a capture page renders ticked, as "field=value". */
function rtvsTicked(string $html): array
{
    preg_match_all('/<input type="radio" name="(documentation_consent|publication_consent)" value="([01])"[^>]*?\bchecked\b[^>]*>/', $html, $matches, PREG_SET_ORDER);

    return array_map(fn (array $m): string => $m[1].'='.$m[2], $matches);
}

/** Submit the capture form from its own page and return the page it comes back to. */
function rtvsSubmitFromForm(ClinicVisit $visit, array $payload): string
{
    return test()->actingAs(test()->frontOffice)
        ->from(route('rme.visits.consent.create', $visit))
        ->followingRedirects()
        ->post(route('rme.visits.consent.store', $visit), $payload)
        ->assertOk()
        ->getContent();
}

/*
| The definition
*/

it('TVS-01 ships v2026.2 as an inactive DRAFT candidate: clauses 8 and 9 split, everything else verbatim from 2026.1, flag risky and OFF', function () {
    $current = config('rme_consent.templates.'.RTVS_CODE);
    $candidate = config('rme_consent.candidate_templates.'.RTVS_CODE);

    // 2026.1 is untouched: still eight clauses, one combined clause-8 answer.
    expect($current['version'])->toBe('2026.1')
        ->and(array_keys($current['clauses']))->toBe(range(1, 8))
        ->and($current['clauses'][8])->toBe('Saya memberikan persetujuan kepada klinik gigi daengtisia secara sadar dan tanpa paksaan dari pihak manapun untuk dokumentasi medis dan mempublikasikan foto/video perawatan saya (YA/TIDAK) dengan syarat tidak menampilkan informasi pribadi.')
        ->and($current['documentation_clause'])->toBe(8)
        ->and($current)->not->toHaveKey('publication_clause')
        // The candidate is never a selectable form of its own.
        ->and(array_keys(config('rme_consent.templates')))->toBe([RTVS_CODE])
        ->and(config('rme_consent.default_template'))->toBe(RTVS_CODE);

    expect($candidate['code'])->toBe(RTVS_CODE)
        ->and($candidate['version'])->toBe('2026.2')
        ->and($candidate['wording_status'])->toBe('draft')
        ->and(array_keys($candidate['clauses']))->toBe(range(1, 9))
        ->and($candidate['documentation_clause'])->toBe(8)
        ->and($candidate['publication_clause'])->toBe(9)
        ->and(config('rme_consent.draft_marker'))->toBe(RTVS_DRAFT_MARKER)
        // The draft text is unmistakable wherever it would ever render.
        ->and($candidate['clauses'][8])->toStartWith(RTVS_DRAFT_MARKER)
        ->and($candidate['clauses'][9])->toStartWith(RTVS_DRAFT_MARKER);

    foreach (range(1, 7) as $number) {
        expect($candidate['clauses'][$number])->toBe($current['clauses'][$number], "clause {$number}");
    }
    foreach (['title', 'location', 'consent_statement', 'clauses_intro', 'declaration', 'signature_labels', 'documentation_labels'] as $key) {
        expect($candidate[$key])->toBe($current[$key], $key);
    }

    $flags = app(FeatureFlagService::class);
    $shipped = (require config_path('feature_flags.php'))['flags'][RTVS_FLAG];

    expect($flags->declaredDefault(RTVS_FLAG))->toBeFalse()
        ->and($flags->get(RTVS_FLAG)['risk_level'])->toBeIn(['high', 'critical'])
        ->and($shipped['env_key'])->toBe('FEATURE_RME_CONSENT_TEMPLATE_V2026_2')
        // Rule 92 — captured at config build, so config:cache keeps the override.
        ->and(array_key_exists('env_value', $shipped))->toBeTrue()
        ->and(collect($flags->validateGovernance()['checks'])->firstWhere('check_id', 'FLAG-ENV-CAPTURE')['status'])->toBe('passed');
});

/*
| Flag OFF — exactly as before
*/

it('TVS-02 flag OFF: a new consent signs on 2026.1 exactly as before — version, snapshot, one answer, the same form and a byte-identical document', function () {
    Log::spy();
    $visit = rtvsVisit();

    $this->actingAs($this->frontOffice)->get(route('rme.visits.consent.create', $visit))->assertOk()
        ->assertSee('(versi 2026.1)', false)
        ->assertSee('name="template_version" value="2026.1"', false)
        ->assertDontSee('name="publication_consent"', false)
        ->assertDontSee('DRAF', false);

    $consent = rtvsSign($visit, ['documentation_consent' => '1']);
    $template = config('rme_consent.templates.'.RTVS_CODE);
    $row = $consent->fresh();

    expect($row->template_version)->toBe('2026.1')
        ->and($row->documentation_consent)->toBeTrue()
        ->and($row->publication_consent)->toBeNull()
        // The snapshot shape of every 2026.1 consent before this PR, key for key.
        ->and($row->content_snapshot)->toBe([
            'code' => $template['code'],
            'version' => '2026.1',
            'title' => $template['title'],
            'consent_statement' => $template['consent_statement'],
            'clauses_intro' => $template['clauses_intro'],
            'clauses' => $template['clauses'],
            'documentation_clause' => 8,
            'declaration' => $template['declaration'],
            'signature_labels' => $template['signature_labels'],
            'location' => $template['location'],
        ]);

    // Awaiting the doctor, countersigned, then history: byte for byte the old partial.
    expect(rtvsDocument($consent))->toBe(rtvsOldDocument($consent));
    rtvsCountersign($consent);
    expect(rtvsDocument($consent))->toBe(rtvsOldDocument($consent));
    DB::table('trx_clinic_visits')->where('id', $visit->id)->update(['status' => ClinicVisit::STATUS_COMPLETED]);
    expect(rtvsDocument($consent))->toBe(rtvsOldDocument($consent))
        ->and(substr_count(rtvsDocument($consent), 'Jawaban:'))->toBe(1);

    // The consent page keeps its single combined row.
    $this->actingAs($this->frontOffice)->get(route('rme.consents.show', $consent))->assertOk()
        ->assertSee('Persetujuan Dokumentasi/Publikasi', false)
        ->assertDontSee('data-testid="consent-publication-answer"', false);

    Log::shouldNotHaveReceived('warning');
});

it('TVS-02b approved wording alone never activates v2026.2 while the flag is OFF', function () {
    rtvsApproveCandidate();

    $consent = rtvsSign(rtvsVisit(), ['publication_consent' => null]);

    expect($consent->template_version)->toBe('2026.1')
        ->and($consent->content_snapshot)->not->toHaveKey('publication_clause')
        ->and($consent->fresh()->publication_consent)->toBeNull();
});

/*
| Flag ON — fail closed on anything but approved wording
*/

it('TVS-03 flag ON with the DRAFT wording: new consents stay on 2026.1 and the refusal is logged with no patient data', function () {
    rtvsFlag(true);
    Log::spy();
    $visit = rtvsVisit();

    $this->actingAs($this->frontOffice)->get(route('rme.visits.consent.create', $visit))->assertOk()
        ->assertSee('(versi 2026.1)', false)
        ->assertDontSee('name="publication_consent"', false)
        ->assertDontSee('DRAF', false);

    $consent = rtvsSign($visit);

    expect($consent->template_version)->toBe('2026.1')
        ->and($consent->content_snapshot['clauses'])->toBe(config('rme_consent.templates.'.RTVS_CODE.'.clauses'))
        ->and($consent->content_snapshot)->not->toHaveKey('publication_clause')
        ->and($consent->fresh()->publication_consent)->toBeNull();

    Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context = []): bool {
        return str_contains($message, 'v2026.2')
            && ! str_contains($message, 'Pasien Rahasia Versi')
            // Ids and reasons only: no patient, visit, consenter or text.
            && $context === ['template_code' => RTVS_CODE, 'candidate_version' => '2026.2', 'reason' => RmeVisitConsentService::TEMPLATE_REFUSAL_WORDING_NOT_APPROVED];
    })->atLeast()->once();
});

it('TVS-03b fails closed on every wording state but exactly "approved" without the draft marker', function (array $changes, array $clauses, string $reason) {
    rtvsFlag(true);
    rtvsApproveCandidate($changes, $clauses);
    Log::spy();

    $consent = rtvsSign(rtvsVisit());

    expect($consent->template_version)->toBe('2026.1')
        ->and($consent->fresh()->publication_consent)->toBeNull();

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []): bool => ($context['reason'] ?? null) === $reason)->atLeast()->once();
})->with([
    'status draft' => [['wording_status' => 'draft'], [], 'wording_not_approved'],
    'status in another case' => [['wording_status' => 'APPROVED'], [], 'wording_not_approved'],
    'status blank' => [['wording_status' => ''], [], 'wording_not_approved'],
    'status missing' => [['wording_status' => null], [], 'wording_not_approved'],
    'approved but clause 9 still carries the draft marker' => [[], [9 => RTVS_DRAFT_MARKER.' Klausul uji 9.'], 'draft_marker_present'],
    'approved but the title still carries the draft marker' => [['title' => RTVS_DRAFT_MARKER.' PERSETUJUAN TINDAKAN MEDIS'], [], 'draft_marker_present'],
    'approved but no clause 9' => [['publication_clause' => null], [], 'definition_invalid'],
    'approved but clause 9 points at no clause' => [['publication_clause' => 10], [], 'definition_invalid'],
    'approved but clauses 8 and 9 are one answer' => [['publication_clause' => 8], [], 'definition_invalid'],
    'approved but the version of 2026.1' => [['version' => '2026.1'], [], 'definition_invalid'],
]);

/*
| Flag ON + approved wording — v2026.2 with two answers
*/

it('TVS-04 flag ON with approved wording: v2026.2 is offered, both answers are stored, snapshotted and rendered', function () {
    rtvsFlag(true);
    rtvsApproveCandidate();
    $visit = rtvsVisit();

    $this->actingAs($this->frontOffice)->get(route('rme.visits.consent.create', $visit))->assertOk()
        ->assertSee('(versi 2026.2)', false)
        ->assertSee('name="template_version" value="2026.2"', false)
        ->assertSee('name="documentation_consent"', false)
        ->assertSee('name="publication_consent"', false)
        ->assertSee('Klausul uji 9: publikasi/promosi foto/video perawatan (YA/TIDAK).', false);

    $this->actingAs($this->frontOffice)
        ->post(route('rme.visits.consent.store', $visit), rtvsPayload([
            'template_version' => '2026.2',
            'documentation_consent' => '1',
            'publication_consent' => '0',
        ]))
        ->assertSessionHasNoErrors();

    $consent = RmeVisitConsent::query()->where('clinic_visit_id', $visit->id)->sole();

    expect($consent->template_version)->toBe('2026.2')
        ->and($consent->documentation_consent)->toBeTrue()
        ->and($consent->publication_consent)->toBeFalse()
        ->and($consent->content_snapshot['version'])->toBe('2026.2')
        ->and(array_keys($consent->content_snapshot['clauses']))->toBe(range(1, 9))
        ->and($consent->content_snapshot['clauses'][9])->toBe('Klausul uji 9: publikasi/promosi foto/video perawatan (YA/TIDAK).')
        ->and($consent->content_snapshot['documentation_clause'])->toBe(8)
        ->and($consent->content_snapshot['publication_clause'])->toBe(9)
        // The snapshot is the agreed text, not the approval bookkeeping.
        ->and($consent->content_snapshot)->not->toHaveKey('wording_status');

    $document = rtvsDocument($consent);

    expect($document)->toMatch('/Klausul uji 8:.*?Jawaban: YA.*?Klausul uji 9:.*?Jawaban: TIDAK/s')
        ->and(substr_count($document, 'Jawaban:'))->toBe(2)
        ->and($document)->not->toContain('DRAF');

    $this->actingAs($this->frontOffice)->get(route('rme.consents.show', $consent))->assertOk()
        ->assertSee('data-testid="consent-documentation-answer"', false)
        ->assertSee('data-testid="consent-publication-answer"', false)
        ->assertDontSee('Persetujuan Dokumentasi/Publikasi', false);
});

it('TVS-04b stores the two answers independently of each other', function (string $documentation, string $publication) {
    rtvsFlag(true);
    rtvsApproveCandidate();

    $consent = rtvsSign(rtvsVisit(), ['documentation_consent' => $documentation, 'publication_consent' => $publication])->fresh();

    expect($consent->template_version)->toBe('2026.2')
        ->and($consent->documentation_consent)->toBe($documentation === '1')
        ->and($consent->publication_consent)->toBe($publication === '1');

    $document = rtvsDocument($consent);
    $answers = fn (string $value): string => $value === '1' ? 'YA' : 'TIDAK';

    expect($document)->toMatch('/Klausul uji 8:.*?Jawaban: '.$answers($documentation).'.*?Klausul uji 9:.*?Jawaban: '.$answers($publication).'/s');
})->with([
    'YA / YA' => ['1', '1'],
    'YA / TIDAK' => ['1', '0'],
    'TIDAK / YA' => ['0', '1'],
    'TIDAK / TIDAK' => ['0', '0'],
]);

it('TVS-05 requires an explicit YA/TIDAK for BOTH clauses on v2026.2 and writes nothing otherwise', function () {
    rtvsFlag(true);
    rtvsApproveCandidate();
    $visit = rtvsVisit();

    $cases = [
        'clause 9 missing' => [[], ['publication_consent'], 'publication_consent'],
        'clause 9 null' => [['publication_consent' => null], [], 'publication_consent'],
        'clause 9 blank' => [['publication_consent' => ''], [], 'publication_consent'],
        'clause 9 not YA/TIDAK' => [['publication_consent' => 'mungkin'], [], 'publication_consent'],
        'clause 8 missing' => [['publication_consent' => '0'], ['documentation_consent'], 'documentation_consent'],
        'clause 8 not YA/TIDAK' => [['publication_consent' => '0', 'documentation_consent' => 'mungkin'], [], 'documentation_consent'],
        // Review LOW-1 — both missing are reported together, in one refusal.
        'both missing' => [[], ['documentation_consent', 'publication_consent'], ['documentation_consent', 'publication_consent']],
    ];

    foreach ($cases as $label => [$overrides, $unset, $keys]) {
        expect(rtvsRefusedKeys(fn () => rtvsSign($visit, $overrides, $unset)))->toBe((array) $keys, $label);
    }

    // Over HTTP the form refuses the same way.
    $payload = rtvsPayload(['template_version' => '2026.2']);
    $this->actingAs($this->frontOffice)->post(route('rme.visits.consent.store', $visit), $payload)
        ->assertSessionHasErrors('publication_consent');
    $this->actingAs($this->frontOffice)->post(route('rme.visits.consent.store', $visit), $payload + ['publication_consent' => 'mungkin'])
        ->assertSessionHasErrors('publication_consent');

    expect(RmeVisitConsent::query()->count())->toBe(0)
        ->and(Storage::disk(ClinicalEvidenceStorage::diskName())->allFiles())->toBe([]);
});

it('TVS-06 refuses an answer given against another version of the form (stale or crafted) and writes nothing', function () {
    $visit = rtvsVisit();

    // Flag OFF: a v2026.2 answer, or a page that showed v2026.2, cannot be filed on 2026.1.
    expect(rtvsRefusedKeys(fn () => rtvsSign($visit, ['publication_consent' => '0'])))->toBe(['template_code'])
        ->and(rtvsRefusedKeys(fn () => rtvsSign($visit, ['template_version' => '2026.2', 'publication_consent' => '0'])))->toBe(['template_code']);

    // Flag ON + approved: a page rendered on 2026.1 before activation cannot be filed on 2026.2.
    rtvsFlag(true);
    rtvsApproveCandidate();

    $this->actingAs($this->frontOffice)
        ->post(route('rme.visits.consent.store', $visit), rtvsPayload(['template_version' => '2026.1']))
        ->assertSessionHasErrors(['template_code' => RmeVisitConsentService::MESSAGE_TEMPLATE_VERSION_CHANGED]);

    expect(RmeVisitConsent::query()->count())->toBe(0)
        ->and(Storage::disk(ClinicalEvidenceStorage::diskName())->allFiles())->toBe([]);

    // The page that matches the version in force signs normally.
    rtvsFlag(false);
    expect(rtvsSign($visit, ['template_version' => '2026.1'])->template_version)->toBe('2026.1');
});

/*
| Historical consents
*/

it('TVS-07 keeps historical consents byte for byte — version, snapshot, single clause-8 answer and document — once v2026.2 is active', function () {
    // A consent signed on 2026.1 through the service, executed, then history.
    $visit = rtvsVisit();
    $signed = rtvsSign($visit, ['documentation_consent' => '1']);
    rtvsCountersign($signed);
    DB::table('trx_clinic_visits')->where('id', $visit->id)->update(['status' => ClinicVisit::STATUS_COMPLETED]);

    // An older consent shape (factory snapshot without the newer keys).
    $legacy = RmeVisitConsent::factory()->create(['clinic_visit_id' => rtvsVisit(ClinicVisit::STATUS_COMPLETED)->id, 'documentation_consent' => true]);

    $rows = DB::table('trx_rme_visit_consents')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    $before = [rtvsDocument($signed), rtvsDocument($legacy)];

    rtvsFlag(true);
    rtvsApproveCandidate();

    // New consents are now 2026.2 …
    expect(rtvsSign(rtvsVisit(), ['publication_consent' => '0'])->template_version)->toBe('2026.2');

    // … and nothing about the historical ones moved.
    expect(DB::table('trx_rme_visit_consents')->whereIn('id', [$signed->id, $legacy->id])->orderBy('id')->get()->map(fn ($row) => (array) $row)->all())->toBe($rows)
        ->and(rtvsDocument($signed))->toBe($before[0])
        ->and(rtvsDocument($legacy))->toBe($before[1])
        ->and($before[0])->toBe(rtvsOldDocument($signed))
        ->and($before[1])->toBe(rtvsOldDocument($legacy))
        ->and($signed->fresh()->publication_consent)->toBeNull()
        ->and($signed->fresh()->template_version)->toBe('2026.1');

    $this->actingAs($this->frontOffice)->get(route('rme.consents.show', $signed))->assertOk()
        ->assertSee('Persetujuan Dokumentasi/Publikasi', false)
        ->assertDontSee('data-testid="consent-publication-answer"', false);
});

/*
| Clause 9 never affects care
*/

it('TVS-08 lets TIDAK on clause 9 — and on clause 8 — block no clinical write', function () {
    rtvsFlag(true);
    rtvsApproveCandidate();
    $visit = rtvsVisit();

    $consent = rtvsSign($visit, ['documentation_consent' => '0', 'publication_consent' => '0']);
    rtvsCountersign($consent);

    $service = app(RmeVisitConsentService::class);

    expect($consent->fresh()->template_version)->toBe('2026.2')
        ->and($service->hasExecutedConsent($visit->fresh()))->toBeTrue()
        ->and($service->ptmStateFor($visit->fresh()))->toBe(RmeVisitConsentService::PTM_EXECUTED)
        ->and($service->canAuthorRmeForPatient($visit->patient_id, $this->doctor))->toBeTrue()
        ->and($service->assertOdontogramAuthoringAllowed($visit->fresh(), $this->doctor)->id)->toBe($visit->id)
        ->and($service->assertPerformedTreatmentAuthoringAllowed($visit->fresh(), $this->doctor)->id)->toBe($visit->id);

    $this->actingAs($this->doctor)->post(route('rme.visits.medical-record.store', $visit))->assertSessionHasNoErrors();
    expect(MedicalRecord::query()->where('clinic_visit_id', $visit->id)->exists())->toBeTrue();

    $this->actingAs($this->doctor)
        ->post(route('rme.visits.transition', $visit), ['status' => ClinicVisit::STATUS_CASHIER_PENDING])
        ->assertSessionHasNoErrors();
    expect($visit->fresh()->status)->toBe(ClinicVisit::STATUS_CASHIER_PENDING);
});

it('TVS-09 lets no workflow read the clause-9 answer: only the consent capture, model and views know it (PR4/PR5 extend this deliberately)', function () {
    $files = collect(File::allFiles(app_path()))
        ->merge(File::allFiles(resource_path('views')))
        ->filter(fn ($file) => str_ends_with($file->getFilename(), '.php'))
        ->mapWithKeys(fn ($file) => [str_replace(base_path().'/', '', $file->getPathname()) => (string) file_get_contents($file->getPathname())]);

    $readersOf = fn (string $needle): array => $files
        ->filter(fn (string $source): bool => str_contains($source, $needle))
        ->keys()
        ->sort()
        ->values()
        ->all();

    // The stored answer.
    expect($readersOf('publication_consent'))->toBe([
        'app/Modules/Consent/Models/RmeVisitConsent.php',
        'app/Modules/Consent/Requests/StoreRmeVisitConsentRequest.php',
        'app/Modules/Consent/Services/RmeVisitConsentService.php',
        'resources/views/rme/consents/create.blade.php',
        'resources/views/rme/consents/partials/document.blade.php',
        'resources/views/rme/consents/show.blade.php',
    ]);

    // Review LOW-3 — the predicates that expose it: no workflow asks them.
    expect($readersOf('publicationClause('))->toBe([
        'app/Modules/Consent/Models/RmeVisitConsent.php',
        'resources/views/rme/consents/show.blade.php',
    ])
        ->and($readersOf('allowsPublication('))->toBe(['app/Modules/Consent/Models/RmeVisitConsent.php'])
        ->and($readersOf('allowsDocumentationPublication('))->toBe(['app/Modules/Consent/Models/RmeVisitConsent.php']);
});

/*
| Review fixes (MEDIUM-1, LOW-1, INFO)
*/

it('TVS-11 never refills a YA/TIDAK answered on another version after a version-changed refusal, in both directions (rule 100 §5)', function () {
    $visit = rtvsVisit();

    // Activation: a page that showed 2026.1 was answered YA, then v2026.2 came into force.
    rtvsFlag(true);
    rtvsApproveCandidate();
    $page = rtvsSubmitFromForm($visit, rtvsPayload(['template_version' => '2026.1', 'documentation_consent' => '1']));

    expect($page)->toContain('(versi 2026.2)')
        ->and($page)->toContain(RmeVisitConsentService::MESSAGE_TEMPLATE_VERSION_CHANGED)
        ->and(rtvsTicked($page))->toBe([]);

    // Rollback: a v2026.2 page answered clause 8 YA / clause 9 TIDAK, then the flag went off.
    // Refilling would turn the clause-8 YA into the 2026.1 COMBINED documentation-and-publication YA.
    rtvsFlag(false);
    $page = rtvsSubmitFromForm($visit, rtvsPayload(['template_version' => '2026.2', 'documentation_consent' => '1', 'publication_consent' => '0']));

    expect($page)->toContain('(versi 2026.1)')
        ->and($page)->toContain(RmeVisitConsentService::MESSAGE_TEMPLATE_VERSION_CHANGED)
        ->and(rtvsTicked($page))->toBe([]);

    expect(RmeVisitConsent::query()->count())->toBe(0);
});

it('TVS-11b still refills the answers after a refusal on the SAME version (the guard is not a blanket reset)', function () {
    $visit = rtvsVisit();

    $page = rtvsSubmitFromForm($visit, rtvsPayload(['template_version' => '2026.1', 'documentation_consent' => '1', 'consenter_signature' => '']));
    expect($page)->toContain('(versi 2026.1)')
        ->and(rtvsTicked($page))->toBe(['documentation_consent=1']);

    rtvsFlag(true);
    rtvsApproveCandidate();
    $page = rtvsSubmitFromForm($visit, rtvsPayload(['template_version' => '2026.2', 'documentation_consent' => '0', 'publication_consent' => '1', 'consenter_signature' => '']));
    expect($page)->toContain('(versi 2026.2)')
        ->and(rtvsTicked($page))->toBe(['documentation_consent=0', 'publication_consent=1']);

    expect(RmeVisitConsent::query()->count())->toBe(0);
});

it('TVS-12 reports both missing answers in one refusal over HTTP with clause-specific messages, and keeps the 2026.1 message', function () {
    $visit = rtvsVisit();
    $store = route('rme.visits.consent.store', $visit);
    $from = route('rme.visits.consent.create', $visit);

    // 2026.1: same key, same text as before.
    $payload = rtvsPayload(['template_version' => '2026.1']);
    unset($payload['documentation_consent']);
    $this->actingAs($this->frontOffice)->from($from)->post($store, $payload)
        ->assertSessionHasErrors(['documentation_consent' => RmeVisitConsentService::MESSAGE_DOCUMENTATION_ANSWER]);

    // v2026.2: both missing, both reported, each naming its own clause.
    rtvsFlag(true);
    rtvsApproveCandidate();
    $payload = rtvsPayload(['template_version' => '2026.2']);
    unset($payload['documentation_consent']);
    $this->actingAs($this->frontOffice)->from($from)->post($store, $payload)
        ->assertSessionHasErrors([
            'documentation_consent' => 'Persetujuan dokumentasi klinis (poin 8) harus dipilih YA atau TIDAK.',
            'publication_consent' => 'Persetujuan publikasi/promosi (poin 9) harus dipilih YA atau TIDAK.',
        ]);

    // A value that is not YA/TIDAK is refused at the edge without naming a clause it may not be.
    $this->actingAs($this->frontOffice)->from($from)->post($store, rtvsPayload(['template_version' => '2026.2', 'documentation_consent' => 'mungkin', 'publication_consent' => '0']))
        ->assertSessionHasErrors('documentation_consent');
    expect(session('errors')->first('documentation_consent'))->not->toContain('publikasi');

    expect(RmeVisitConsent::query()->count())->toBe(0);
});

it('TVS-13 renders, and posts the version of, the form the page actually shows — also when the old input chose another offered form', function () {
    config()->set('rme_consent.templates.UJI_FORM', array_replace(config('rme_consent.templates.'.RTVS_CODE), [
        'code' => 'UJI_FORM',
        'version' => 'U.1',
        'title' => 'FORM UJI',
        'clauses' => [1 => 'Klausul form uji satu-satunya.'],
    ]));
    $visit = rtvsVisit();

    $page = rtvsSubmitFromForm($visit, rtvsPayload(['template_code' => 'UJI_FORM', 'template_version' => 'U.1', 'documentation_consent' => '1', 'consenter_signature' => '']));

    expect($page)->toContain('name="template_version" value="U.1"')
        ->and($page)->toContain('Klausul form uji satu-satunya.')
        ->and($page)->not->toContain('name="template_version" value="2026.1"')
        ->and(rtvsTicked($page))->toBe(['documentation_consent=1']);
});

/*
| The migration
*/

it('TVS-10 adds publication_consent additively (nullable, no backfill) and refuses to drop it once a clause-9 answer exists', function () {
    $historical = rtvsSign(rtvsVisit());

    expect(Schema::hasColumn('trx_rme_visit_consents', 'publication_consent'))->toBeTrue()
        ->and(DB::table('trx_rme_visit_consents')->where('id', $historical->id)->value('publication_consent'))->toBeNull();

    rtvsFlag(true);
    rtvsApproveCandidate();
    rtvsSign(rtvsVisit(), ['publication_consent' => '1']);

    $migration = require base_path('database/migrations/2026_11_17_210001_add_publication_consent_to_trx_rme_visit_consents_table.php');

    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'publication_consent');
    expect(Schema::hasColumn('trx_rme_visit_consents', 'publication_consent'))->toBeTrue();
});
