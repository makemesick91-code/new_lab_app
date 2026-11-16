<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| DMS-RME-COMPREHENSIVE-CLOSURE-1 — rendered clinical documents are not stored
|--------------------------------------------------------------------------
|
| The clinical image endpoints (handwriting pages, prescription canvases,
| consent signatures, legacy page bytes) already answer with
| `Cache-Control: private, no-store`. The rendered documents built from the
| same evidence did not: the RME visit print and PDF, the odontogram, consent
| and prescription prints, the legacy archive print/export and the oversight
| copies fell back to the framework default `no-cache, private`, which lets a
| browser keep the page in its disk cache on a shared clinic workstation.
|
| Every such route now carries the framework's own `cache.headers` middleware
| with `private;no_store;max_age=0`. No controller changed.
|
| Selected by the existing `RmeClinicalDocumentReadScope` critical-gate token in both runner
| variants and declared in critical_gate_mandatory_suites (CG-19). Helper prefix
| `rmens` is unique because the critical gate loads every suite into one process.
*/

use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Odontogram\Models\Odontogram;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\Route;

const RMENS_MIDDLEWARE = 'cache.headers:private;no_store;max_age=0';

/** Every route that renders a clinical or patient-data document for a reader. */
const RMENS_CLINICAL_DOCUMENT_ROUTES = [
    'rme.visits.print',
    'rme.visits.pdf',
    'rme.odontograms.print',
    'rme.prescriptions.print',
    'rme.consents.print',
    'rme.legacy-records.print',
    'rme.legacy-records.export',
    'oversight.rme.visits.print',
    'oversight.rme.visits.pdf',
    'oversight.rme.visits.odontogram.print',
    'oversight.rme.visits.prescription.print',
    'oversight.legacy.rme.print',
    'oversight.legacy.rme.pdf',
    'oversight.legacy.odontogram.print',
    // Patient-data documents (independent review, same program): the cashier
    // receipt and the patient / payment / receivable / patient-audit reports.
    'rme.cashier.receipt.pdf',
    'rme.cashier.receivables.export',
    'rme.reports.patients.print',
    'rme.reports.patients.export',
    'rme.reports.payments.print',
    'rme.reports.payments.export',
    'rme.patients.audit.export',
];

function rmensAssertNoStore($response): void
{
    $header = (string) $response->headers->get('Cache-Control');

    expect($header)->toContain('no-store')
        ->and($header)->toContain('private')
        ->and($header)->toContain('max-age=0')
        ->and($header)->not->toContain('public');
}

function rmensVisitWithOdontogram(): ClinicVisit
{
    $visit = ClinicVisit::factory()->create([
        'branch_id' => Branch::where('code', Branch::MAIN_CODE)->firstOrFail()->id,
    ]);

    Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'tooth_map_payload' => ['teeth' => ['11' => ['status' => 'caries']]],
    ]);

    return $visit;
}

it('NS-01 puts every clinical document route behind private, no-store caching', function () {
    foreach (RMENS_CLINICAL_DOCUMENT_ROUTES as $name) {
        $route = Route::getRoutes()->getByName($name);

        expect($route)->not->toBeNull("route {$name} is missing")
            ->and($route->gatherMiddleware())->toContain(RMENS_MIDDLEWARE);
    }
});

it('NS-02 turns the middleware options into a private, no-store, max-age=0 header', function () {
    Route::middleware(['web', RMENS_MIDDLEWARE])->get('/_rmens/document', fn () => response('<html>clinical</html>'));

    rmensAssertNoStore($this->get('/_rmens/document')->assertOk());
});

it('NS-03 answers an authorised RME visit print, PDF and odontogram print with no-store', function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    $manager = rmeClinicalAuthor(['manage_clinic_visits']);
    $visit = rmensVisitWithOdontogram();

    rmensAssertNoStore($this->actingAs($manager)->get(route('rme.visits.print', $visit))->assertOk());
    rmensAssertNoStore($this->actingAs($manager)->get(route('rme.visits.pdf', $visit))->assertOk());
    rmensAssertNoStore($this->actingAs($manager)->get(route('rme.odontograms.print', $visit->odontogram))->assertOk());
});

it('NS-04 changes no authorisation: an account without clinical access is still refused the print', function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    $visit = rmensVisitWithOdontogram();

    foreach (['rme.visits.print', 'rme.visits.pdf'] as $name) {
        $status = $this->actingAs(userWith([]))->get(route($name, $visit))->status();

        expect($status)->toBeIn([403, 404]);
    }
});
