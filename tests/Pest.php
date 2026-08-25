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
use App\Modules\Consent\Models\RmeVisitConsent;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductCategory;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LabService\Models\LabService;
use App\Modules\LegacyRme\Models\LegacyRmeMigrationWave;
use App\Modules\LegacyRme\Models\LegacyRmeWaveBranch;
use App\Modules\LegacyRme\Models\LegacyRmeWaveOperator;
use App\Modules\LegacyRme\Support\LegacyRmeWaveBranchStatus;
use App\Modules\LegacyRme\Support\LegacyRmeWaveStatus;
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
use App\Services\Monitoring\PilotPerformanceSnapshotDiskProbe;
use App\Support\Clinical\ClinicalClock;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\Testing\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
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

/**
 * FIX-LEGACY-RME-ROUTINE-OPS-1 — staff both legacy RME separation-of-duties
 * pairs with distinct accounts.
 *
 * Mirrors the real production topology rather than inventing one: a governance
 * account that registers batches, a branch intake operator that files
 * documents, and a separate checker that both certifies documents and approves
 * batches. Three accounts, and no account holds both halves of either pair.
 *
 * Needed because readiness now verifies that the enforced SOD rules can
 * actually be performed. A suite with no accounts at all is a deployment where
 * nobody can approve anything, and it is now reported as such.
 *
 * @return array{manager: User, maker: User, checker: User}
 */
function legacyRmeStaffSeparationOfDuties(): array
{
    return [
        'manager' => userWith(['manage_legacy_rme_migration_operations', 'view_legacy_rme_migration_operations']),
        'maker' => userWith(['create_legacy_rme_imports', 'view_legacy_rme_imports']),
        'checker' => userWith([
            'approve_legacy_rme_migration_wave',
            'publish_legacy_rme_imports',
            'review_legacy_rme_imports',
            'view_legacy_rme_migration_operations',
        ]),
    ];
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

/**
 * FIX-CLINIC-OPS-BRANCH-CONTEXT-WA-1 (FIX-06) — the clinic's own calendar day.
 *
 * visit_date is a clinical calendar date, so fixtures must use the same
 * Asia/Makassar day production stamps. A raw today() is UTC and drifts from the
 * clinic's day for eight hours of every day.
 */
function clinicalToday(): Carbon
{
    return Carbon::parse(app(ClinicalClock::class)->todayString());
}

/**
 * FIX-CLINIC-OPS-BRANCH-CONTEXT-WA-1 (FIX-03) — activate a Kasir branch-only
 * online context through the real service (same canonical mechanism as Admin
 * Klinik and Perawat).
 */
function rmeMakeKasirActive(User $user, Branch $branch): void
{
    if (! $user->hasRole('Kasir')) {
        $user->assignRole('Kasir');
    }

    app(UserOnlineContextService::class)
        ->startKasirSession($user, (int) $branch->id);
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

    // LEGACY-RME-PDF-ROLL-4 — and the branch is ENROLLED in a running
    // operational wave, for the same reason: the operations layer is enforced
    // for the whole suite, so a test that ingests has to satisfy it exactly as
    // production does. Tests that need a ROLL-4 denial pause the wave, drain the
    // branch or withhold the operator assignment explicitly.
    legacyRmeMigrationWave([$code]);

    return $branch->refresh();
}

/**
 * LEGACY-RME-PDF-ROLL-4 — declare and register the running migration wave.
 *
 * Mirrors what an operator does on a real deployment: config declares the wave
 * label and the approval, and a matching wave row is registered, approved,
 * activated and its branches enrolled. Idempotent, so several fixture branches
 * can join the same wave.
 *
 * The wave row MIRRORS config rather than replacing it — the binding check
 * compares the two, so the fixture has to keep them in step just as production
 * must.
 *
 * @param  list<string>  $branchCodes
 */
function legacyRmeMigrationWave(array $branchCodes, string $waveCode = 'TEST-WAVE'): LegacyRmeMigrationWave
{
    $waveCode = strtoupper(trim($waveCode));
    config()->set('legacy_rme_rollout.admission.wave', $waveCode);

    $codes = array_values(array_unique(array_map(
        static fn (string $code): string => strtoupper(trim($code)),
        $branchCodes,
    )));

    /** @var LegacyRmeMigrationWave $wave */
    $wave = LegacyRmeMigrationWave::query()->firstOrNew(['code' => $waveCode]);

    $wave->forceFill([
        'name' => $wave->exists ? $wave->name : 'Gelombang Uji',
        // Only a NEW wave starts ACTIVE. An existing one keeps whatever status
        // the test put it in: this helper runs again on every
        // legacyRmeArchivablePatient() call, and resetting the status here would
        // silently undo the pause or drain the test is asserting on.
        'status' => $wave->exists ? $wave->status : LegacyRmeWaveStatus::ACTIVE,
        'approval_reference' => (string) config('legacy_rme_rollout.admission.approval_reference', ''),
        'approved_branch_codes' => (array) config('legacy_rme_rollout.admission.approved_branch_codes', []),
        'activated_at' => $wave->activated_at ?? now(),
    ])->save();

    foreach ($codes as $code) {
        $branch = Branch::query()->where('code', $code)->first();

        if ($branch === null) {
            continue;
        }

        /** @var LegacyRmeWaveBranch $enrollment */
        $enrollment = LegacyRmeWaveBranch::query()->firstOrNew([
            'wave_id' => $wave->getKey(),
            'branch_id' => $branch->getKey(),
        ]);

        $enrollment->forceFill([
            'branch_code' => $code,
            'status' => $enrollment->exists ? $enrollment->status : LegacyRmeWaveBranchStatus::ACTIVE,
        ])->save();
    }

    return $wave->refresh();
}

/**
 * LEGACY-RME-PDF-ROLL-4 — assign a user as a migration operator for a branch.
 *
 * ROLL-4 requires an explicit assignment on top of the permission, with no
 * exemption for Super Admin: `Gate::before` grants permissions, and being
 * assigned to a clinic's archive is a domain invariant rather than a permission.
 * Tests that ingest therefore assign their actor, exactly as an operator would
 * be assigned on a real wave.
 */
function legacyRmeAssignOperator(User $user, string $branchCode = 'TKM1', string $waveCode = 'TEST-WAVE'): void
{
    $wave = LegacyRmeMigrationWave::query()->where('code', strtoupper(trim($waveCode)))->first();
    $branch = Branch::query()->where('code', strtoupper(trim($branchCode)))->first();

    if ($wave === null || $branch === null) {
        return;
    }

    /** @var LegacyRmeWaveBranch|null $enrollment */
    $enrollment = LegacyRmeWaveBranch::query()
        ->where('wave_id', $wave->getKey())
        ->where('branch_id', $branch->getKey())
        ->first();

    if ($enrollment === null) {
        return;
    }

    LegacyRmeWaveOperator::query()->updateOrCreate(
        [
            'wave_id' => $wave->getKey(),
            'user_id' => $user->getKey(),
            'branch_id' => $branch->getKey(),
        ],
        [
            'branch_code' => $enrollment->branch_code,
            'assigned_at' => now(),
            'revoked_at' => null,
        ],
    );
}

/**
 * LEGACY-RME-PDF-ROLL-4 — an actor that is BOTH permitted and assigned.
 *
 * The common case for an ingestion test: the ROLL-4 layer requires a permission
 * AND an assignment, so a fixture that supplies only one of them is testing a
 * denial by accident.
 */
function legacyRmeOperator(User $user, string $branchCode = 'TKM1'): User
{
    legacyRmeAssignOperator($user, $branchCode);

    return $user;
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

    // ROLL-3 corrective: an admitted set is only authorized when the wave
    // carries its own approval covering it, so a fixture that admits a branch
    // approves it too. Denial tests override this explicitly.
    legacyRmeApproveWave(
        (string) (config('legacy_rme_rollout.admission.approval_reference') ?: 'TEST-WAVE-APPROVAL'),
        (array) config('legacy_rme_rollout.admission.admitted_branch_codes', []),
    );
}

/**
 * LEGACY-RME-PDF-ROLL-3 — record the current wave's approval.
 *
 * @param  list<string>  $codes  the exact branch set the approval covers
 */
function legacyRmeApproveWave(string $reference, array $codes): void
{
    config()->set('legacy_rme_rollout.admission.approval_reference', $reference);
    config()->set(
        'legacy_rme_rollout.admission.approved_branch_codes',
        array_values(array_unique(array_map(
            static fn (string $code): string => strtoupper(trim($code)),
            $codes,
        ))),
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
 *
 * LEGACY-RME-PDF-HISTORY-1A — the birth date is PINNED, and that is not
 * cosmetic. PatientFactory defaults it to
 * `fake()->dateTimeBetween('-80 years', '-5 years')`, so roughly 3.7% of
 * generated patients were born AFTER the hardcoded legacy dates these suites
 * use (most commonly `2019-04-02`). The 1A rule "patient birth date <= legacy
 * date" then correctly refused the upload and the test failed — with a real
 * ~28% chance per run in LegacyRmeBranchAdmissionTest alone, which is exactly
 * how it surfaced: a red NSF-R011 critical gate on a change that touched
 * neither the date rules nor this fixture.
 *
 * 1990-01-01 is early enough for every legacy date in these suites and matches
 * what the majority of call sites already pass explicitly. A caller that cares
 * about the birth date still wins: PHP's `+` keeps the LEFT operand, so an
 * explicit `date_of_birth` (including an explicit `null`, which exercises the
 * "no recorded birth date" path) overrides this default.
 */
function legacyRmeArchivablePatient(array $attributes = [], string $branchCode = 'TKM1'): Patient
{
    $branch = legacyRmeBranch($branchCode);

    static $sequence = 0;
    $sequence++;

    return Patient::factory()->create($attributes + [
        'branch_id' => $branch->id,
        'medical_record_number' => sprintf('DG-%s-2024-%04d', $branchCode, $sequence),
        'date_of_birth' => '1990-01-01',
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

/**
 * FIX-RME-EXAM-CONSENT-ODONTOGRAM-HISTORY-3 / CORRECTIVE-02 — make a visit a
 * legitimate, consented, ACTIVE encounter.
 *
 * Current-RME authoring now requires POSITIVE authority: the patient must have an
 * `in_progress` examination with a signed consent. `rmeSignedConsentFor()` alone is
 * no longer enough for a fixture that intends to WRITE a record, because a consent
 * on a queued or finished visit authorises nothing.
 *
 * Tests about medical-record mechanics should use this; tests about the gate
 * itself must build the state explicitly so the rules stay under test.
 */
function rmeActiveConsentedEncounter(ClinicVisit $visit): ClinicVisit
{
    // One patient is examined once at a time. The gate fails closed on two
    // concurrent in_progress visits (a second encounter's consent must never
    // authorise writes attributed to the first), so a fixture that opens a new
    // encounter closes any earlier one, exactly as the clinic would.
    ClinicVisit::query()
        ->where('patient_id', $visit->patient_id)
        ->where('id', '!=', $visit->id)
        ->where('status', ClinicVisit::STATUS_IN_PROGRESS)
        ->update(['status' => ClinicVisit::STATUS_COMPLETED]);

    if ($visit->status !== ClinicVisit::STATUS_IN_PROGRESS) {
        $visit->forceFill(['status' => ClinicVisit::STATUS_IN_PROGRESS])->save();
        $visit->refresh();
    }

    rmeSignedConsentFor($visit);

    return $visit;
}

/**
 * FIX-RME-EXAM-CONSENT-ODONTOGRAM-HISTORY-3 / CORRECTIVE-03 — a visit that is a
 * legitimate, consented, ACTIVE encounter and is therefore a writable odontogram
 * workspace.
 *
 * The active odontogram is now gated exactly like the RME beside it, so a fixture
 * that intends to CHART something must build the state the clinic would: the
 * doctor started the examination and the patient signed. Tests about the GATE
 * itself must still build their state explicitly, so the rules stay under test.
 *
 * @param  array<string, mixed>  $attributes
 */
function rmeConsentedOdontogramVisit(array $attributes = []): ClinicVisit
{
    return rmeActiveConsentedEncounter(
        ClinicVisit::factory()->create($attributes + ['status' => ClinicVisit::STATUS_IN_PROGRESS]),
    );
}

/**
 * FIX-RME-CONSENT-WORKFLOW-PRINT-UX-2 / FIX-01 — give a visit a signed consent.
 *
 * RME payment now requires a signed PERSETUJUAN TINDAKAN MEDIS, so any test
 * that exercises payment mechanics (allocation, receivables, receipts, lab
 * candidates) needs one. Those tests are not testing consent, so this writes
 * the record directly rather than driving the signing UI.
 *
 * Tests that ARE about consent — timing, signature evidence, authorisation,
 * branch scope — must go through RmeVisitConsentService instead, so that the
 * rules stay under test. See RmeVisitConsentGateTest.
 */
function rmeSignedConsentFor(ClinicVisit $visit): RmeVisitConsent
{
    return RmeVisitConsent::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
        'patient_name_snapshot' => $visit->patient?->name ?? 'Pasien',
    ]);
}

/**
 * FIX-RME-CONSENT-WORKFLOW-PRINT-UX-2 — shared PDF inspection helpers.
 *
 * These reuse Poppler (`pdfinfo` / `pdftotext`), which is already a required,
 * CI-verified dependency of this repository (LEGACY-RME-PDF-1B installs and
 * verifies it in the critical gate). Nothing new is introduced: the print
 * contracts in this sprint are proven against real renderer output rather than
 * against CSS or template strings.
 */
function popplerBinary(string $binary): string
{
    return (string) config('legacy_rme.processing.'.$binary.'_binary', $binary);
}

function pdfToolAvailable(string $binary): bool
{
    $which = @shell_exec('command -v '.escapeshellarg(popplerBinary($binary)).' 2>/dev/null');

    return is_string($which) && trim($which) !== '';
}

function pdfToTextAvailable(): bool
{
    return pdfToolAvailable('pdftotext');
}

function pdfInfoAvailable(): bool
{
    return pdfToolAvailable('pdfinfo');
}

/**
 * Write PDF bytes to a temp file, run a Poppler binary over it, then clean up.
 *
 * FIX-PDF-TEMPFILE-LEAK-1 — the helper owns EXACTLY ONE path, and it is the
 * one `tempnam()` created.
 *
 * `tempnam()` does not just reserve a name, it creates the file. Deriving a
 * second path from the returned one — the previous `.'.pdf'` — therefore made
 * this helper the owner of two artifacts while its `finally` cleaned only the
 * derived one, stranding a zero-byte orphan on every PDF assertion in the
 * suite. Keeping the allocation itself as the document path removes the second
 * artifact rather than adding a second unlink, so there is no path left to
 * forget.
 *
 * The suffix is not needed: Poppler dispatches on the file header, not on the
 * filename, and `pdftotext`/`pdfinfo` read this path identically with or
 * without one. Dropping it also keeps the 0600 mode `tempnam()` assigns, and
 * closes the window in which the derived name — never atomically reserved —
 * could have been created by another process between derivation and write.
 *
 * Pinned by tests/Feature/Cicd/PdfTempFileLifecycleContractTest.php.
 */
function pdfWithTempFile(string $bytes, callable $callback): mixed
{
    $path = tempnam(sys_get_temp_dir(), 'dms-pdf-');
    file_put_contents($path, $bytes);

    try {
        return $callback($path);
    } finally {
        @unlink($path);
    }
}

/**
 * Extract the text layer of a PDF. Callers asserting that something is ABSENT
 * must also assert that something expected is PRESENT, otherwise the negative
 * assertion passes vacuously whenever extraction fails.
 */
function pdfExtractText(string $bytes): string
{
    return pdfWithTempFile($bytes, function (string $path): string {
        $out = @shell_exec(
            escapeshellarg(popplerBinary('pdftotext')).' -layout '.escapeshellarg($path).' - 2>/dev/null'
        );

        return is_string($out) ? $out : '';
    });
}

/**
 * Page count of a rendered PDF, straight from `pdfinfo`. Returns 0 when the
 * document cannot be read, which FAILS a `toBe(1)` assertion rather than
 * silently passing it.
 */
function pdfPageCount(string $bytes): int
{
    return pdfWithTempFile($bytes, function (string $path): int {
        $out = @shell_exec(
            escapeshellarg(popplerBinary('pdfinfo')).' '.escapeshellarg($path).' 2>/dev/null'
        );

        if (! is_string($out) || ! preg_match('/^Pages:\s+(\d+)/m', $out, $m)) {
            return 0;
        }

        return (int) $m[1];
    });
}

/*
|--------------------------------------------------------------------------
| Wrap-tolerant reading of `pdftotext -layout` output
|--------------------------------------------------------------------------
|
| FIX-RECEIPT-PDF-TEXT-CONTIGUITY-1.
|
| `pdftotext -layout` serialises the VISUAL layout of a PDF, so a value that is
| semantically one string is not necessarily one contiguous substring of the
| extraction. A value that overflows its cell wraps, and the columns beside it
| are interleaved into the same lines. On the RME receipt a 26-character patient
| name comes back as:
|
|   Nama Pasien     Miss Marcella O'Conner     No. Rekam Medis     MRN-U8XPPPBS
|                   DVM
|
| and in the item table the description is split AROUND its own numeric row:
|
|   Perawatan Saluran Akar Gigi Molar Pertama Rahang Bawah
|                                        1     Rp 1.250.000     Rp 1.250.000
|   Kanan Kunjungan Kedua
|
| So `expect($text)->toContain($patient->name)` is a broken contract: it asserts
| a layout property (never wraps) while claiming to assert a content property
| (the name is on the receipt). It fails on ~6% of faker names even though the
| document is perfectly correct, and no amount of escaping changes that.
|
| The fix is to read the value out of ITS OWN COLUMN and rejoin the wrap, which
| is both wrap-tolerant and stricter than the substring search it replaces:
| text from a neighbouring column can no longer satisfy the assertion, because
| it is outside the band being read.
|
| Deliberately NOT done: stripping all whitespace from the extraction, or
| flattening the whole page into one string. Both would let the patient-name
| column and the adjacent medical-record column fuse into a single haystack and
| match things that are not actually in the field under test.
*/

/**
 * Collapse whitespace runs so a rejoined wrap compares equal to the original.
 *
 * This normalises SPACING only — no character is removed — so it cannot make a
 * missing or wrong value look present.
 */
function pdfNormalizeText(string $value): string
{
    return trim((string) preg_replace('/\s+/u', ' ', $value));
}

/**
 * Split one layout line into its column cells.
 *
 * `-layout` separates columns with runs of two or more spaces and pads with
 * single spaces inside a cell, so the run length is what distinguishes "next
 * column" from "next word". Each cell is returned with the character column it
 * starts at, which is what makes a column band addressable.
 *
 * @return array<int, array{col: int, text: string}>
 */
function pdfLayoutSegments(string $line): array
{
    $chars = preg_split('//u', rtrim($line, "\r"), -1, PREG_SPLIT_NO_EMPTY) ?: [];

    $segments = [];
    $current = '';
    $start = 0;
    $spaces = 0;

    foreach ($chars as $index => $char) {
        if ($char === ' ') {
            $spaces++;

            if ($current !== '' && $spaces >= 2) {
                $segments[] = ['col' => $start, 'text' => $current];
                $current = '';
            }

            continue;
        }

        if ($current === '') {
            $start = $index;
        } elseif ($spaces === 1) {
            $current .= ' ';
        }

        $current .= $char;
        $spaces = 0;
    }

    if ($current !== '') {
        $segments[] = ['col' => $start, 'text' => $current];
    }

    return $segments;
}

/**
 * The value block printed BENEATH a label that owns its own line.
 *
 * The RME visit PDF stacks its identity fields instead of tabulating them:
 *
 *   NAMA PASIEN
 *
 *   Alexandria Catherine … Purnomo
 *   Hadiwinata Suryadi
 *
 *   NO. REKAM MEDIS
 *
 * A blank line separates one field from the next, but never separates a wrapped
 * value from its own continuation, so the first non-blank run after the label is
 * the value and the blank line after it closes the field.
 *
 * @param  array<int, string>  $lines
 */
function pdfLayoutStackedValue(array $lines, int $labelIndex, int $column): string
{
    $fragments = [];

    for ($next = $labelIndex + 1; $next < count($lines); $next++) {
        $slice = trim(mb_substr($lines[$next], $column));

        if ($slice === '') {
            // Still looking for the value, or the value just ended.
            if ($fragments === []) {
                continue;
            }

            break;
        }

        $fragments[] = $slice;
    }

    return pdfNormalizeText(implode(' ', $fragments));
}

/**
 * The value of a labelled cell, rejoined across the wraps the layout
 * introduced — whether the value sits BESIDE the label or BENEATH it.
 *
 * The value is read from the column band that starts where the value cell
 * starts and ends where the NEXT cell on that row starts, so the neighbouring
 * column is structurally excluded. A following line continues the cell only
 * while the label's own column stays empty — the moment anything appears there
 * a new row has begun and the cell is closed.
 *
 * Returns null when the label is not in the document at all, so a missing
 * label fails an assertion instead of quietly comparing empty strings.
 */
function pdfLayoutFieldValue(string $text, string $label): ?string
{
    $lines = preg_split('/\R/u', $text) ?: [];

    // Labels are routinely CSS-uppercased on the way into the PDF, so match the
    // label case-insensitively. The VALUE is always returned exactly as printed.
    $label = mb_strtolower(pdfNormalizeText($label));

    foreach ($lines as $index => $line) {
        $segments = pdfLayoutSegments($line);

        foreach ($segments as $position => $segment) {
            if (mb_strtolower($segment['text']) !== $label) {
                continue;
            }

            $value = $segments[$position + 1] ?? null;

            if ($value === null) {
                /*
                 * Nothing beside the label. Either the label owns its line and
                 * the value is stacked beneath it, or the cell is empty — and
                 * an empty cell must not silently borrow the row below.
                 */
                return count($segments) === 1
                    ? pdfLayoutStackedValue($lines, $index, $segment['col'])
                    : '';
            }

            $valueStart = $value['col'];
            $neighbour = $segments[$position + 2] ?? null;
            $width = $neighbour !== null ? $neighbour['col'] - $valueStart : null;

            $fragments = [$value['text']];

            for ($next = $index + 1; $next < count($lines); $next++) {
                // Anything in the label's own column means the next row started.
                if (trim(mb_substr($lines[$next], 0, $valueStart)) !== '') {
                    break;
                }

                $slice = trim(mb_substr($lines[$next], $valueStart, $width));

                if ($slice === '') {
                    break;
                }

                $fragments[] = $slice;
            }

            return pdfNormalizeText(implode(' ', $fragments));
        }
    }

    return null;
}

/**
 * Every full-width line of the layout, rejoined in reading order.
 *
 * A centred header or a section title owns its whole line, so it can be reached
 * neither by a label nor by a column heading. Joining ONLY the lines that hold a
 * single cell keeps every tabular row — and therefore every opportunity for two
 * columns to fuse into a value that was never printed — out of the haystack.
 */
function pdfLayoutFullWidthText(string $text): string
{
    $collected = [];

    foreach (preg_split('/\R/u', $text) ?: [] as $line) {
        $segments = pdfLayoutSegments($line);

        if (count($segments) === 1) {
            $collected[] = $segments[0]['text'];
        }
    }

    return pdfNormalizeText(implode(' ', $collected));
}

/**
 * Everything printed under one column heading, rejoined in reading order.
 *
 * Used for tabular cells, where a wrapped value is split around its own numeric
 * row and so cannot be rebuilt by looking at the following line alone. The band
 * runs from the heading's column to the next heading's column, which keeps the
 * quantity and money columns out of the haystack.
 *
 * Both headings must appear on the same line — that is what proves they are
 * headings of the same table rather than two unrelated words.
 *
 * Returns null when the heading row is not found.
 */
function pdfLayoutColumnText(string $text, string $fromHeading, ?string $toHeading = null): ?string
{
    $lines = preg_split('/\R/u', $text) ?: [];
    $fromHeading = pdfNormalizeText($fromHeading);
    $toHeading = $toHeading === null ? null : pdfNormalizeText($toHeading);

    foreach ($lines as $index => $line) {
        $start = null;
        $width = null;

        foreach (pdfLayoutSegments($line) as $segment) {
            if ($start === null) {
                if ($segment['text'] === $fromHeading) {
                    $start = $segment['col'];
                }

                continue;
            }

            if ($toHeading !== null && $segment['text'] === $toHeading) {
                $width = $segment['col'] - $start;
                break;
            }
        }

        if ($start === null) {
            continue;
        }

        if ($toHeading !== null && $width === null) {
            continue;
        }

        $collected = [];

        for ($next = $index + 1; $next < count($lines); $next++) {
            $slice = trim(mb_substr($lines[$next], $start, $width));

            if ($slice !== '') {
                $collected[] = $slice;
            }
        }

        return pdfNormalizeText(implode(' ', $collected));
    }

    return null;
}

/**
 * A pilot-snapshot disk probe pinned to a fixed number of free gigabytes (or null
 * for "unreadable").
 *
 * `overall_status` is the worst of every snapshot section, so the free disk of
 * whichever machine runs the suite silently participates in it. Left unpinned, an
 * assertion that the aggregate is 'OK' passes on a spacious CI runner and fails on
 * a nearly full laptop, and — worse — an assertion that it is 'WATCH' passes on the
 * full laptop even if the log status stopped reaching the aggregate at all. Pinning
 * the disk makes the aggregate a property of the code under test on every host.
 *
 * Pass a value comfortably clear of the classifier's 20 GB WATCH boundary when the
 * disk is meant to be uninteresting; pass a low value to exercise the boundary.
 */
function pilotSnapshotDiskProbe(?float $freeGb): PilotPerformanceSnapshotDiskProbe
{
    return new class($freeGb) extends PilotPerformanceSnapshotDiskProbe
    {
        public function __construct(private readonly ?float $freeGb) {}

        public function freeBytes(string $path): ?float
        {
            return $this->freeGb === null ? null : $this->freeGb * 1024 * 1024 * 1024;
        }
    };
}
