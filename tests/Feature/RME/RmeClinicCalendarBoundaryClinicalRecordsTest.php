<?php

/**
 * SECURITY-F4A1-CLINIC-CALENDAR-CLINICAL-RECORDS-1 — part of SECURITY-F4A (split by module).
 * The clinic calendar (Asia/Makassar, UTC+8), never the UTC one.
 *
 * The application timezone is UTC. Between 00:00 and 08:00 WITA the UTC date is
 * still yesterday, so "today" counts, receivable aging, due follow-ups and
 * date defaults named the previous clinic day for eight hours of every day.
 *
 * Every case freezes the clock at a UTC instant chosen so that the UTC date and
 * the clinic date differ (or, for the control, agree):
 *
 *   2026-10-27 15:59 UTC = 2026-10-27 23:59 WITA  (same date)
 *   2026-10-27 16:01 UTC = 2026-10-28 00:01 WITA  (UTC is still the 27th)
 *   2026-10-27 23:59 UTC = 2026-10-28 07:59 WITA  (UTC is still the 27th)
 */

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\MedicalRecord\Models\ClinicalDiagnosis;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Services\ClinicalDiagnosisService;
use App\Modules\MedicalRecord\Services\MedicalRecordService;
use App\Modules\PatientMerge\Services\PatientMergeCaseService;
use App\Modules\Prescription\Services\RmePrescriptionService;
use Database\Seeders\BranchSeeder;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    Branch::where('code', Branch::MAIN_CODE)->update(['is_rme_enabled' => false]);
    $this->branch = Branch::factory()->create(['code' => 'ATG3', 'is_active' => true, 'is_rme_enabled' => true]);
    $this->cashier = userWith(['manage_rme_billing']);
});

afterEach(function () {
    freeTestClock();
});
dataset('instants', [
    '23:59 WITA' => ['2026-10-27 15:59:00', '2026-10-27'],
    '00:01 WITA' => ['2026-10-27 16:01:00', '2026-10-28'],
    '07:59 WITA' => ['2026-10-27 23:59:00', '2026-10-28'],
]);
it('defaults a prescription to the clinic date', function (string $instant, string $clinicDate) {
    pinTestClock($instant);
    $visit = ClinicVisit::factory()->create(['branch_id' => $this->branch->id]);

    expect(app(RmePrescriptionService::class)->buildDefaults($visit)['prescription_date'])->toBe($clinicDate);
})->with('instants');
it('accepts a merge date of birth on the clinic date and refuses the next one', function () {
    pinTestClock('2026-10-27 16:01:00');
    $service = app(PatientMergeCaseService::class);
    $valid = fn (string $date) => (fn (string $v) => $this->validDate($v))->call($service, $date);

    expect($valid('2026-10-28'))->toBeTrue()
        ->and($valid('2026-10-29'))->toBeFalse();
});
it('stamps a deprecated diagnosis effective_to with the clinic date', function () {
    pinTestClock('2026-10-27 16:30:00');
    $diagnosis = ClinicalDiagnosis::factory()->create(['status' => ClinicalDiagnosis::STATUS_ACTIVE]);

    $deprecated = app(ClinicalDiagnosisService::class)->deprecate($diagnosis, User::factory()->create(), null, 'Diganti kode baru');

    expect($deprecated->fresh()->effective_to->toDateString())->toBe('2026-10-28');
});
it('counts records finalized on the clinic day, not on the UTC day', function () {
    pinTestClock('2026-10-27 23:59:00'); // clinic 2026-10-28 07:59

    $onClinicDay = MedicalRecord::factory()->create(['branch_id' => $this->branch->id, 'status' => MedicalRecord::STATUS_FINAL]);
    $previousClinicDay = MedicalRecord::factory()->create(['branch_id' => $this->branch->id, 'status' => MedicalRecord::STATUS_FINAL]);
    MedicalRecord::query()->whereKey($onClinicDay->id)->update(['finalized_at' => '2026-10-27 16:30:00']);       // 10-28 00:30 WITA
    MedicalRecord::query()->whereKey($previousClinicDay->id)->update(['finalized_at' => '2026-10-27 15:30:00']); // 10-27 23:30 WITA

    expect(app(MedicalRecordService::class)->finalizedTodayCount([$this->branch->id]))->toBe(1);
});
