<?php

use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\Satusehat\Models\SatusehatCandidate;

require_once __DIR__.'/helpers.php';

it('generates a candidate via the post-commit observer when a visit completes', function () {
    config()->set('satusehat.candidate.auto_generate', true);

    // MR is final but the visit is still at the cashier — not eligible yet.
    $ctx = ssMakeVisit(['status' => ClinicVisit::STATUS_CASHIER_PENDING]);
    expect(SatusehatCandidate::where('clinic_visit_id', $ctx['visit']->id)->exists())->toBeFalse();

    // Completing the visit triggers the post-commit observer.
    $ctx['visit']->update(['status' => ClinicVisit::STATUS_COMPLETED, 'completed_at' => now()]);

    expect(SatusehatCandidate::where('clinic_visit_id', $ctx['visit']->id)->exists())->toBeTrue();
});

it('does not auto-generate when auto_generate is disabled', function () {
    config()->set('satusehat.candidate.auto_generate', false);

    $ctx = ssMakeVisit(['status' => ClinicVisit::STATUS_CASHIER_PENDING]);
    $ctx['visit']->update(['status' => ClinicVisit::STATUS_COMPLETED, 'completed_at' => now()]);

    expect(SatusehatCandidate::count())->toBe(0);
});

it('does not break visit completion when candidate generation would fail', function () {
    config()->set('satusehat.candidate.auto_generate', true);

    // A completed visit with no final MR: observer runs, generation is a no-op,
    // and the visit transition itself is unaffected.
    $ctx = ssMakeVisit(['status' => ClinicVisit::STATUS_CASHIER_PENDING]);
    $ctx['mr']->update(['status' => MedicalRecord::STATUS_DRAFT, 'finalized_at' => null]);

    $ctx['visit']->update(['status' => ClinicVisit::STATUS_COMPLETED]);

    expect($ctx['visit']->fresh()->status)->toBe(ClinicVisit::STATUS_COMPLETED)
        ->and(SatusehatCandidate::count())->toBe(0);
});
