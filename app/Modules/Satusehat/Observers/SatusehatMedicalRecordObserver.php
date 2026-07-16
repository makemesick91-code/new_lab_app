<?php

namespace App\Modules\Satusehat\Observers;

use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\Satusehat\Services\SatusehatCandidateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Post-commit refresh when a FINAL medical record changes on an already-completed
 * visit (e.g. Sprint 59 editable-after-final). Keeps the candidate's readiness +
 * source hash in sync so a post-approval clinical edit can revoke the approval.
 * Never performs an external request; failure is swallowed + logged.
 */
class SatusehatMedicalRecordObserver
{
    public function saved(MedicalRecord $record): void
    {
        if (! (bool) config('satusehat.candidate.auto_generate', true)) {
            return;
        }

        if ($record->status !== MedicalRecord::STATUS_FINAL) {
            return;
        }

        $visitId = (int) $record->clinic_visit_id;

        DB::afterCommit(function () use ($visitId) {
            try {
                $visit = ClinicVisit::query()
                    ->with('medicalRecord')
                    ->find($visitId);

                if ($visit !== null && $visit->status === ClinicVisit::STATUS_COMPLETED) {
                    app(SatusehatCandidateService::class)->generateForVisit($visit);
                }
            } catch (\Throwable $e) {
                Log::warning('SATUSEHAT candidate refresh failed after medical record finalize', [
                    'clinic_visit_id' => $visitId,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }
}
