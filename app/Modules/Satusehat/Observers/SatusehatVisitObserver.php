<?php

namespace App\Modules\Satusehat\Observers;

use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Satusehat\Services\SatusehatCandidateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Post-commit SATUSEHAT candidate generation trigger. When a visit becomes
 * completed, a readiness candidate is (idempotently) created/refreshed AFTER the
 * surrounding transaction commits. Generation NEVER performs an external request
 * and its failure is swallowed + logged — it can never roll back a clinical or
 * billing transaction.
 */
class SatusehatVisitObserver
{
    public function updated(ClinicVisit $visit): void
    {
        if (! (bool) config('satusehat.candidate.auto_generate', true)) {
            return;
        }

        if (! $visit->wasChanged('status') || $visit->status !== ClinicVisit::STATUS_COMPLETED) {
            return;
        }

        $visitId = (int) $visit->id;

        // Runs after the current transaction commits (or immediately when none).
        DB::afterCommit(function () use ($visitId) {
            try {
                $fresh = ClinicVisit::query()->with('medicalRecord')->find($visitId);
                if ($fresh !== null) {
                    app(SatusehatCandidateService::class)->generateForVisit($fresh);
                }
            } catch (\Throwable $e) {
                Log::warning('SATUSEHAT candidate generation failed after visit completion', [
                    'clinic_visit_id' => $visitId,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }
}
