<?php

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\LabOrder\Models\LabCaseCandidate;
use App\Modules\LegacyOdontogram\Models\LegacyOdontogramImport;
use App\Modules\LegacyRme\Models\LegacyRmeImport;
use App\Modules\Patient\Models\Patient;
use App\Modules\Patient\Models\PatientDocument;
use App\Modules\Satusehat\Models\SatusehatCodeMapping;
use App\Modules\Satusehat\Models\SatusehatDataQualityIssue;
use App\Modules\Satusehat\Models\SatusehatEntityIdentifier;
use App\Support\Routing\DatabaseRouteKey;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
|--------------------------------------------------------------------------
| DMS-RME-REVISION-PROGRAM-4 / R-16..R-23 — the eight remaining route
| parameters that name a bigint key never let a non-key segment reach a
| query or an `int` argument.
|--------------------------------------------------------------------------
|
| Measured 2026-11-05 on the live route collection (57 parameter uses):
|
|   R-16 {import}      14  `int $import`  stg_rme_legacy_imports.id /
|                                         stg_odontogram_legacy_imports.id   whereNumber
|   R-17 {patient}      7  Patient        mst_patients.id (settings)          none / whereNumber
|   R-18 {document}     2  PatientDocument mst_patient_documents.id            whereNumber
|   R-19 {candidate}    7  SatusehatCandidate trx_satusehat_candidates.id (5),
|                          LabCaseCandidate   trx_lab_case_candidates.id (2)   none
|   R-20 {mapping}      5  SatusehatCodeMapping mst_satusehat_code_mappings.id none
|   R-21 {issue}       11  `int $issue`   trx_satusehat_data_quality_issues.id none (8) / whereNumber (3)
|   R-22 {identifier}   2  SatusehatEntityIdentifier
|                                         mst_satusehat_entity_identifiers.id  none
|   R-23 {doctor}       8  Doctor         mst_doctors.id                      none (7) / whereNumber (1)
|
| Every key is a bigint `id()` and no model overrides its route key, so
| each takes DatabaseRouteKey::PATTERN. Every name is GENERIC (`candidate`
| already binds two different models), so each is constrained per route,
| never globally (rule 222 §1.6), and the contract is pinned on route NAMES.
|
| Before this revision:
|  - an unconstrained model-bound segment (`abc`) reached
|    `where "id" = 'abc'`: PostgreSQL SQLSTATE 22P02, HTTP 500 plus an ERROR
|    line — for ANY signed-in account, because implicit binding runs before
|    the permission middleware and before `can:satusehat.access`;
|  - `whereNumber` (`[0-9]+`) let a number beyond bigint reach the query
|    (22003) or a number beyond PHP's integer reach an `int` argument
|    (TypeError): HTTP 500 plus an ERROR line;
|  - a leading-zero alias (`0<id>`) cast to the real key and opened, and on
|    the POST/DELETE routes CHANGED, a real record under a non-canonical URL.
*/

require_once __DIR__.'/../Satusehat/helpers.php';
require_once __DIR__.'/../LegacyOdontogram/helpers.php';

beforeEach(function () {
    seedAccessControl();
    $this->admin = superAdmin();
});

/** @return array<string, list<string>> the routes this programme constrained, by name */
function p4rkRouteContract(): array
{
    return [
        'import' => [
            'settings.rme.legacy-imports.show', 'settings.rme.legacy-imports.status',
            'settings.rme.legacy-imports.source', 'settings.rme.legacy-imports.pages.show',
            'settings.rme.legacy-imports.retry', 'settings.rme.legacy-imports.cancel',
            'settings.rme.legacy-imports.review', 'settings.rme.legacy-imports.publish',
            'settings.rme.legacy-odontograms.show', 'settings.rme.legacy-odontograms.pages.show',
            'settings.rme.legacy-odontograms.retry', 'settings.rme.legacy-odontograms.cancel',
            'settings.rme.legacy-odontograms.review', 'settings.rme.legacy-odontograms.publish',
        ],
        'patient' => [
            'settings.patients.edit', 'settings.patients.update', 'settings.patients.destroy',
            'settings.patients.activate', 'settings.patients.deactivate',
            'settings.patients.documents.show', 'settings.patients.documents.destroy',
            // R-11 (Program 3), unchanged: the oversight patient route.
            'oversight.rme.patients.show',
        ],
        'document' => [
            'settings.patients.documents.show', 'settings.patients.documents.destroy',
        ],
        'candidate' => [
            'satusehat.submissions.show', 'satusehat.submissions.preview', 'satusehat.submissions.refresh',
            'satusehat.submissions.approve', 'satusehat.submissions.exclude',
            'lab-case-candidates.show', 'lab-case-candidates.convert',
        ],
        'mapping' => [
            'satusehat.mappings.show', 'satusehat.mappings.review', 'satusehat.mappings.verify',
            'satusehat.mappings.activate', 'satusehat.mappings.deprecate',
        ],
        'issue' => [
            'satusehat.readiness.issues.show', 'satusehat.readiness.issues.acknowledge',
            'satusehat.readiness.issues.assign', 'satusehat.readiness.issues.start',
            'satusehat.readiness.issues.request-review', 'satusehat.readiness.issues.resolve',
            'satusehat.readiness.issues.reopen', 'satusehat.readiness.issues.waive',
            'satusehat.branches.issues.assign', 'satusehat.branches.issues.escalate',
            'satusehat.branches.issues.review',
        ],
        'identifier' => [
            'satusehat.identifiers.deactivate', 'satusehat.identifiers.verify',
        ],
        'doctor' => [
            'settings.doctors.account-links.store', 'settings.doctors.account-links.destroy',
            'settings.doctors.edit', 'settings.doctors.update', 'settings.doctors.destroy',
            'settings.doctors.activate', 'settings.doctors.deactivate',
            'rme.doctor-branch-locks.release-session',
        ],
    ];
}

/**
 * Every constrained route as [method, template]; `{S}` is the segment under
 * test, and any OTHER identifier in the path is a well-formed key.
 *
 * @return array<string, array{0: string, 1: string}>
 */
function p4rkRoutes(): array
{
    return [
        // R-16 {import}
        'import: legacy RME show' => ['get', '/settings/rme/legacy-imports/{S}'],
        'import: legacy RME status' => ['get', '/settings/rme/legacy-imports/{S}/status'],
        'import: legacy RME source' => ['get', '/settings/rme/legacy-imports/{S}/source'],
        'import: legacy RME page' => ['get', '/settings/rme/legacy-imports/{S}/pages/1'],
        'import: legacy RME retry' => ['post', '/settings/rme/legacy-imports/{S}/retry'],
        'import: legacy RME cancel' => ['post', '/settings/rme/legacy-imports/{S}/cancel'],
        'import: legacy RME review' => ['post', '/settings/rme/legacy-imports/{S}/review'],
        'import: legacy RME publish' => ['post', '/settings/rme/legacy-imports/{S}/publish'],
        'import: legacy odontogram show' => ['get', '/settings/rme/legacy-odontograms/{S}'],
        'import: legacy odontogram page' => ['get', '/settings/rme/legacy-odontograms/{S}/pages/1'],
        'import: legacy odontogram retry' => ['post', '/settings/rme/legacy-odontograms/{S}/retry'],
        'import: legacy odontogram cancel' => ['post', '/settings/rme/legacy-odontograms/{S}/cancel'],
        'import: legacy odontogram review' => ['post', '/settings/rme/legacy-odontograms/{S}/review'],
        'import: legacy odontogram publish' => ['post', '/settings/rme/legacy-odontograms/{S}/publish'],
        // R-17 {patient}
        'patient: edit' => ['get', '/settings/patients/{S}/edit'],
        'patient: update' => ['put', '/settings/patients/{S}'],
        'patient: destroy' => ['delete', '/settings/patients/{S}'],
        'patient: activate' => ['patch', '/settings/patients/{S}/activate'],
        'patient: deactivate' => ['patch', '/settings/patients/{S}/deactivate'],
        'patient: document show' => ['get', '/settings/patients/{S}/documents/1'],
        'patient: document destroy' => ['delete', '/settings/patients/{S}/documents/1'],
        // R-18 {document}
        'document: show' => ['get', '/settings/patients/1/documents/{S}'],
        'document: destroy' => ['delete', '/settings/patients/1/documents/{S}'],
        // R-19 {candidate}
        'candidate: SATUSEHAT show' => ['get', '/rme/satusehat/submissions/{S}'],
        'candidate: SATUSEHAT preview' => ['get', '/rme/satusehat/submissions/{S}/preview'],
        'candidate: SATUSEHAT refresh' => ['post', '/rme/satusehat/submissions/{S}/refresh'],
        'candidate: SATUSEHAT approve' => ['post', '/rme/satusehat/submissions/{S}/approve'],
        'candidate: SATUSEHAT exclude' => ['post', '/rme/satusehat/submissions/{S}/exclude'],
        'candidate: lab show' => ['get', '/lab/case-candidates/{S}'],
        'candidate: lab convert' => ['post', '/lab/case-candidates/{S}/convert'],
        // R-20 {mapping}
        'mapping: show' => ['get', '/rme/satusehat/mappings/{S}'],
        'mapping: review' => ['post', '/rme/satusehat/mappings/{S}/review'],
        'mapping: verify' => ['post', '/rme/satusehat/mappings/{S}/verify'],
        'mapping: activate' => ['post', '/rme/satusehat/mappings/{S}/activate'],
        'mapping: deprecate' => ['post', '/rme/satusehat/mappings/{S}/deprecate'],
        // R-21 {issue}
        'issue: readiness show' => ['get', '/rme/satusehat/readiness/issues/{S}'],
        'issue: readiness acknowledge' => ['post', '/rme/satusehat/readiness/issues/{S}/acknowledge'],
        'issue: readiness assign' => ['post', '/rme/satusehat/readiness/issues/{S}/assign'],
        'issue: readiness start' => ['post', '/rme/satusehat/readiness/issues/{S}/start'],
        'issue: readiness request review' => ['post', '/rme/satusehat/readiness/issues/{S}/request-review'],
        'issue: readiness resolve' => ['post', '/rme/satusehat/readiness/issues/{S}/resolve'],
        'issue: readiness reopen' => ['post', '/rme/satusehat/readiness/issues/{S}/reopen'],
        'issue: readiness waive' => ['post', '/rme/satusehat/readiness/issues/{S}/waive'],
        'issue: branch assign' => ['post', '/rme/satusehat/branches/issues/{S}/assign'],
        'issue: branch escalate' => ['post', '/rme/satusehat/branches/issues/{S}/escalate'],
        'issue: branch review' => ['post', '/rme/satusehat/branches/issues/{S}/review'],
        // R-22 {identifier}
        'identifier: deactivate' => ['post', '/rme/satusehat/identifiers/{S}/deactivate'],
        'identifier: verify' => ['post', '/rme/satusehat/identifiers/{S}/verify'],
        // R-23 {doctor}
        'doctor: account link store' => ['post', '/settings/doctors/account-links/{S}'],
        'doctor: account link destroy' => ['delete', '/settings/doctors/account-links/{S}'],
        'doctor: edit' => ['get', '/settings/doctors/{S}/edit'],
        'doctor: update' => ['put', '/settings/doctors/{S}'],
        'doctor: destroy' => ['delete', '/settings/doctors/{S}'],
        'doctor: activate' => ['patch', '/settings/doctors/{S}/activate'],
        'doctor: deactivate' => ['patch', '/settings/doctors/{S}/deactivate'],
        'doctor: release session' => ['post', '/rme/doctor-branch-locks/{S}/release-session'],
    ];
}

/** @return array<string, string> already URL-encoded segments that are not a key */
function p4rkNonKeySegments(): array
{
    return [
        'letters' => 'abc',
        'template placeholder' => '%7BID%7D',
        'negative number' => '-1',
        'zero' => '0',
        'leading zero' => '007',
        'decimal' => '1.5',
        'invalid percent encoding' => '%ZZ',
        'nineteen digits' => '1000000000000000000',
        'beyond PHP integer range' => '99999999999999999999',
    ];
}

/** @return ArrayObject<int, string> */
function p4rkCaptureErrorLogs(): ArrayObject
{
    $levels = new ArrayObject;

    Event::listen(MessageLogged::class, function (MessageLogged $event) use ($levels): void {
        if (in_array($event->level, ['error', 'critical', 'alert', 'emergency'], true)) {
            $levels->append($event->level.': '.mb_substr($event->message, 0, 120));
        }
    });

    return $levels;
}

function p4rkUrl(string $template, string $segment): string
{
    return strtr($template, ['{S}' => $segment]);
}

dataset('p4rk_routes', fn () => p4rkRoutes());

dataset('p4rk_segments', fn () => array_map(fn (string $s) => [$s], p4rkNonKeySegments()));

it('constrains every route carrying the eight remaining parameters with the canonical database key pattern', function () {
    foreach (p4rkRouteContract() as $parameter => $names) {
        $carrying = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => in_array($parameter, $route->parameterNames(), true))
            ->map->getName()->values()->all();

        // Measured 2026-11-05: these are every route carrying the name. A new
        // route reusing a generic name must be reviewed here, not inherit a
        // pattern it may not want.
        expect($carrying)->toEqualCanonicalizing($names, $parameter);

        foreach ($names as $name) {
            expect(Route::getRoutes()->getByName($name)->wheres[$parameter] ?? null)
                ->toBe(DatabaseRouteKey::PATTERN, "{$name} {{$parameter}}");
        }
    }
});

it('keeps all eight names out of the global list because each is generic', function () {
    foreach (array_keys(p4rkRouteContract()) as $parameter) {
        expect(DatabaseRouteKey::CONSTRAINED_PARAMETERS)->not->toContain($parameter);
    }

    // The global list is unchanged by this programme (rule 222 §1.1).
    expect(DatabaseRouteKey::CONSTRAINED_PARAMETERS)->toHaveCount(10);
});

it('keeps the page number pattern on the import page routes', function () {
    foreach (['settings.rme.legacy-imports.pages.show', 'settings.rme.legacy-odontograms.pages.show'] as $name) {
        expect(Route::getRoutes()->getByName($name)->wheres['page'] ?? null)
            ->toBe(DatabaseRouteKey::PAGE_NUMBER_PATTERN, $name);
    }
});

it('keeps the constraints in the compiled collection that route:cache writes', function () {
    $compiled = app('router')->getRoutes()->compile();
    $byName = collect($compiled['attributes'])->keyBy(fn (array $attributes) => $attributes['action']['as'] ?? '');

    foreach (p4rkRouteContract() as $parameter => $names) {
        foreach ($names as $name) {
            expect($byName[$name]['wheres'][$parameter] ?? null)->toBe(DatabaseRouteKey::PATTERN, "{$name} {{$parameter}}");
        }
    }
});

it('answers 404 to a guest for every segment that is not a key', function (string $method, string $template) {
    $mismatched = [];

    foreach (p4rkNonKeySegments() as $label => $segment) {
        $status = $this->call(strtoupper($method), p4rkUrl($template, $segment))->getStatusCode();

        if ($status !== 404) {
            $mismatched[] = "{$label} => {$status}";
        }
    }

    expect($mismatched)->toBe([]);
})->with('p4rk_routes');

it('answers 404, never 500, to a Super Admin for a segment that is not a key', function (string $method, string $template, string $segment) {
    // One segment per test: a failed statement aborts the PostgreSQL
    // transaction (25P02) and would poison the next request.
    $errors = p4rkCaptureErrorLogs();

    $this->actingAs($this->admin)
        ->call(strtoupper($method), p4rkUrl($template, $segment))
        ->assertNotFound();

    expect($errors->getArrayCopy())->toBe([]);
})->with('p4rk_routes')->with('p4rk_segments');

it('answers 404, never 500, to a signed-in account holding no permission', function (string $method, string $template, string $segment) {
    // Implicit binding runs before the permission middleware and before
    // `can:satusehat.access`, so an unconstrained segment was reachable by
    // any account at all (rule 222 §1.8).
    $errors = p4rkCaptureErrorLogs();

    $this->actingAs(User::factory()->create())
        ->call(strtoupper($method), p4rkUrl($template, $segment))
        ->assertNotFound();

    expect($errors->getArrayCopy())->toBe([]);
})->with('p4rk_routes')->with([
    'letters' => 'abc',
    'beyond PHP integer range' => '99999999999999999999',
]);

it('never answers 500 for the longest key the pattern admits when it names nothing', function (string $method, string $template) {
    $errors = p4rkCaptureErrorLogs();

    $status = $this->actingAs($this->admin)
        ->call(strtoupper($method), p4rkUrl($template, '999999999999999999'))
        ->getStatusCode();

    // The router admits this key, so the route's own order decides the
    // answer: a lookup 404, except on the two readiness actions whose
    // FormRequest validates the (empty) body before the lookup runs and so
    // redirects (302). Measured on PostgreSQL 16.15. Either way PostgreSQL can
    // cast the key, so it is never a 500 and never an ERROR line.
    $validatesFirst = in_array($template, [
        '/rme/satusehat/readiness/issues/{S}/assign',
        '/rme/satusehat/readiness/issues/{S}/waive',
    ], true);

    expect($status)->toBe($validatesFirst ? 302 : 404)
        ->and($errors->getArrayCopy())->toBe([]);
})->with('p4rk_routes');

it('never routes a leading-zero alias of a key, while the canonical key still routes', function (string $method, string $template) {
    // Router-level proof for EVERY route, independent of any controller,
    // fixture or feature flag (a disarmed capability or a missing record
    // would also answer 404 and hide an alias that still routes).
    $routes = app('router')->getRoutes();

    $canonical = $routes->match(Request::create(p4rkUrl($template, '1'), strtoupper($method)));
    expect($canonical->getName())->not->toBeNull();

    foreach (['01', '007', '0'.str_repeat('9', 17)] as $alias) {
        $matched = null;

        try {
            $matched = $routes->match(Request::create(p4rkUrl($template, $alias), strtoupper($method)))->getName();
        } catch (NotFoundHttpException) {
            // Expected: no route claims the alias.
        }

        expect($matched)->toBeNull("{$template} routed the alias {$alias} to {$matched}");
    }
})->with('p4rk_routes');

/*
|--------------------------------------------------------------------------
| Canonical keys still work; a leading-zero alias never reaches a record.
|--------------------------------------------------------------------------
*/

it('R-16 opens a legacy import only at its canonical key', function () {
    legacyRmeArchiveFlag(true);
    lodoFlag(true);
    Storage::fake('legacy_rme_private');
    Storage::fake('legacy_odontogram_private');

    $branch = Branch::factory()->create(['code' => 'RRK1', 'name' => 'Cabang Kunci Rute', 'is_active' => true, 'is_rme_enabled' => true]);
    $patient = Patient::factory()->create(['branch_id' => $branch->id]);
    $rme = LegacyRmeImport::factory()->readyForReview()->create(['patient_id' => $patient->id, 'origin_branch_id' => $branch->id]);
    $odontogram = LegacyOdontogramImport::factory()->create(['patient_id' => $patient->id, 'origin_branch_id' => $branch->id]);

    $this->actingAs($this->admin);

    $this->get('/settings/rme/legacy-imports/'.$rme->id)->assertOk();
    $this->get('/settings/rme/legacy-odontograms/'.$odontogram->id)->assertOk();

    $this->get('/settings/rme/legacy-imports/0'.$rme->id)->assertNotFound();
    $this->get('/settings/rme/legacy-imports/00'.$rme->id.'/status')->assertNotFound();
    $this->get('/settings/rme/legacy-odontograms/0'.$odontogram->id)->assertNotFound();

    // A state-changing alias never reaches the import.
    $before = $rme->fresh()->status;
    $this->post('/settings/rme/legacy-imports/0'.$rme->id.'/cancel')->assertNotFound();
    expect($rme->fresh()->status)->toBe($before);
});

it('R-17 opens a patient only at its canonical key and keeps the permission gate', function () {
    $patient = Patient::factory()->create();

    $this->actingAs($this->admin);
    $this->get('/settings/patients/'.$patient->id.'/edit')->assertOk();
    $this->get('/settings/patients/0'.$patient->id.'/edit')->assertNotFound();

    $this->patch('/settings/patients/0'.$patient->id.'/deactivate')->assertNotFound();
    expect((bool) $patient->fresh()->is_active)->toBe((bool) $patient->is_active);

    // No `manage patients`: the gate answers at the canonical key (unchanged).
    $this->actingAs(User::factory()->create())
        ->get('/settings/patients/'.$patient->id.'/edit')
        ->assertForbidden();
});

it('R-18 streams a private patient document only at its canonical keys', function () {
    Storage::fake('local');
    $binary = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAoAAAAKCAYAAACNMs+9AAAAFUlEQVR42mNk+M9Qz0AEYBxVSF+FABJADveWkH6oAAAAAElFTkSuQmCC');
    $patient = Patient::factory()->create();
    $path = 'patient-documents/'.$patient->id.'/ktp-rrk-'.uniqid().'.png';
    Storage::disk('local')->put($path, $binary);
    $document = $patient->documents()->create([
        'document_type' => PatientDocument::TYPE_KTP,
        'file_path' => $path,
        'original_filename' => 'ktp.png',
        'mime_type' => 'image/png',
        'file_size' => strlen($binary),
        'compressed_file_size' => strlen($binary),
        'checksum' => hash('sha256', $binary),
        'uploaded_by' => null,
        'captured_branch_id' => null,
    ]);

    $this->actingAs($this->admin);

    $this->get('/settings/patients/'.$patient->id.'/documents/'.$document->id)->assertOk();

    // Before this revision each alias cast to the real key and streamed (and
    // audited) the KTP image under a non-canonical URL.
    $this->get('/settings/patients/0'.$patient->id.'/documents/'.$document->id)->assertNotFound();
    $this->get('/settings/patients/'.$patient->id.'/documents/0'.$document->id)->assertNotFound();

    // ...and a DELETE alias removed it.
    $this->delete('/settings/patients/'.$patient->id.'/documents/0'.$document->id)->assertNotFound();
    $this->delete('/settings/patients/0'.$patient->id.'/documents/'.$document->id)->assertNotFound();

    expect(PatientDocument::find($document->id))->not->toBeNull()
        ->and(Storage::disk('local')->exists($path))->toBeTrue();
});

it('R-19 opens a SATUSEHAT candidate only at its canonical key and keeps the Super Admin gate', function () {
    $ctx = ssMakeVisit();
    ssAddIdentifiers($ctx);
    $candidate = ssService()->generateForVisit($ctx['visit']);

    $this->actingAs($this->admin);
    $this->get('/rme/satusehat/submissions/'.$candidate->id)->assertOk();
    $this->get('/rme/satusehat/submissions/0'.$candidate->id)->assertNotFound();
    $this->get('/rme/satusehat/submissions/0'.$candidate->id.'/preview')->assertNotFound();

    $reviewBefore = $candidate->fresh()->review_status;
    $this->post('/rme/satusehat/submissions/0'.$candidate->id.'/exclude', ['reason' => 'Uji kunci rute: alias.'])->assertNotFound();
    expect($candidate->fresh()->review_status)->toBe($reviewBefore);

    // Any other role: the SATUSEHAT gate answers at the canonical key (unchanged).
    $this->actingAs(userWith(['view_satusehat_submissions']))
        ->get('/rme/satusehat/submissions/'.$candidate->id)
        ->assertForbidden();
});

it('R-19 opens a lab case candidate only at its canonical key and keeps the permission gate', function () {
    $candidate = LabCaseCandidate::factory()->create();

    $this->actingAs($this->admin);
    $this->get('/lab/case-candidates/'.$candidate->id)->assertOk();
    $this->get('/lab/case-candidates/0'.$candidate->id)->assertNotFound();

    $statusBefore = $candidate->fresh()->status;
    $this->post('/lab/case-candidates/0'.$candidate->id.'/convert')->assertNotFound();
    expect($candidate->fresh()->status)->toBe($statusBefore);

    $this->actingAs(User::factory()->create())
        ->get('/lab/case-candidates/'.$candidate->id)
        ->assertForbidden();
});

it('R-20 opens a SATUSEHAT mapping only at its canonical key', function () {
    $mapping = SatusehatCodeMapping::create([
        'environment' => 'sandbox',
        'local_entity_type' => 'treatment',
        'local_entity_id' => 10,
        'target_resource_type' => 'Procedure',
        'terminology_system' => 'http://snomed.info/sct',
        'target_code' => 'RRK-CODE',
        'target_display' => 'Route key fixture',
        'status' => SatusehatCodeMapping::STATUS_DRAFT,
        'version' => 1,
        'effective_date' => now()->toDateString(),
    ]);

    $this->actingAs($this->admin);
    $this->get('/rme/satusehat/mappings/'.$mapping->id)->assertOk();
    $this->get('/rme/satusehat/mappings/0'.$mapping->id)->assertNotFound();

    $this->post('/rme/satusehat/mappings/0'.$mapping->id.'/deprecate', ['reason' => 'Uji kunci rute: alias.'])->assertNotFound();
    expect($mapping->fresh()->status)->toBe(SatusehatCodeMapping::STATUS_DRAFT);
});

it('R-21 opens a data-quality issue only at its canonical key', function () {
    $ctx = ssMakeVisit();
    $ctx['patient']->update(['gender' => null]);
    ssSyncIssues($ctx);
    $issue = SatusehatDataQualityIssue::query()->where('rule_code', 'patient_identity')->firstOrFail();

    $this->actingAs($this->admin);
    $this->get('/rme/satusehat/readiness/issues/'.$issue->id)->assertOk();
    $this->get('/rme/satusehat/readiness/issues/0'.$issue->id)->assertNotFound();

    $statusBefore = $issue->fresh()->status;
    $this->post('/rme/satusehat/readiness/issues/0'.$issue->id.'/acknowledge')->assertNotFound();
    $this->post('/rme/satusehat/branches/issues/0'.$issue->id.'/escalate')->assertNotFound();
    expect($issue->fresh()->status)->toBe($statusBefore);
});

it('R-22 never lets a leading-zero alias deactivate a real SATUSEHAT identifier', function () {
    $identifier = SatusehatEntityIdentifier::create([
        'environment' => 'sandbox',
        'entity_type' => SatusehatEntityIdentifier::ENTITY_PATIENT,
        'local_entity_type' => 'patient',
        'local_entity_id' => 1,
        'remote_identifier' => 'ihs-rrk-1',
        'status' => SatusehatEntityIdentifier::STATUS_ACTIVE,
    ]);

    $this->actingAs($this->admin);

    // Before this revision the alias bound the real identifier and
    // deactivated it.
    $this->post('/rme/satusehat/identifiers/0'.$identifier->id.'/deactivate')->assertNotFound();
    expect($identifier->fresh()->status)->toBe(SatusehatEntityIdentifier::STATUS_ACTIVE);

    // The canonical key still reaches the action (positive control).
    $this->post('/rme/satusehat/identifiers/'.$identifier->id.'/deactivate')->assertRedirect();
    expect($identifier->fresh()->status)->not->toBe(SatusehatEntityIdentifier::STATUS_ACTIVE);
});

it('R-23 opens a doctor only at its canonical key and keeps the permission gate', function () {
    $doctor = Doctor::factory()->create();

    $this->actingAs($this->admin);
    $this->get('/settings/doctors/'.$doctor->id.'/edit')->assertOk();
    $this->get('/settings/doctors/0'.$doctor->id.'/edit')->assertNotFound();

    $activeBefore = (bool) $doctor->fresh()->is_active;
    $this->patch('/settings/doctors/0'.$doctor->id.'/deactivate')->assertNotFound();
    // While the branch lock is disarmed this route answers the capability 404
    // for every key, alias or not, so this request proves nothing on its own
    // about the alias; the router-level alias test above is the proof.
    $this->post('/rme/doctor-branch-locks/0'.$doctor->id.'/release-session', ['reason' => 'Uji kunci rute: alias.'])->assertNotFound();
    expect((bool) $doctor->fresh()->is_active)->toBe($activeBefore);

    $this->actingAs(User::factory()->create())
        ->get('/settings/doctors/'.$doctor->id.'/edit')
        ->assertForbidden();
});
