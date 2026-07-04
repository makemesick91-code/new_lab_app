<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\Product;
use App\Services\DataQuality\Dq1AuditService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

uses()->group('DataQuality', 'Dq1');

it('registers data quality dq1 audit command', function () {
    expect(Artisan::all())->toHaveKey('data-quality:dq1-audit');
});

it('runs and outputs valid JSON with expected check ids', function () {
    $exitCode = Artisan::call('data-quality:dq1-audit', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    $checkIds = collect($payload['checks'])->pluck('check_id')->all();

    expect($exitCode)->toBe(0)
        ->and($payload)->toHaveKeys(['generated_at', 'environment', 'metadata', 'summary', 'checks', 'privacy'])
        ->and($payload['privacy']['privacy_safe'])->toBeTrue()
        ->and($payload['privacy']['pii_masked'])->toBeTrue()
        ->and($checkIds)->toContain('DQ1-ACID-001')
        ->and($checkIds)->toContain('DQ1-CONSTRAINT-001')
        ->and($checkIds)->toContain('DQ1-DATA-001')
        ->and($checkIds)->toContain('DQ1-DATA-010');
});

it('passes acid checks for configured critical services', function () {
    Artisan::call('data-quality:dq1-audit', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    $acidChecks = collect($payload['checks'])->where('category', 'ACID');

    expect($acidChecks->where('status', 'FAIL'))->toBeEmpty();
});

it('masks ktp in duplicate ktp check output', function () {
    Artisan::call('data-quality:dq1-audit', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    $ktpCheck = collect($payload['checks'])->firstWhere('check_id', 'DQ1-DATA-002');
    $encoded = json_encode($ktpCheck, JSON_THROW_ON_ERROR);

    expect($encoded)->not->toMatch('/\d{16}/');
});

it('fail-on error exits non zero when invalid inventory movement exists', function () {
    $branch = Branch::factory()->create();
    $location = InventoryLocation::factory()->create(['branch_id' => $branch->id]);
    $product = Product::factory()->create();

    DB::table('trx_inventory_movements')->insert([
        'branch_id' => $branch->id,
        'inventory_location_id' => $location->id,
        'product_id' => $product->id,
        'movement_type' => 'ADJUSTMENT_IN',
        'movement_date' => now()->toDateString(),
        'quantity_in' => 5,
        'quantity_out' => 5,
        'unit_cost' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $service = app(Dq1AuditService::class);
    expect($service->countInvalidInventoryMovements())->toBe(1);

    $exitCode = Artisan::call('data-quality:dq1-audit', [
        '--json' => true,
        '--fail-on' => 'error',
    ]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and(collect($payload['checks'])->firstWhere('check_id', 'DQ1-DATA-006')['status'])->toBe('FAIL');
});

it('writes output only under storage app architecture', function () {
    $relative = 'dq1-audit-test-'.uniqid().'.json';

    try {
        $exitCode = Artisan::call('data-quality:dq1-audit', [
            '--json' => true,
            '--output' => $relative,
        ]);

        $fullPath = storage_path('app/architecture/'.$relative);

        expect($exitCode)->toBe(0)
            ->and(file_exists($fullPath))->toBeTrue();
    } finally {
        if (isset($fullPath) && file_exists($fullPath)) {
            unlink($fullPath);
        }
    }
});

it('every dq1 check has required fields', function () {
    Artisan::call('data-quality:dq1-audit', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    foreach ($payload['checks'] as $check) {
        expect($check)->toHaveKeys(['check_id', 'category', 'title', 'status', 'severity', 'message', 'details']);
        expect($check['status'])->toBeIn(['PASS', 'WARN', 'FAIL']);
    }
});
