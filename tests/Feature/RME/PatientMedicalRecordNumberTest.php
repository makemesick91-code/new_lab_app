<?php

use App\Modules\Patient\Models\Patient;
use App\Modules\Patient\Services\PatientMedicalRecordNumberService;
use Carbon\Carbon;

beforeEach(function () {
    $this->service = app(PatientMedicalRecordNumberService::class);
});

it('composes the finalized DG format', function () {
    expect($this->service->compose('TKM1', 2026, '0001'))
        ->toBe('DG-TKM1-2026-0001');
});

it('uppercases and trims the branch code', function () {
    expect($this->service->compose(' ldk2 ', 2026, '25'))
        ->toBe('DG-LDK2-2026-25');
});

it('preserves manual RM number leading zeros without padding', function () {
    expect($this->service->compose('ATG3', 2026, '0150'))->toBe('DG-ATG3-2026-0150')
        ->and($this->service->compose('ATG3', 2026, '7'))->toBe('DG-ATG3-2026-7');
});

it('derives the year from the registration date', function () {
    expect($this->service->composeForRegistration('TKM1', Carbon::parse('2026-03-15'), '0001'))
        ->toBe('DG-TKM1-2026-0001');
});

it('rejects a non four digit year', function () {
    expect(fn () => $this->service->compose('TKM1', 26, '0001'))
        ->toThrow(InvalidArgumentException::class);
});

it('requires a branch code and a manual number', function () {
    expect(fn () => $this->service->compose('', 2026, '0001'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->service->compose('TKM1', 2026, '  '))->toThrow(InvalidArgumentException::class);
});

it('detects an existing final medical record number', function () {
    Patient::factory()->create(['medical_record_number' => 'DG-TKM1-2026-0001']);

    expect($this->service->exists('DG-TKM1-2026-0001'))->toBeTrue()
        ->and($this->service->exists('DG-TKM1-2026-9999'))->toBeFalse();
});

it('does not auto-generate or auto-increment the manual number', function () {
    // The same components always produce the same value — no sequence logic.
    $first = $this->service->compose('TKM1', 2026, '0001');
    $second = $this->service->compose('TKM1', 2026, '0001');

    expect($first)->toBe($second)->toBe('DG-TKM1-2026-0001');
});

it('allows the same manual number across branches because the final value differs', function () {
    $a = $this->service->compose('TKM1', 2026, '0001');
    $b = $this->service->compose('LDK2', 2026, '0001');

    expect($a)->not->toBe($b)
        ->and($a)->toBe('DG-TKM1-2026-0001')
        ->and($b)->toBe('DG-LDK2-2026-0001');
});
