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
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductCategory;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LabService\Models\LabService;
use App\Modules\MedicalRecord\Models\MedicalRecord;
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
 * The shared parent Branch that Lab operational-analytics fixtures belong to.
 *
 * CICD-FIX-4 — those fixtures used to hardcode `branch_id => 1`. Both drivers
 * declare AND enforce `trx_lab_orders_branch_id_foreign`, so this was never a
 * missing-constraint difference; it was a surrogate-id assumption:
 *
 *   LabOrder::factory() -> Doctor::factory() -> Branch::factory()
 *
 * creates a branch as a side effect. Under SQLite each test runs in a
 * transaction that is rolled back, so rowids restart and that incidental branch
 * lands on id 1 — making `branch_id => 1` resolve by accident. PostgreSQL does
 * not roll sequences back, so from the second test onward the branch is id 2,
 * 3, ... and id 1 no longer exists:
 * `Key (branch_id)=(1) is not present in table "mst_branches"`.
 *
 * So this returns a branch the fixture owns explicitly, resolved by code rather
 * than memoised in a `static` so it stays valid after RefreshDatabase rolls back
 * between tests, and so every default-scoped order inside one test still shares
 * ONE branch. The id is whatever the database assigns; no test may depend on it
 * being 1.
 */
function labOpsBranch(): Branch
{
    return Branch::query()->firstWhere('code', 'LABOPS')
        ?? Branch::factory()->create(['code' => 'LABOPS', 'is_active' => true]);
}

/**
 * The shared actor that Lab operational-analytics fixtures attribute writes to.
 *
 * CICD-FIX-4 — `trx_lab_order_status_logs.changed_by`,
 * `trx_lab_orders.created_by`, `trx_lab_model_analyses.analyzed_by` and
 * `trx_lab_external_dispatches.created_by` are all NOT NULL foreign keys to
 * `users`. Hardcoding `=> 1` only worked for the same reason as the branch: in a
 * rolled-back SQLite test the first factory-made user receives id 1, whereas on
 * PostgreSQL the sequence keeps climbing across tests in the same process, so
 * id 1 no longer exists.
 */
function labOpsActor(): User
{
    return User::query()->firstWhere('email', 'lab-ops-fixture@example.test')
        ?? User::factory()->create(['email' => 'lab-ops-fixture@example.test']);
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

/**
 * LEGACY-RME-PDF-1B — a structurally valid, multi-page PDF built in pure PHP.
 *
 * Real bytes with a correct xref table, so `mimetypes:application/pdf`, the
 * `%PDF-` magic check and Poppler itself all accept it — no fixture binary is
 * committed and no clinical document is ever used in a test.
 */
function legacyRmePdfBytes(int $pages = 1, float $width = 595.276, float $height = 841.89): string
{
    $objects = [];
    $kids = [];

    for ($i = 0; $i < $pages; $i++) {
        $kids[] = (3 + $i).' 0 R';
    }

    $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
    $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.$pages.' >>';

    for ($i = 0; $i < $pages; $i++) {
        $objects[3 + $i] = sprintf(
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.4F %.4F] /Resources << >> >>',
            $width,
            $height,
        );
    }

    $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
    $offsets = [];

    foreach ($objects as $number => $body) {
        $offsets[$number] = strlen($pdf);
        $pdf .= $number." 0 obj\n".$body."\nendobj\n";
    }

    $size = count($objects) + 1;
    $xref = strlen($pdf);

    $pdf .= "xref\n0 ".$size."\n0000000000 65535 f \n";

    for ($number = 1; $number < $size; $number++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$number]);
    }

    return $pdf."trailer\n<< /Size ".$size." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
}

/**
 * LEGACY-RME-PDF-1B — an uploadable legacy PDF.
 */
function legacyRmePdfUpload(string $name = 'arsip.pdf', int $pages = 1): File
{
    return UploadedFile::fake()->createWithContent($name, legacyRmePdfBytes($pages));
}

/**
 * LEGACY-RME-PDF-1B — toggle the legacy archive feature flag in a test.
 *
 * The flag key itself contains a dot ("rme.legacy_pdf_archive"), so it is a
 * literal array key and cannot be reached by config dot-notation — the whole
 * `feature_flags.flags` array has to be rewritten, exactly as
 * FeatureFlagFoundationTest does.
 */
function legacyRmeArchiveFlag(bool $enabled): void
{
    $flags = config('feature_flags.flags', []);
    $flags['rme.legacy_pdf_archive']['default'] = $enabled;

    config()->set('feature_flags.flags', $flags);
}

/**
 * LEGACY-RME-PDF-FIX-ROLL2-1 — an RME-enabled branch identified by its business
 * code, created once per test.
 *
 * The branch CODE matters now: a legacy archive's owning branch is derived from
 * the branch-code segment of the patient's Nomor RM, so a fixture branch has to
 * be reachable by that code.
 */
function legacyRmeBranch(string $code = 'TKM1', string $name = 'Cabang Telkomas'): Branch
{
    $branch = Branch::withTrashed()->firstOrNew(['code' => $code]);

    $branch->forceFill([
        'name' => $branch->exists ? $branch->name : $name,
        'is_active' => true,
        'is_rme_enabled' => true,
        'deleted_at' => null,
    ])->save();

    // LEGACY-RME-PDF-ROLL-3 — a fixture branch is also ADMITTED to the running
    // migration wave, mirroring production, where an operator admits exactly
    // the branch they are about to migrate.
    //
    // Admission enforcement deliberately stays ON for the whole suite: the
    // alternative (disabling the gate in phpunit.xml) would leave every
    // ingestion test silently bypassing it, which is how ROLL-2 shipped an
    // advisory pilot scope in the first place. Tests that need a DENIAL clear
    // or rewrite the allowlist explicitly via legacyRmeAdmittedBranches().
    legacyRmeAdmitBranch($code);

    return $branch->refresh();
}

/**
 * LEGACY-RME-PDF-ROLL-3 — add one branch code to the admission allowlist.
 */
function legacyRmeAdmitBranch(string $code): void
{
    $admitted = (array) config('legacy_rme_rollout.admission.admitted_branch_codes', []);
    $admitted[] = strtoupper(trim($code));

    config()->set(
        'legacy_rme_rollout.admission.admitted_branch_codes',
        array_values(array_unique(array_filter($admitted))),
    );
}

/**
 * LEGACY-RME-PDF-ROLL-3 — set the admission allowlist to exactly these codes.
 *
 * Pass an empty array to prove the fail-closed default: capability on, no
 * branch admitted, nothing may be ingested anywhere.
 *
 * @param  list<string>  $codes
 */
function legacyRmeAdmittedBranches(array $codes): void
{
    config()->set(
        'legacy_rme_rollout.admission.admitted_branch_codes',
        array_values(array_unique(array_map(
            static fn (string $code): string => strtoupper(trim($code)),
            $codes,
        ))),
    );
}

/**
 * LEGACY-RME-PDF-FIX-ROLL2-1 — a patient whose Nomor RM is in the CANONICAL
 * format the archive resolves a branch from.
 *
 * PatientFactory's default (`MRN-XXXXXXXX`) is a generic placeholder and is
 * deliberately left alone — other suites pin it. Real patients, however, carry
 * `DG-{KODE_CABANG}-{TAHUN}-{NOMOR}` (the pilot's own patient is
 * `DG-TKM1-2024-9985`), and that is what the legacy archive reads to decide
 * which branch owns the document. A fixture that used the placeholder would be
 * testing a patient shape production does not have.
 */
function legacyRmeArchivablePatient(array $attributes = [], string $branchCode = 'TKM1'): Patient
{
    $branch = legacyRmeBranch($branchCode);

    static $sequence = 0;
    $sequence++;

    return Patient::factory()->create($attributes + [
        'branch_id' => $branch->id,
        'medical_record_number' => sprintf('DG-%s-2024-%04d', $branchCode, $sequence),
    ]);
}

/**
 * LEGACY-RME-PDF-1B — give a patient a NATIVE RME encounter, which is what the
 * 1A date rules compare a legacy date against.
 */
function legacyRmeNativeVisit(Patient $patient, string $visitDate): ClinicVisit
{
    $visit = ClinicVisit::factory()->create([
        'patient_id' => $patient->id,
        'visit_date' => $visitDate,
    ]);

    MedicalRecord::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
    ]);

    return $visit;
}
