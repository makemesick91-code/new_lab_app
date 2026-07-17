<?php

use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\MedicalRecord\Models\ClinicalDiagnosis;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\Satusehat\Services\SatusehatDiagnosisAdoptionService;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/helpers.php';

beforeEach(fn () => config()->set('satusehat.candidate.auto_generate', false));

/** Bulk medical records (+ visits) with a structured diagnosis on every 2nd. */
function s4bBulkRecords(array $ctx, int $count, int $startQueue): void
{
    $master = ClinicalDiagnosis::query()->firstOrCreate(
        ['code_system' => 'ICD-10', 'code' => 'K02.9'],
        ['display' => 'Dental caries, unspecified', 'status' => ClinicalDiagnosis::STATUS_ACTIVE],
    );

    $now = now();
    $dxRows = [];
    foreach (range(1, $count) as $i) {
        $q = $startQueue + $i;
        $visit = ClinicVisit::factory()->create([
            'branch_id' => $ctx['branch']->id,
            'clinic_id' => $ctx['visit']->clinic_id,
            'patient_id' => $ctx['patient']->id,
            'doctor_id' => $ctx['doctor']->id,
            'visit_number' => 'PERF4B-'.$q,
            'queue_number' => $q,
            'visit_date' => $now->toDateString(),
            'status' => ClinicVisit::STATUS_COMPLETED,
        ]);
        $mr = MedicalRecord::factory()->create([
            'clinic_visit_id' => $visit->id,
            'branch_id' => $ctx['branch']->id,
            'patient_id' => $ctx['patient']->id,
            'doctor_id' => $ctx['doctor']->id,
            'status' => MedicalRecord::STATUS_FINAL,
        ]);
        if ($i % 2 === 0) {
            $dxRows[] = [
                'medical_record_id' => $mr->id,
                'clinic_visit_id' => $visit->id,
                'branch_id' => $ctx['branch']->id,
                'clinical_diagnosis_id' => $master->id,
                'diagnosis_role' => 'primary',
                'diagnosed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
    }
    DB::table('trx_medical_record_diagnoses')->insert($dxRows);
}

/**
 * SATUSEHAT-4B — adoption analytics must run a CONSTANT number of queries
 * regardless of record volume (aggregate SQL + batched name lookups — no N+1),
 * and the doctor-facing search stays bounded.
 */
it('adoption metrics query count stays constant as record volume grows', function () {
    $ctx = ssMakeVisit(['queue_number' => 700]);
    $service = app(SatusehatDiagnosisAdoptionService::class);

    $count = function (callable $fn): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $fn();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    s4bBulkRecords($ctx, 10, 4000);
    $small = $count(fn () => $service->metrics(['branch_id' => $ctx['branch']->id]));

    s4bBulkRecords($ctx, 90, 5000); // now 100 records, 50 with diagnosis
    $large = $count(fn () => $service->metrics(['branch_id' => $ctx['branch']->id]));

    expect($large)->toBe($small)
        ->and($small)->toBeLessThan(20);

    // Sanity: the metrics themselves reflect the volume honestly.
    $metrics = $service->metrics(['branch_id' => $ctx['branch']->id]);
    expect($metrics['eligible_visits'])->toBeGreaterThanOrEqual(100)
        ->and($metrics['with_structured_diagnosis'])->toBeGreaterThanOrEqual(50);
});

it('the doctor-facing diagnosis search stays bounded and safely escapes wildcards', function () {
    seedAccessControl();
    ClinicalDiagnosis::factory()->count(60)->create();
    $user = userWith(['view_clinic_visits']);

    // Bounded: never more than 50 rows even with a broad match.
    $response = $this->actingAs($user)->getJson(route('rme.diagnoses.search', ['q' => 'diagnosis']));
    $response->assertOk();
    expect(count($response->json('data')))->toBeLessThanOrEqual(50);

    // Wildcards are escaped — "%" alone matches nothing rather than everything.
    $wild = $this->actingAs($user)->getJson(route('rme.diagnoses.search', ['q' => '%']));
    expect($wild->json('data'))->toBe([]);
});
