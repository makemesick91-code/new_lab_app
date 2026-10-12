<?php

/*
| FEATURE-FINANCE-KLINIK-DATA-ANALYST-ROLES-1 (rule 186) — access control and
| read-only guarantees of the oversight surface.
|
| Pins: GET-only route surface; every page reachable by both roles and refused
| to every other role; cross-branch read across RME branches but never MAIN /
| inactive / non-RME; out-of-scope ids are 404 (no existence oracle); every
| existing mutation endpoint refused; GET writes nothing but an audit row;
| KTP/NIK and consent never rendered; landing page; sidebar; existing roles'
| routing unchanged.
*/

use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\LegacyOdontogram\Models\LegacyOdontogramRecord;
use App\Modules\LegacyRme\Models\LegacyRmeRecord;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Models\MedicalRecordHandwritingPage;
use App\Modules\Prescription\Models\RmePrescription;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Services\Auth\PostAuthenticationRedirectService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\StreamedResponse;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    seedAccessControl();
    $this->branchA = covBranch('ACA', 'Cabang Akses A');
    $this->branchB = covBranch('ACB', 'Cabang Akses B');
    $this->patient = covPatient($this->branchA, ['ktp_number' => '7371019988776655', 'phone' => '081234567890']);
    $this->visitB = covVisit($this->branchB, $this->patient, ['status' => ClinicVisit::STATUS_COMPLETED]);
    $this->record = MedicalRecord::factory()->create([
        'clinic_visit_id' => $this->visitB->id,
        'branch_id' => $this->branchB->id,
        'patient_id' => $this->patient->id,
        'notes' => 'Catatan klinis contoh',
    ]);
});

function covGetPages(): array
{
    $t = test();

    return [
        route('oversight.dashboard'),
        route('oversight.dashboard.metrics'),
        route('oversight.rme.index'),
        route('oversight.rme.lookup'),
        route('oversight.rme.lookup', ['mode' => 'patient']),
        route('oversight.rme.patients.show', $t->patient->id),
        route('oversight.rme.visits.show', $t->visitB->id),
        route('oversight.monitoring.rooms'),
        route('oversight.monitoring.room-patients'),
        route('oversight.monitoring.room-treatments'),
        route('oversight.monitoring.queue'),
        route('oversight.monitoring.queue.branches'),
    ];
}

function covExportPages(): array
{
    $t = test();

    return [
        route('oversight.dashboard.export'),
        route('oversight.dashboard.print'),
        route('oversight.rme.export'),
        route('oversight.rme.visits.print', $t->visitB->id),
        route('oversight.monitoring.room-patients.export'),
        route('oversight.monitoring.room-patients.print'),
        route('oversight.monitoring.room-treatments.export'),
        route('oversight.monitoring.room-treatments.print'),
        route('oversight.monitoring.queue.export'),
        route('oversight.monitoring.queue.print'),
    ];
}

it('exposes ONLY GET/HEAD routes under the oversight prefix', function () {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => str_starts_with((string) $r->getName(), 'oversight.'));

    expect($routes->count())->toBeGreaterThan(30);

    foreach ($routes as $route) {
        expect(array_values(array_diff($route->methods(), ['GET', 'HEAD'])))->toBe([], $route->getName().' must be GET-only');
        $middleware = implode(',', $route->gatherMiddleware());
        expect($middleware)->toContain('auth')->toContain('permission:');
    }
});

it('lets both oversight roles open every page and output', function (string $role) {
    covActingAs(covUser($role));

    foreach ([...covGetPages(), ...covExportPages()] as $url) {
        $this->get($url)->assertOk();
    }
})->with(['Finance Klinik', 'Data Analyst']);

it('refuses every oversight page to roles without an oversight permission', function (string $role) {
    covActingAs(covUser($role));

    foreach ([...covGetPages(), ...covExportPages()] as $url) {
        $this->get($url)->assertForbidden();
    }
})->with(['Finance', 'Kasir', 'Doctor', 'Front Office', 'Admin Klinik', 'Perawat', 'Admin Lab', 'Owner', 'Supervisor RME']);

it('redirects a guest to the login page', function () {
    foreach (covGetPages() as $url) {
        $this->get($url)->assertRedirect(route('login'));
    }
});

it('refuses print/PDF/CSV when the export permission is withdrawn, while viewing still works', function () {
    $user = covUser('Data Analyst');
    Role::findByName('Data Analyst', 'web')->revokePermissionTo('export_oversight_data');
    covActingAs($user->fresh());

    $this->get(route('oversight.rme.visits.show', $this->visitB->id))->assertOk();
    foreach (covExportPages() as $url) {
        $this->get($url)->assertForbidden();
    }
});

it('reads a visit in another RME branch but 404s MAIN, inactive, non-RME and unknown ids', function () {
    covActingAs(covUser('Finance Klinik'));
    $this->get(route('oversight.rme.visits.show', $this->visitB->id))->assertOk()->assertSee($this->visitB->visit_number);

    foreach ([
        covBranch('MAIN', 'Kantor Pusat'),
        covBranch('OFF2', 'Cabang Mati', active: false),
        covBranch('NRM2', 'Cabang Lab', rme: false),
    ] as $branch) {
        $hidden = covVisit($branch, covPatient());
        $this->get(route('oversight.rme.visits.show', $hidden->id))->assertNotFound();
        $this->get(route('oversight.rme.visits.print', $hidden->id))->assertNotFound();
        $this->get(route('oversight.rme.visits.pdf', $hidden->id))->assertNotFound();
    }

    $this->get(route('oversight.rme.visits.show', 999999))->assertNotFound();
});

it('never renders a full KTP/NIK or a consent link on any oversight page', function () {
    covActingAs(covUser('Finance Klinik'));

    foreach ([...covGetPages(), ...covExportPages()] as $url) {
        $response = $this->get($url);
        $body = $response->baseResponse instanceof StreamedResponse
            ? $response->streamedContent()
            : (string) $response->getContent();
        expect($body)->not->toContain('7371019988776655')
            ->and($body)->not->toContain('/consents/');
    }
});

it('writes nothing but audit rows when every GET page and output is opened', function () {
    $prescription = RmePrescription::factory()->create([
        'clinic_visit_id' => $this->visitB->id,
        'branch_id' => $this->branchB->id,
        'patient_id' => $this->patient->id,
        'printed_at' => null,
    ]);

    $tables = ['trx_clinic_visits', 'trx_medical_records', 'trx_odontograms', 'trx_rme_prescriptions',
        'trx_rme_invoices', 'trx_rme_payments', 'mst_patients', 'trx_user_online_contexts',
        'trx_rme_legacy_records', 'trx_odontogram_legacy_records', 'trx_rme_visit_consents', 'trx_lab_orders'];
    $before = collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()]);
    $visitUpdated = $this->visitB->fresh()->updated_at;

    covActingAs(covUser('Finance Klinik'));
    foreach ([...covGetPages(), ...covExportPages(),
        route('oversight.rme.visits.prescription.print', $this->visitB->id),
        route('oversight.rme.visits.pdf', $this->visitB->id)] as $url) {
        $this->get($url)->assertOk();
    }

    foreach ($tables as $table) {
        expect(DB::table($table)->count())->toBe($before[$table], "{$table} changed on a GET");
    }

    // The operational prescription print stamps printed_at; the oversight one must not.
    expect($prescription->fresh()->printed_at)->toBeNull()
        ->and($this->visitB->fresh()->updated_at->equalTo($visitUpdated))->toBeTrue();
});

it('records an audit row for opening a record and for every extraction, without PII', function () {
    $user = covUser('Data Analyst');
    covActingAs($user);

    $this->get(route('oversight.rme.visits.show', $this->visitB->id))->assertOk();
    $this->get(route('oversight.rme.visits.pdf', $this->visitB->id))->assertOk();
    $this->get(route('oversight.monitoring.queue.export'))->assertOk();

    $rows = DB::table('sys_audit_logs')->where('entity_type', 'clinic_oversight')->where('performed_by', $user->id)->get();
    expect($rows->pluck('action')->all())->toContain('OVERSIGHT_NATIVE_RME_VIEWED', 'OVERSIGHT_NATIVE_RME_PDF_DOWNLOADED', 'OVERSIGHT_DATA_EXPORTED');

    $payloads = $rows->pluck('new_values')->implode(' ');
    expect($payloads)->not->toContain($this->patient->name)
        ->and($payloads)->not->toContain('7371019988776655')
        ->and($payloads)->not->toContain('081234567890')
        ->and($payloads)->not->toContain((string) $this->patient->medical_record_number);
});

it('refuses every existing clinical and financial mutation endpoint', function (string $role) {
    covActingAs(covUser($role));
    $invoice = covInvoice($this->visitB, RmeInvoice::STATUS_UNPAID, '100000');
    $legacy = LegacyRmeRecord::factory()->create(['patient_id' => $this->patient->id, 'origin_branch_id' => $this->branchB->id]);
    $legacyOdo = LegacyOdontogramRecord::factory()->create(['patient_id' => $this->patient->id, 'branch_id' => $this->branchB->id]);

    $this->post(route('rme.visits.store'), [])->assertForbidden();
    $this->patch(route('rme.visits.update', $this->visitB), [])->assertForbidden();
    $this->post(route('rme.visits.transition', $this->visitB), ['status' => 'cancelled'])->assertForbidden();
    $this->patch(route('rme.visits.assign-room', $this->visitB), [])->assertForbidden();
    $this->post(route('rme.visits.medical-record.store', $this->visitB), [])->assertForbidden();
    $this->patch(route('rme.visits.medical-record.update', [$this->visitB, $this->record]), [])->assertForbidden();
    $this->post(route('rme.visits.medical-record.finalize', [$this->visitB, $this->record]), [])->assertForbidden();
    $this->post(route('rme.visits.prescription.store', $this->visitB), [])->assertForbidden();
    $this->post(route('rme.cashier.store', $this->visitB), [])->assertForbidden();
    $this->post(route('rme.cashier.payment.store', [$this->visitB, $invoice]), [])->assertForbidden();
    $this->post(route('rme.legacy-records.void', $legacy->id), ['reason' => 'x'])->assertForbidden();
    $this->post(route('rme.legacy-odontograms.void', $legacyOdo->id), ['reason' => 'x'])->assertForbidden();
    $this->post(route('settings.patients.store'), [])->assertForbidden();
    $this->post(route('invoices.store'), [])->assertForbidden();

    // Nor may they READ the operational surfaces those mutations live on.
    $this->get(route('rme.visits.show', $this->visitB))->assertForbidden();
    $this->get(route('rme.visits.print', $this->visitB))->assertForbidden();
    $this->get(route('rme.legacy-records.show', $legacy->id))->assertForbidden();
    $this->get(route('dashboard'))->assertForbidden();

    expect($invoice->fresh()->status)->toBe(RmeInvoice::STATUS_UNPAID)
        ->and($legacy->fresh()->isPublished())->toBeTrue()
        ->and($this->visitB->fresh()->status)->toBe(ClinicVisit::STATUS_COMPLETED);
})->with(['Finance Klinik', 'Data Analyst']);

it('serves a handwriting image only for a record inside the oversight scope', function () {
    covActingAs(covUser('Finance Klinik'));

    $page = MedicalRecordHandwritingPage::factory()->create([
        'medical_record_id' => $this->record->id,
        'handwriting_path' => 'data:image/png;base64,'.base64_encode('PNGDATA'),
    ]);
    $this->get(route('oversight.rme.handwriting-pages.image', $page->id))->assertOk();

    $main = covBranch('MAIN', 'Kantor Pusat');
    $mainVisit = covVisit($main, covPatient());
    $mainRecord = MedicalRecord::factory()->create(['clinic_visit_id' => $mainVisit->id, 'branch_id' => $main->id, 'patient_id' => $mainVisit->patient_id]);
    $hidden = MedicalRecordHandwritingPage::factory()->create([
        'medical_record_id' => $mainRecord->id,
        'handwriting_path' => 'data:image/png;base64,'.base64_encode('PNGDATA'),
    ]);
    $this->get(route('oversight.rme.handwriting-pages.image', $hidden->id))->assertNotFound();
});

it('lands both roles on the oversight dashboard and leaves other roles where they were', function () {
    $service = app(PostAuthenticationRedirectService::class);

    foreach (covRoles() as $role) {
        expect($service->defaultLandingPath(covUser($role)))->toBe(route('oversight.dashboard', absolute: false));
    }

    expect($service->defaultLandingPath(covUser('Finance')))->toBe(route('dashboard', absolute: false))
        ->and($service->defaultLandingPath(covUser('Owner')))->toBe(route('dashboard', absolute: false))
        ->and($service->defaultLandingPath(covUser('Admin Lab')))->toBe(route('lab-v2-orders.index', absolute: false));
});

it('shows the oversight navigation to the oversight roles only', function () {
    covActingAs(covUser('Data Analyst'));
    $this->get(route('oversight.dashboard'))
        ->assertOk()
        ->assertSee('data-sidebar-section="oversight"', false)
        ->assertSee('Monitoring Dental')
        ->assertSee('Monitoring Antrian')
        ->assertDontSee('Dashboard RME');

    covActingAs(covUser('Finance'));
    $this->get(route('invoices.index'))->assertOk()->assertDontSee('data-sidebar-section="oversight"', false);
});
