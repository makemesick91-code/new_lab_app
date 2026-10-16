<?php

/*
| FEATURE-FINANCE-KLINIK-DATA-ANALYST-ROLES-1 (rule 186) — every guard LAYER,
| asserted on its own.
|
| The oversight surface is defended in depth: route middleware, a controller
| `authorize()`, a branch scope that is empty without the area permission,
| and controller-level re-assertions that hold even for a Super Admin (who
| passes every gate). A mutation run showed that a layer covered only by the
| layer in front of it can be deleted with every behavioural test still
| green. Each test below pins ONE layer with the others still in place.
*/

use App\Models\User;
use App\Modules\ClinicOversight\Services\OversightClinicalRecordService;
use App\Modules\ClinicOversight\Services\OversightDashboardService;
use App\Modules\ClinicOversight\Support\ClinicOversightScope;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\LegacyOdontogram\Models\LegacyOdontogramRecord;
use App\Modules\LegacyRme\Models\LegacyRmeRecord;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Models\MedicalRecordHandwritingPage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    seedAccessControl();
    $this->branchA = covBranch('GLA', 'Cabang Lapis A');
    $this->branchB = covBranch('GLB', 'Cabang Lapis B');
    $this->patient = covPatient($this->branchA, ['name' => 'Pasien Lapis', 'ktp_number' => '7371012233445566']);
    $this->visit = covVisit($this->branchB, $this->patient, ['status' => ClinicVisit::STATUS_COMPLETED]);
    $this->record = MedicalRecord::factory()->create([
        'clinic_visit_id' => $this->visit->id,
        'branch_id' => $this->branchB->id,
        'patient_id' => $this->patient->id,
    ]);
});

/** A user holding exactly the given permissions and no role. */
function covUserWith(array $permissions): User
{
    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    return $user->fresh();
}

it('gives an EMPTY branch set for an area the user does not hold, independent of the routes', function () {
    $scope = app(ClinicOversightScope::class);
    $monitorOnly = covUserWith([ClinicOversightScope::PERMISSION_MONITORING]);

    expect($scope->branchIdsFor($monitorOnly, ClinicOversightScope::PERMISSION_MONITORING))
        ->toContain($this->branchA->id, $this->branchB->id)
        ->and($scope->branchIdsFor($monitorOnly, ClinicOversightScope::PERMISSION_DASHBOARD))->toBe([])
        ->and($scope->branchIdsFor($monitorOnly, ClinicOversightScope::PERMISSION_CLINICAL))->toBe([])
        ->and($scope->allowsBranch($monitorOnly, ClinicOversightScope::PERMISSION_CLINICAL, $this->branchB->id))->toBeFalse();

    foreach (['Kasir', 'Finance', 'Owner'] as $role) {
        $user = covUser($role);
        foreach (ClinicOversightScope::VIEW_PERMISSIONS as $area) {
            expect($scope->branchIdsFor($user, $area))->toBe([], "{$role} / {$area}");
        }
    }
});

it('answers nothing from the services to a caller without the area permission', function () {
    $monitorOnly = covUserWith([ClinicOversightScope::PERMISSION_MONITORING]);
    $payload = app(OversightDashboardService::class)->build($monitorOnly, null, null);

    expect($payload['branches'])->toBe([])
        ->and($payload['totals']['patients'])->toBe(0)
        ->and(app(OversightClinicalRecordService::class)->findVisit($monitorOnly, $this->visit->id))->toBeNull();
});

it('hides every print/PDF/CSV control and denies the export gate without the export permission', function () {
    $viewer = covUser('Data Analyst');
    $withExport = [
        [route('oversight.dashboard'), route('oversight.dashboard.export')],
        [route('oversight.rme.visits.show', $this->visit->id), route('oversight.rme.visits.print', $this->visit->id)],
        [route('oversight.monitoring.queue'), route('oversight.monitoring.queue.export')],
    ];

    covActingAs($viewer);
    foreach ($withExport as [$page, $output]) {
        $this->get($page)->assertOk()->assertSee($output, false);   // positive control
    }

    Role::findByName('Data Analyst', 'web')->revokePermissionTo(ClinicOversightScope::PERMISSION_EXPORT);
    $viewer = $viewer->fresh();
    covActingAs($viewer);

    foreach ($withExport as [$page, $output]) {
        $this->get($page)->assertOk()->assertDontSee($output, false);
    }

    foreach (ClinicOversightScope::VIEW_PERMISSIONS as $area) {
        expect(Gate::forUser($viewer)->allows('clinic-oversight.export', $area))->toBeFalse($area);
    }
});

it('puts the export permission AND the area permission on every output route, and export on no view route', function () {
    $areaByPrefix = [
        'oversight.dashboard' => ClinicOversightScope::PERMISSION_DASHBOARD,
        'oversight.rme' => ClinicOversightScope::PERMISSION_CLINICAL,
        'oversight.legacy' => ClinicOversightScope::PERMISSION_CLINICAL,
        'oversight.monitoring' => ClinicOversightScope::PERMISSION_MONITORING,
        // FEATURE-RME-INVOICE-CORRECTION-RECEIPT-FOUNDATION-1: the fifth area.
        'oversight.billing-corrections' => ClinicOversightScope::PERMISSION_BILLING_CORRECTION,
    ];

    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => str_starts_with((string) $r->getName(), 'oversight.'));
    $outputs = 0;

    foreach ($routes as $route) {
        $name = (string) $route->getName();
        $middleware = $route->gatherMiddleware();
        $area = collect($areaByPrefix)->first(fn ($p, $prefix) => str_starts_with($name, $prefix.'.') || $name === $prefix);

        expect($area)->not->toBeNull($name)
            ->and($middleware)->toContain('permission:'.$area);

        $isOutput = (bool) preg_match('/\.(export|print|pdf|source)$/', $name);
        $outputs += $isOutput ? 1 : 0;

        if ($isOutput) {
            expect($middleware)->toContain('permission:'.ClinicOversightScope::PERMISSION_EXPORT);
        } else {
            expect($middleware)->not->toContain('permission:'.ClinicOversightScope::PERMISSION_EXPORT);
        }
    }

    // 18 before FEATURE-RME-INVOICE-CORRECTION-RECEIPT-FOUNDATION-1, which added four
    // Billing Correction outputs (list export + list print + detail print + detail PDF).
    expect($outputs)->toBe(22);
});

it('404s a VOID legacy archive even for a Super Admin, who passes every gate', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Super Admin');
    covActingAs($admin->fresh());

    $rme = LegacyRmeRecord::factory()->voided()->create(['patient_id' => $this->patient->id, 'origin_branch_id' => $this->branchA->id]);
    $odo = LegacyOdontogramRecord::factory()->create(['patient_id' => $this->patient->id, 'branch_id' => $this->branchA->id]);
    $odo->forceFill(['status' => 'VOID'])->save();

    foreach (['show', 'source', 'print', 'pdf'] as $surface) {
        $this->get(route('oversight.legacy.rme.'.$surface, $rme->id))->assertNotFound();
    }
    foreach (['show', 'source', 'print'] as $surface) {
        $this->get(route('oversight.legacy.odontogram.'.$surface, $odo->id))->assertNotFound();
    }
});

it('404s an out-of-scope handwriting image even for a Super Admin', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Super Admin');
    covActingAs($admin->fresh());

    $main = covBranch('MAIN', 'Kantor Pusat');
    $mainVisit = covVisit($main, covPatient());
    $mainRecord = MedicalRecord::factory()->create(['clinic_visit_id' => $mainVisit->id, 'branch_id' => $main->id, 'patient_id' => $mainVisit->patient_id]);
    $hidden = MedicalRecordHandwritingPage::factory()->create([
        'medical_record_id' => $mainRecord->id,
        'handwriting_path' => 'data:image/png;base64,'.base64_encode('PNGDATA'),
    ]);

    $this->get(route('oversight.rme.handwriting-pages.image', $hidden->id))->assertNotFound();
});

it('audits every streamed handwriting image with ids only, and a missing image not at all', function () {
    $user = covUser('Finance Klinik');
    covActingAs($user);

    $page = MedicalRecordHandwritingPage::factory()->create([
        'medical_record_id' => $this->record->id,
        'page_number' => 2,
        'handwriting_path' => 'data:image/png;base64,'.base64_encode('PNGDATA'),
    ]);
    $empty = MedicalRecordHandwritingPage::factory()->create([
        'medical_record_id' => $this->record->id,
        'page_number' => 3,
        'handwriting_path' => 'data:image/png;base64,',
    ]);

    $this->get(route('oversight.rme.handwriting-pages.image', $page->id))->assertOk();
    $this->get(route('oversight.rme.handwriting-pages.image', $page->id))->assertOk();
    $this->get(route('oversight.rme.handwriting-pages.image', $empty->id))->assertNotFound();

    $rows = DB::table('sys_audit_logs')
        ->where('entity_type', 'clinic_oversight')
        ->where('action', 'OVERSIGHT_NATIVE_RME_FILE_STREAMED')
        ->where('performed_by', $user->id)
        ->get();

    expect($rows)->toHaveCount(2);

    $payload = json_decode((string) $rows->first()->new_values, true);
    expect($payload)->toMatchArray([
        'surface' => 'native_handwriting',
        'record_id' => $this->record->id,
        'branch_id' => $this->branchB->id,
        'patient_id' => $this->patient->id,
        'variant' => 'page',
        'page_number' => 2,
    ]);

    $all = $rows->pluck('new_values')->implode(' ');
    expect($all)->not->toContain('Pasien Lapis')
        ->and($all)->not->toContain('7371012233445566')
        ->and($all)->not->toContain('data:image')
        ->and($all)->not->toContain((string) $this->patient->medical_record_number);
});
