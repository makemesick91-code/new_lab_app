<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

use App\Models\User;
use App\Modules\Clinic\Models\Clinic;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LabService\Models\LabService;
use App\Modules\Patient\Models\Patient;
use App\Modules\Production\Models\LabOrderAssignment;
use App\Modules\Production\Services\AssignmentService;
use App\Modules\Production\Services\ProductionWorkflowService;
use App\Modules\QualityControl\Models\QualityControl;
use App\Modules\QualityControl\Services\QualityControlService;
use App\Modules\Technician\Models\Technician;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;

/**
 * Seed the Sprint 0 roles & permissions for access-control tests.
 */
function seedAccessControl(): void
{
    test()->seed([PermissionSeeder::class, RoleSeeder::class]);
}

/**
 * A Lab Order in RECEIVED status, ready for production assignment.
 */
function receivedOrder(): LabOrder
{
    return LabOrder::factory()->create(['status' => LabOrder::STATUS_RECEIVED]);
}

/**
 * Assign a technician to an order through the real service (management actor).
 */
function assignOrder(LabOrder $order, ?Technician $technician = null, ?User $actor = null): LabOrderAssignment
{
    $technician = $technician ?? Technician::factory()->create();
    $actor = $actor ?? superAdmin();

    return app(AssignmentService::class)->assign($order->refresh(), $technician->id, 'assigned in test', $actor);
}

/**
 * Move an order to IN_PRODUCTION (assign + start) and return [order, assignment].
 *
 * @return array{0: LabOrder, 1: LabOrderAssignment}
 */
function orderInProduction(?Technician $technician = null): array
{
    $order = receivedOrder();
    $assignment = assignOrder($order, $technician);
    app(ProductionWorkflowService::class)->startWork($order->refresh(), 'start', superAdmin());

    return [$order->refresh(), $assignment->refresh()];
}

/**
 * A user with a linked technician profile (for ownership tests).
 *
 * @param  array<int, string>  $permissions
 * @return array{0: User, 1: Technician}
 */
function technicianActor(array $permissions): array
{
    $user = User::factory()->create();
    $user->givePermissionTo($permissions);
    $technician = Technician::factory()->create(['user_id' => $user->id]);

    return [$user, $technician];
}

/**
 * A Lab Order in QC_PENDING status, ready for QC review.
 */
function qcPendingOrder(): LabOrder
{
    return LabOrder::factory()->create(['status' => LabOrder::STATUS_QC_PENDING]);
}

/**
 * Start a QC review (creates the default checklist) and return it.
 */
function startQcReview(LabOrder $order, ?User $actor = null): QualityControl
{
    return app(QualityControlService::class)->start($order->refresh(), 'review', $actor ?? superAdmin());
}

/**
 * Build a valid StoreLabOrderRequest payload, creating the required master data.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function labOrderPayload(array $overrides = []): array
{
    $clinic = Clinic::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id, 'doctor_id' => $doctor->id]);
    $service = LabService::factory()->create();

    return array_merge([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'order_date' => now()->toDateString(),
        'due_date' => now()->addDays(5)->toDateString(),
        'priority' => 'NORMAL',
        'notes' => 'Test order',
        'items' => [
            ['lab_service_id' => $service->id, 'tooth_number' => '11', 'quantity' => 2, 'unit_price' => 1000000],
        ],
    ], $overrides);
}

/**
 * A user with the Super Admin role (bypasses every gate).
 */
function superAdmin(): User
{
    return User::factory()->create()->assignRole('Super Admin');
}

/**
 * A user granted only the given permission names (no Super Admin bypass).
 *
 * @param  array<int, string>  $permissions
 */
function userWith(array $permissions): User
{
    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    return $user;
}
