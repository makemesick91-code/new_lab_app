<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\DuskTestCase;
use Tests\TestCase;

pest()->extend(DuskTestCase::class)
//  ->use(Illuminate\Foundation\Testing\DatabaseMigrations::class)
    ->in('Browser');

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
use App\Modules\Branch\Models\Branch;
use App\Modules\Clinic\Models\Clinic;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductCategory;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LabService\Models\LabService;
use App\Modules\Patient\Models\Patient;
use App\Modules\Production\Models\LabOrderAssignment;
use App\Modules\Production\Services\AssignmentService;
use App\Modules\Production\Services\ProductionWorkflowService;
use App\Modules\QualityControl\Models\QualityControl;
use App\Modules\QualityControl\Services\QualityControlService;
use App\Modules\RmeOnlineContext\Services\UserOnlineContextService;
use App\Modules\Technician\Models\Technician;
use App\Modules\Technician\Services\TechnicianAssignmentEligibility;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\Testing\File;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Role;

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
    // Assignment targets must be eligible (active user + Technician role).
    $technician = $technician ?? Technician::factory()->assignable()->create();
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
    Role::findOrCreate(TechnicianAssignmentEligibility::ROLE, 'web');
    $user->assignRole(TechnicianAssignmentEligibility::ROLE);
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

/**
 * A user assigned to a seeded role (no Super Admin bypass).
 */
function userInRole(string $role): User
{
    return User::factory()->create()->assignRole($role);
}

function validPodSignatureData(): string
{
    return 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAoAAAAKCAYAAACNMs+9AAAAFUlEQVR42mNk+M9Qz0AEYBxVSF+FABJADveWkH6oAAAAAElFTkSuQmCC';
}

function podPayload(array $overrides = []): array
{
    return array_merge([
        'receiver_name' => 'Budi Santoso',
        'received_at' => now()->format('Y-m-d H:i:s'),
        'receiver_signature_data' => validPodSignatureData(),
    ], $overrides);
}

function inventoryQuickActionsPanelHtml(string $html): string
{
    if (! preg_match('/data-testid="inventory-quick-actions"[\s\S]*?<\/section>/', $html, $matches)) {
        return '';
    }

    return $matches[0];
}

if (! function_exists('createReportStockRow')) {
    /**
     * Seed a branch-scoped product/location with one ledger movement for inventory report tests.
     *
     * @param  array<string, mixed>  $overrides
     * @return array{0: Product, 1: InventoryLocation, 2: ProductCategory, 3: ProductUnit}
     */
    function createReportStockRow(Branch $branch, array $overrides = []): array
    {
        $category = isset($overrides['category_id'])
            ? ProductCategory::findOrFail($overrides['category_id'])
            : ProductCategory::factory()->create([
                'branch_id' => $branch->id,
                'name' => $overrides['category_name'] ?? 'Report Category',
            ]);
        $productCode = $overrides['product_code'] ?? 'RPT-'.fake()->unique()->numerify('###');
        $unit = ProductUnit::factory()->create([
            'name' => $overrides['unit_name'] ?? 'Report Unit',
            'symbol' => $overrides['unit_symbol'] ?? strtolower(str_replace('-', '', $productCode)),
        ]);
        $product = Product::factory()->create([
            'branch_id' => $branch->id,
            'product_category_id' => $category->id,
            'product_unit_id' => $unit->id,
            'code' => $productCode,
            'name' => $overrides['product_name'] ?? 'Report Product '.$productCode,
            'minimum_stock' => $overrides['minimum_stock'] ?? 1,
            'average_cost' => $overrides['average_cost'] ?? 100,
        ]);
        $location = isset($overrides['location_id'])
            ? InventoryLocation::findOrFail($overrides['location_id'])
            : InventoryLocation::factory()->create([
                'branch_id' => $branch->id,
                'name' => $overrides['location_name'] ?? 'Report Room '.$productCode,
            ]);

        createReportMovement(
            $branch,
            $product,
            $location,
            $overrides['quantity_in'] ?? 5,
            $overrides['quantity_out'] ?? 0,
            $overrides['movement_date'] ?? '2026-06-06',
            $overrides,
        );

        return [$product, $location, $category, $unit];
    }
}

if (! function_exists('createReportMovement')) {
    /**
     * @param  array<string, mixed>  $overrides
     */
    function createReportMovement(
        Branch $branch,
        Product $product,
        InventoryLocation $location,
        float|int $quantityIn,
        float|int $quantityOut,
        string $movementDate = '2026-06-06',
        array $overrides = [],
    ): InventoryMovement {
        return InventoryMovement::factory()->create([
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'inventory_location_id' => $location->id,
            'supplier_id' => null,
            'movement_type' => $overrides['movement_type'] ?? ($quantityOut > 0 ? InventoryMovement::TYPE_ADJUSTMENT_OUT : InventoryMovement::TYPE_OPENING),
            'movement_date' => $movementDate,
            'quantity_in' => $quantityIn,
            'quantity_out' => $quantityOut,
            'reference_type' => $overrides['reference_type'] ?? null,
            'reference_id' => $overrides['reference_id'] ?? null,
            'notes' => $overrides['notes'] ?? null,
            'created_by' => isset($overrides['creator_name'])
                ? User::factory()->create(['name' => $overrides['creator_name']])->id
                : ($overrides['created_by'] ?? null),
        ]);
    }
}

if (! function_exists('reportPanelHtml')) {
    function reportPanelHtml(string $html, string $panel): string
    {
        if (! preg_match('/<section[^>]*data-report-panel="'.preg_quote($panel, '/').'"[\s\S]*?<\/section>/', $html, $matches)) {
            return '';
        }

        return $matches[0];
    }
}

if (! function_exists('currentStockReportSectionHtml')) {
    function currentStockReportSectionHtml(string $html): string
    {
        return reportPanelHtml($html, 'current-stock');
    }
}

if (! function_exists('stockCardReportSectionHtml')) {
    function stockCardReportSectionHtml(string $html): string
    {
        return reportPanelHtml($html, 'stock-card');
    }
}

if (! function_exists('lowStockReportSectionHtml')) {
    function lowStockReportSectionHtml(string $html): string
    {
        return reportPanelHtml($html, 'low-stock');
    }
}

if (! function_exists('stockMutationReportSectionHtml')) {
    function stockMutationReportSectionHtml(string $html): string
    {
        return reportPanelHtml($html, 'mutation');
    }
}

if (! function_exists('inventoryValuationReportSectionHtml')) {
    function inventoryValuationReportSectionHtml(string $html): string
    {
        return reportPanelHtml($html, 'valuation');
    }
}

if (! function_exists('roomStockReportSectionHtml')) {
    function roomStockReportSectionHtml(string $html): string
    {
        return reportPanelHtml($html, 'room-stock');
    }
}

/**
 * Sprint 66.1.1 — doctor user with linked master record and online context.
 */
function doctorWithOnlineContext(?Branch $branch = null): User
{
    $branch ??= Branch::factory()->create([
        'is_active' => true,
        'is_rme_enabled' => true,
    ]);

    $doctor = Doctor::factory()->withAllowedBranches([$branch])->create();

    return rmeMakeDoctorOnline($doctor, $branch);
}

/**
 * Sprint 66.0 — mark a doctor master record as online in an RME branch.
 */
function rmeMakeDoctorOnline(
    Doctor $doctor,
    Branch $branch,
    ?ClinicRoom $room = null,
    ?User $user = null,
): User {
    $user ??= $doctor->user_id ? User::query()->find($doctor->user_id) : null;
    $user ??= User::factory()->create();
    $doctor->update(['user_id' => $user->id]);

    $doctor->branches()->syncWithoutDetaching([(int) $branch->id]);

    if (! $user->hasRole('Doctor')) {
        $user->assignRole('Doctor');
    }

    $room ??= ClinicRoom::factory()->create([
        'branch_id' => $branch->id,
        'status' => ClinicRoom::STATUS_ACTIVE,
    ]);

    app(UserOnlineContextService::class)
        ->startDoctorSession($user, (int) $branch->id, (int) $room->id);

    return $user;
}

/**
 * Sprint 66.0 — activate admin klinik branch context for a user.
 */
function rmeMakeAdminClinicActive(User $user, Branch $branch): void
{
    if (! $user->hasRole('Admin Klinik')) {
        $user->assignRole('Admin Klinik');
    }

    app(UserOnlineContextService::class)
        ->startAdminClinicSession($user, (int) $branch->id);
}

/**
 * RME-BRANCH-SUN4 — activate a Perawat branch-only online context through the
 * real service (same canonical mechanism as Admin Klinik).
 */
function rmeMakePerawatActive(User $user, Branch $branch): void
{
    if (! $user->hasRole('Perawat')) {
        $user->assignRole('Perawat');
    }

    app(UserOnlineContextService::class)
        ->startPerawatSession($user, (int) $branch->id);
}

function rmeAdminClinicUser(Branch $branch): User
{
    $user = userInRole('Admin Klinik');
    rmeMakeAdminClinicActive($user, $branch);

    return $user;
}

/**
 * LAB-WORKFLOW-V2 — a real (1x1) PNG upload without requiring the GD extension.
 * getimagesizefromstring + the `image` validation rule both parse these bytes
 * natively, so evidence tests run on CLI environments without GD.
 */
function fakeEvidencePhoto(string $name = 'photo.png'): File
{
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');

    return UploadedFile::fake()->createWithContent($name, $png);
}
