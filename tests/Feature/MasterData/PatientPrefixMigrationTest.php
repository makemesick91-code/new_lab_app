<?php

use App\Modules\Patient\Models\Patient;

it('migrates RM DG patient numbers to DG without touching existing DG numbers', function () {
    $legacy = Patient::factory()->create(['medical_record_number' => 'RM DG-TLK1-2026-0001']);
    $auto = Patient::factory()->create(['medical_record_number' => 'RM-202606-000099']);
    $alreadyDg = Patient::factory()->create(['medical_record_number' => 'DG-LDK2-2026-0002']);
    $other = Patient::factory()->create(['medical_record_number' => 'LEGACY-RM-001']);

    $migration = include database_path('migrations/2026_06_16_120001_migrate_patient_medical_record_prefix_rm_to_dg.php');
    $migration->up();

    expect($legacy->refresh()->medical_record_number)->toBe('DG-TLK1-2026-0001')
        ->and($auto->refresh()->medical_record_number)->toBe('DG-202606-000099')
        ->and($alreadyDg->refresh()->medical_record_number)->toBe('DG-LDK2-2026-0002')
        ->and($other->refresh()->medical_record_number)->toBe('LEGACY-RM-001');
});

it('skips RM to DG migration when the target number already exists', function () {
    Patient::factory()->create(['medical_record_number' => 'DG-TLK1-2026-0001']);
    $legacy = Patient::factory()->create(['medical_record_number' => 'RM DG-TLK1-2026-0001']);

    $migration = include database_path('migrations/2026_06_16_120001_migrate_patient_medical_record_prefix_rm_to_dg.php');
    $migration->up();

    expect($legacy->refresh()->medical_record_number)->toBe('RM DG-TLK1-2026-0001');
});
