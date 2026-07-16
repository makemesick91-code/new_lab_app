<?php

namespace App\Modules\Satusehat\Services\DataQuality;

use App\Models\User;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Satusehat\Gateways\SatusehatGatewayInterface;
use App\Modules\Satusehat\Models\SatusehatAuditLog;
use App\Modules\Satusehat\Models\SatusehatCandidate;
use App\Modules\Satusehat\Services\SatusehatAuditLogger;
use App\Modules\Satusehat\Services\SatusehatCandidateService;
use App\Modules\Satusehat\Services\SatusehatFhirPreviewBuilder;
use App\Modules\Satusehat\Services\SatusehatReadinessService;
use App\Modules\Satusehat\Services\SatusehatSubmissionService;
use App\Modules\Satusehat\Support\SatusehatProductionActivationGuard;
use App\Modules\Satusehat\Support\SatusehatSourceHasher;
use Illuminate\Validation\ValidationException;

/**
 * SATUSEHAT-4A — credential-independent end-to-end rehearsal.
 *
 * Drives the synthetic candidate through the full internal pipeline and STOPS
 * honestly at the external credential wall. NEVER performs a network request,
 * NEVER reports `submitted`/`succeeded`, NEVER fabricates an identifier.
 * Final state is either BLOCKED_EXTERNAL_CREDENTIAL (internal pipeline clean)
 * or the top remaining internal operational status.
 */
class SatusehatRehearsalService
{
    public function __construct(
        private readonly SatusehatCandidateService $candidates,
        private readonly SatusehatDataQualityIssueService $issues,
        private readonly SatusehatReadinessService $readiness,
        private readonly SatusehatOperationalReadinessService $operational,
        private readonly SatusehatFhirPreviewBuilder $preview,
        private readonly SatusehatGatewayInterface $gateway,
        private readonly SatusehatProductionActivationGuard $productionGuard,
        private readonly SatusehatAuditLogger $audit,
    ) {}

    /**
     * @return array{final_state: string, internal_pipeline_clean: bool, stages: list<array{stage: string, status: string, detail: string}>}
     */
    public function rehearse(?User $actor = null, bool $prepareBatch = false, bool $dryRun = true): array
    {
        $stages = [];
        $push = function (string $stage, string $status, string $detail) use (&$stages): void {
            $stages[] = ['stage' => $stage, 'status' => $status, 'detail' => $detail];
        };

        // Stage 1 — synthetic pack present (the rehearsal ONLY ever touches the
        // synthetic campaign; real visits are out of bounds by design).
        $visit = ClinicVisit::query()
            ->where('visit_number', 'SYN4A-0001')
            ->with(['patient', 'doctor', 'medicalRecord', 'branch', 'clinicRoom'])
            ->first();

        if ($visit === null) {
            $push('synthetic_data', 'fail', 'Paket sintetis belum tersedia — jalankan satusehat:synthetic-pilot seed.');

            return ['final_state' => 'MISSING_SYNTHETIC_PACK', 'internal_pipeline_clean' => false, 'stages' => $stages];
        }
        $push('synthetic_data', 'pass', 'Paket sintetis ditemukan (visit SYN4A-0001).');

        // Stage 2 — candidate generation (idempotent).
        $candidate = $this->candidates->generateForVisit($visit, $actor)
            ?? SatusehatCandidate::query()->where('clinic_visit_id', $visit->id)->first();
        if ($candidate === null) {
            $push('candidate_generation', 'fail', 'Kandidat tidak dapat dibuat (cek eligibilitas visit sintetis).');

            return ['final_state' => 'CANDIDATE_GENERATION_FAILED', 'internal_pipeline_clean' => false, 'stages' => $stages];
        }
        $push('candidate_generation', 'pass', "Kandidat #{$candidate->id} tersedia (idempotent).");

        // Stage 3 — readiness evaluation + issue detection (rule engine).
        $issueSummary = $this->issues->syncForCandidate($candidate, $actor);
        $candidate->refresh();
        $push('readiness_evaluation', 'pass', "Readiness: {$candidate->readiness_status}.");
        $push('issue_detection', 'pass', sprintf(
            '%d isu terdeteksi, %d terbuka setelah auto-resolve.',
            $issueSummary['detected'], $issueSummary['open'],
        ));

        // Stage 4 — source revalidation: two consecutive evaluations must hash
        // identically (deterministic engine, no drift on rerun).
        $first = $this->readiness->evaluate($visit);
        $second = $this->readiness->evaluate($visit);
        $hasher = app(SatusehatSourceHasher::class);
        $stable = $hasher->hash($first->facts) === $hasher->hash($second->facts);
        $push('source_revalidation', $stable ? 'pass' : 'fail', $stable
            ? 'Hash sumber deterministik dan stabil pada evaluasi ulang.'
            : 'Hash sumber TIDAK stabil — periksa field non-deterministik.');

        // Stage 5 — local FHIR builders + conformance (local only, no network).
        $previewPack = $this->preview->build($visit, $first);
        $resourceCount = count($previewPack['resources'] ?? []);
        $push('local_fhir_preview', 'pass', "{$resourceCount} resource lokal dibangun (belum dikirim, belum diverifikasi SATUSEHAT).");

        // Stage 6 — operational classification (internal vs external remaining).
        $operationalStatus = $this->operational->operationalStatusFor($candidate);
        $externalOnly = $operationalStatus === 'BLOCKED_EXTERNAL_CREDENTIAL';
        $internalClean = $externalOnly || $operationalStatus === 'READY_INTERNAL';
        $push('operational_classification', $internalClean ? 'pass' : 'blocked_internal', "Status operasional: {$operationalStatus}.");

        // Stage 7 — approval + local batch preparation (optional, controlled write).
        if ($prepareBatch && ! $dryRun) {
            $this->rehearseBatchPreparation($candidate, $actor, $push);
        } else {
            $push('batch_preparation', 'skipped', 'Dry-run — tidak ada batch yang disiapkan.');
        }

        // Stage 8 — outbound gate: MUST be blocked while credentials are absent.
        $gatewayEnabled = $this->gateway->isEnabled();
        $push('outbound_gate', $gatewayEnabled ? 'fail' : 'pass', $gatewayEnabled
            ? 'PERINGATAN: gateway aktif — rehearsal mengharapkan gateway NONAKTIF.'
            : 'Gateway eksternal NONAKTIF (fail-closed) — tidak ada request keluar.');

        // Stage 9 — production guard must stay blocked.
        $guard = $this->productionGuard->evaluate();
        $push('production_guard', $guard['allowed'] ? 'fail' : 'pass', $guard['allowed']
            ? 'PERINGATAN: production guard TIDAK terblokir.'
            : 'Produksi tetap terblokir ('.count($guard['blockers']).' blocker).');

        $finalState = match (true) {
            ! $internalClean => $operationalStatus,
            $gatewayEnabled => 'READY_INTERNAL', // internal done; outbound governed elsewhere — never "submitted"
            default => 'BLOCKED_EXTERNAL_CREDENTIAL',
        };

        $result = [
            'final_state' => $finalState,
            'internal_pipeline_clean' => $internalClean && $stable,
            'stages' => $stages,
        ];

        $this->audit->log(
            'satusehat_candidate',
            (int) $candidate->id,
            SatusehatAuditLog::EVENT_REHEARSAL_RUN,
            'Credential-independent rehearsal run',
            ['final_state' => $finalState, 'internal_pipeline_clean' => $result['internal_pipeline_clean']],
            (int) $candidate->branch_id,
            $actor,
        );

        return $result;
    }

    private function rehearseBatchPreparation(SatusehatCandidate $candidate, ?User $actor, \Closure $push): void
    {
        if ($actor === null) {
            $push('batch_preparation', 'skipped', 'Butuh aktor eksplisit untuk persiapan batch.');

            return;
        }

        try {
            $candidate->refresh();
            if ($candidate->canApprove()) {
                $this->candidates->approve($candidate, $actor);
            }
            $batch = app(SatusehatSubmissionService::class)->prepare(
                [(int) $candidate->id],
                [(int) $candidate->branch_id],
                $actor,
            );
            $push('batch_preparation', 'pass', "Batch lokal #{$batch->id} disiapkan (status {$batch->status}); TIDAK ada pengiriman.");
        } catch (ValidationException $e) {
            $push('batch_preparation', 'blocked_external', 'Persiapan batch tertahan (kandidat belum siap — normal tanpa identifier eksternal).');
        }
    }
}
