<?php

namespace App\Modules\Satusehat\Services;

use App\Models\User;
use App\Modules\Branch\Services\BranchService;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\Satusehat\Models\SatusehatAuditLog;
use App\Modules\Satusehat\Models\SatusehatCandidate;
use App\Modules\Satusehat\Services\Dental\SatusehatDentalReadinessService;
use App\Modules\Satusehat\Support\SatusehatDentalReadinessResult;
use App\Modules\Satusehat\Support\SatusehatReadinessResult;
use App\Modules\Satusehat\Support\SatusehatSourceHasher;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * SATUSEHAT candidate lifecycle. Generation is idempotent (one candidate per
 * visit) and NEVER performs an external request. A completed visit with a FINAL
 * medical record becomes a readiness candidate only — it is never auto-sent.
 *
 * When the clinical source drifts after approval, the candidate transitions to
 * source_changed, the approval is revoked (approved_by retained for audit), and
 * a fresh review is required.
 */
class SatusehatCandidateService
{
    public function __construct(
        private readonly SatusehatReadinessService $readiness,
        private readonly SatusehatDentalReadinessService $dentalReadiness,
        private readonly SatusehatSourceHasher $hasher,
        private readonly SatusehatAuditLogger $audit,
        private readonly BranchService $branches,
    ) {}

    /**
     * Idempotently create/refresh the candidate for an eligible visit. Returns
     * null when the visit is not eligible (never creates a partial candidate).
     * Safe to call repeatedly (retry-safe) — never duplicates.
     */
    public function generateForVisit(ClinicVisit $visit, ?User $actor = null): ?SatusehatCandidate
    {
        $visit->loadMissing(['medicalRecord']);

        if (! $this->isEligible($visit)) {
            return null;
        }

        if (! in_array((int) $visit->branch_id, $this->branches->rmeEnabledIds(), true)) {
            return null;
        }

        $env = (string) config('satusehat.environment');
        $result = $this->readiness->evaluate($visit);
        $hash = $this->hasher->hash($result->facts);
        $dental = $this->dentalReadiness->evaluate($visit);
        $dentalHash = $this->hasher->hash($dental->facts);

        return DB::transaction(function () use ($visit, $env, $result, $hash, $dental, $dentalHash, $actor) {
            $candidate = SatusehatCandidate::firstOrCreate(
                ['clinic_visit_id' => $visit->id],
                [
                    'environment' => $env,
                    'branch_id' => $visit->branch_id,
                    'patient_id' => $visit->patient_id,
                    'doctor_id' => $visit->doctor_id,
                    'medical_record_id' => $visit->medicalRecord?->id,
                    'source_version' => 1,
                    'source_hash' => $hash,
                    'readiness_status' => $result->status,
                    'readiness_reasons' => $result->reasons,
                    'dental_readiness_status' => $dental->status,
                    'dental_readiness_reasons' => $dental->reasons,
                    'dental_source_hash' => $dentalHash,
                    'dental_evaluated_at' => now(),
                    'dental_coverage_snapshot' => $dental->coverage,
                    'review_status' => SatusehatCandidate::REVIEW_PENDING,
                    'eligibility_snapshot' => $result->facts,
                    'created_by' => $actor?->id,
                ]
            );

            $created = $candidate->wasRecentlyCreated;
            $this->applyReadiness($candidate, $result, $hash, $dental, $dentalHash, $actor, $created);

            return $candidate;
        });
    }

    /**
     * Recompute readiness + source hash for an existing candidate and persist,
     * detecting post-approval clinical drift.
     */
    public function refresh(SatusehatCandidate $candidate, ?User $actor = null): SatusehatCandidate
    {
        $visit = $candidate->clinicVisit;
        if ($visit === null) {
            return $candidate;
        }

        $result = $this->readiness->evaluate($visit);
        $hash = $this->hasher->hash($result->facts);
        $dental = $this->dentalReadiness->evaluate($visit);
        $dentalHash = $this->hasher->hash($dental->facts);

        return DB::transaction(function () use ($candidate, $result, $hash, $dental, $dentalHash, $actor) {
            $locked = SatusehatCandidate::query()->lockForUpdate()->findOrFail($candidate->id);
            $this->applyReadiness($locked, $result, $hash, $dental, $dentalHash, $actor, false);

            return $locked;
        });
    }

    /**
     * Approve a READY candidate (server recomputes readiness first). The approval
     * pins approved_source_hash so any later drift can be detected and revoked.
     */
    public function approve(SatusehatCandidate $candidate, User $actor): SatusehatCandidate
    {
        return DB::transaction(function () use ($candidate, $actor) {
            $locked = SatusehatCandidate::query()->lockForUpdate()->findOrFail($candidate->id);
            $visit = $locked->clinicVisit;

            if ($visit === null) {
                throw ValidationException::withMessages(['candidate' => 'Kunjungan sumber tidak ditemukan.']);
            }

            $result = $this->readiness->evaluate($visit);
            $hash = $this->hasher->hash($result->facts);
            $dental = $this->dentalReadiness->evaluate($visit);
            $dentalHash = $this->hasher->hash($dental->facts);

            if (! $result->isReady()) {
                throw ValidationException::withMessages([
                    'candidate' => 'Kandidat belum siap disetujui (readiness belum "ready").',
                ]);
            }

            $locked->fill([
                'readiness_status' => SatusehatCandidate::READINESS_READY,
                'readiness_reasons' => $result->reasons,
                'source_hash' => $hash,
                'approved_source_hash' => $hash,
                'dental_readiness_status' => $dental->status,
                'dental_readiness_reasons' => $dental->reasons,
                'dental_source_hash' => $dentalHash,
                // Pin the dental fingerprint so later dental drift is detected.
                'approved_dental_source_hash' => $dentalHash,
                'dental_evaluated_at' => now(),
                'dental_coverage_snapshot' => $dental->coverage,
                'review_status' => SatusehatCandidate::REVIEW_APPROVED,
                'approved_by' => $actor->id,
                'approved_at' => now(),
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'revoked_at' => null,
                'eligibility_snapshot' => $result->facts,
            ])->save();

            $this->audit->log(
                'satusehat_candidate', $locked->id, SatusehatAuditLog::EVENT_APPROVED,
                'Kandidat disetujui', ['readiness' => $result->status], $locked->branch_id, $actor,
            );

            return $locked;
        });
    }

    /**
     * Exclude a candidate — reason is mandatory.
     */
    public function exclude(SatusehatCandidate $candidate, User $actor, string $reason): SatusehatCandidate
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['exclusion_reason' => 'Alasan pengecualian wajib diisi.']);
        }

        return DB::transaction(function () use ($candidate, $actor, $reason) {
            $locked = SatusehatCandidate::query()->lockForUpdate()->findOrFail($candidate->id);

            $locked->fill([
                'review_status' => SatusehatCandidate::REVIEW_EXCLUDED,
                'excluded_by' => $actor->id,
                'excluded_at' => now(),
                'exclusion_reason' => $reason,
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
            ])->save();

            $this->audit->log(
                'satusehat_candidate', $locked->id, SatusehatAuditLog::EVENT_EXCLUDED,
                'Kandidat dikecualikan', [], $locked->branch_id, $actor,
            );

            return $locked;
        });
    }

    /**
     * @param  list<int>  $eligibleStatuses
     */
    private function isEligible(ClinicVisit $visit): bool
    {
        if ($visit->patient_id === null) {
            return false;
        }

        $statuses = (array) config('satusehat.candidate.eligible_visit_statuses', ['completed']);
        if (! in_array($visit->status, $statuses, true)) {
            return false;
        }

        if (config('satusehat.candidate.require_final_medical_record', true)) {
            $mr = $visit->medicalRecord;
            if ($mr === null || $mr->status !== MedicalRecord::STATUS_FINAL) {
                return false;
            }
        }

        return true;
    }

    private function applyReadiness(
        SatusehatCandidate $candidate,
        SatusehatReadinessResult $result,
        string $hash,
        SatusehatDentalReadinessResult $dental,
        string $dentalHash,
        ?User $actor,
        bool $created,
    ): void {
        $coreDrift = $candidate->isApproved()
            && $candidate->approved_source_hash !== null
            && $candidate->approved_source_hash !== $hash;

        // Dental drift after approval revokes the approval too (source_changed).
        $dentalDrift = $candidate->isApproved()
            && $candidate->approved_dental_source_hash !== null
            && $candidate->approved_dental_source_hash !== $dentalHash;

        $drift = $coreDrift || $dentalDrift;

        $status = $result->status;
        $dentalStatus = $dental->status;
        $review = $candidate->review_status;

        if ($drift) {
            $status = SatusehatCandidate::READINESS_SOURCE_CHANGED;
            $review = SatusehatCandidate::REVIEW_PENDING; // fresh review required
            $candidate->revoked_at = now();
        }
        if ($dentalDrift) {
            $dentalStatus = SatusehatCandidate::DENTAL_SOURCE_CHANGED;
        }

        if (! $created && $candidate->source_hash !== $hash) {
            $candidate->source_version = (int) $candidate->source_version + 1;
        }

        $candidate->fill([
            'readiness_status' => $status,
            'readiness_reasons' => $result->reasons,
            'source_hash' => $hash,
            'dental_readiness_status' => $dentalStatus,
            'dental_readiness_reasons' => $dental->reasons,
            'dental_source_hash' => $dentalHash,
            'dental_evaluated_at' => now(),
            'dental_coverage_snapshot' => $dental->coverage,
            'review_status' => $review,
            'eligibility_snapshot' => $result->facts,
        ])->save();

        $this->audit->log(
            'satusehat_candidate', $candidate->id,
            $created ? SatusehatAuditLog::EVENT_CANDIDATE_CREATED : SatusehatAuditLog::EVENT_CANDIDATE_REFRESHED,
            $created ? 'Kandidat dibuat' : 'Kandidat diperbarui',
            ['readiness' => $status], $candidate->branch_id, $actor,
        );

        $this->audit->log(
            'satusehat_candidate', $candidate->id, SatusehatAuditLog::EVENT_READINESS_CALCULATED,
            'Readiness dihitung: '.$status,
            ['readiness' => $status, 'reason_codes' => array_column($result->reasons, 'code')],
            $candidate->branch_id, $actor,
        );

        if ($drift) {
            $this->audit->log(
                'satusehat_candidate', $candidate->id, SatusehatAuditLog::EVENT_APPROVAL_REVOKED,
                'Persetujuan dicabut karena sumber klinis berubah', [], $candidate->branch_id, $actor,
            );
        }
    }
}
