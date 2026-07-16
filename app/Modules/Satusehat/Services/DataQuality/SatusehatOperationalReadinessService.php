<?php

namespace App\Modules\Satusehat\Services\DataQuality;

use App\Modules\Branch\Services\BranchService;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\MedicalRecord\Models\MedicalRecordDiagnosis;
use App\Modules\Satusehat\Interfaces\SatusehatCandidateRepositoryInterface;
use App\Modules\Satusehat\Interfaces\SatusehatDataQualityIssueRepositoryInterface;
use App\Modules\Satusehat\Models\SatusehatCandidate;
use App\Modules\Satusehat\Models\SatusehatCodeMapping;
use App\Modules\Satusehat\Models\SatusehatEntityIdentifier;
use App\Modules\Satusehat\Support\SatusehatOperationalStatusResolver;
use App\Modules\Treatment\Models\Treatment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * SATUSEHAT-4A — operational readiness dashboard aggregation. Read-only,
 * branch-scoped, bounded (SQL GROUP BY aggregates + per-page batched lookups —
 * constant query count regardless of candidate volume). Never HTTP, never PII.
 */
class SatusehatOperationalReadinessService
{
    private const PRACTITIONER_LIMIT = 200;

    private const LOCATION_LIMIT = 200;

    public function __construct(
        private readonly SatusehatCandidateRepositoryInterface $candidates,
        private readonly SatusehatDataQualityIssueRepositoryInterface $issues,
        private readonly SatusehatOperationalStatusResolver $resolver,
        private readonly BranchService $branches,
    ) {}

    /**
     * Headline metrics for the readiness dashboard.
     *
     * @param  list<int>  $branchIds
     * @return array<string, mixed>
     */
    public function metrics(array $branchIds): array
    {
        $scoped = fn () => SatusehatCandidate::query()
            ->when($branchIds === [], fn ($q) => $q->whereRaw('1 = 0'))
            ->when($branchIds !== [], fn ($q) => $q->whereIn('branch_id', $branchIds));

        $byReadiness = $scoped()->selectRaw('readiness_status, count(*) as total')
            ->groupBy('readiness_status')->pluck('total', 'readiness_status')->map(fn ($v) => (int) $v)->all();

        $byDental = $scoped()->whereNotNull('dental_readiness_status')
            ->selectRaw('dental_readiness_status, count(*) as total')
            ->groupBy('dental_readiness_status')->pluck('total', 'dental_readiness_status')->map(fn ($v) => (int) $v)->all();

        $byReview = $scoped()->selectRaw('review_status, count(*) as total')
            ->groupBy('review_status')->pluck('total', 'review_status')->map(fn ($v) => (int) $v)->all();

        $byBranch = $scoped()->selectRaw('branch_id, count(*) as total')
            ->groupBy('branch_id')->pluck('total', 'branch_id')->map(fn ($v) => (int) $v)->all();

        $issueAggregates = $this->issues->aggregates($branchIds);

        return [
            'total_candidates' => array_sum($byReadiness),
            'by_readiness_status' => $byReadiness,
            'by_dental_status' => $byDental,
            'by_review_status' => $byReview,
            'by_branch' => $byBranch,
            'issues' => $issueAggregates,
            'open_issue_total' => array_sum($issueAggregates['open_by_severity'] ?? []),
        ];
    }

    /**
     * Candidate board: paginated candidates + one resolved operational status
     * per row. Constant query count per page (batched aggregates, no N+1).
     *
     * @param  array<string, mixed>  $filters
     * @param  list<int>  $branchIds
     */
    public function candidateBoard(array $filters, array $branchIds, int $perPage = 20): LengthAwarePaginator
    {
        $paginator = $this->candidates->paginate($filters, $branchIds, $perPage);

        /** @var list<int> $candidateIds */
        $candidateIds = collect($paginator->items())->pluck('id')->map(fn ($id) => (int) $id)->all();
        $issueAggregates = $this->issues->openAggregatesForCandidates($candidateIds);

        // One query: MR ids that hold a primary structured diagnosis.
        $mrIds = collect($paginator->items())->pluck('medical_record_id')->filter()->map(fn ($id) => (int) $id)->all();
        $primaryMrIds = $mrIds === [] ? [] : MedicalRecordDiagnosis::query()
            ->whereIn('medical_record_id', $mrIds)
            ->where('diagnosis_role', MedicalRecordDiagnosis::ROLE_PRIMARY)
            ->distinct()
            ->pluck('medical_record_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $paginator->getCollection()->transform(function (SatusehatCandidate $candidate) use ($issueAggregates, $primaryMrIds) {
            $agg = $issueAggregates[(int) $candidate->id]
                ?? ['open' => 0, 'awaiting_clinical_review' => 0, 'invalid_demographics' => 0, 'diagnosis_mapping_gap' => 0];

            $candidate->setAttribute('operational_status', $this->resolver->resolve(
                candidate: $candidate,
                reasonCodes: collect((array) $candidate->readiness_reasons)->pluck('code')->filter()->values()->all(),
                dentalReasonCodes: collect((array) $candidate->dental_readiness_reasons)->pluck('code')->filter()->values()->all(),
                hasStructuredPrimaryDiagnosis: in_array((int) $candidate->medical_record_id, $primaryMrIds, true),
                hasDiagnosisMappingGap: $agg['diagnosis_mapping_gap'] > 0,
                openAwaitingClinicalReview: $agg['awaiting_clinical_review'],
                openInternalIssues: $agg['open'],
                hasInvalidDemographics: $agg['invalid_demographics'] > 0,
            ));
            $candidate->setAttribute('open_issue_count', $agg['open']);

            return $candidate;
        });

        return $paginator;
    }

    /**
     * Resolve one candidate's operational status (detail views / rehearsal).
     */
    public function operationalStatusFor(SatusehatCandidate $candidate): string
    {
        $agg = $this->issues->openAggregatesForCandidates([(int) $candidate->id])[(int) $candidate->id]
            ?? ['open' => 0, 'awaiting_clinical_review' => 0, 'invalid_demographics' => 0, 'diagnosis_mapping_gap' => 0];

        $hasPrimary = $candidate->medical_record_id !== null && MedicalRecordDiagnosis::query()
            ->where('medical_record_id', $candidate->medical_record_id)
            ->where('diagnosis_role', MedicalRecordDiagnosis::ROLE_PRIMARY)
            ->exists();

        return $this->resolver->resolve(
            candidate: $candidate,
            reasonCodes: collect((array) $candidate->readiness_reasons)->pluck('code')->filter()->values()->all(),
            dentalReasonCodes: collect((array) $candidate->dental_readiness_reasons)->pluck('code')->filter()->values()->all(),
            hasStructuredPrimaryDiagnosis: $hasPrimary,
            hasDiagnosisMappingGap: $agg['diagnosis_mapping_gap'] > 0,
            openAwaitingClinicalReview: $agg['awaiting_clinical_review'],
            openInternalIssues: $agg['open'],
            hasInvalidDemographics: $agg['invalid_demographics'] > 0,
        );
    }

    /**
     * Practitioner readiness rows (bounded). Never fabricates an IHS id — a
     * doctor without an identifier is ready_for_lookup at best.
     *
     * @return list<array<string, mixed>>
     */
    public function practitionerReadiness(): array
    {
        $env = (string) config('satusehat.environment');
        $doctors = Doctor::query()->orderBy('name')->limit(self::PRACTITIONER_LIMIT)
            ->get(['id', 'name', 'code', 'is_active']);

        $identifiers = SatusehatEntityIdentifier::query()
            ->where('environment', $env)
            ->where('entity_type', SatusehatEntityIdentifier::ENTITY_PRACTITIONER)
            ->where('local_entity_type', 'doctor')
            ->where('status', SatusehatEntityIdentifier::STATUS_ACTIVE)
            ->get(['local_entity_id', 'verified_at'])
            ->keyBy('local_entity_id');

        return $doctors->map(function (Doctor $doctor) use ($identifiers, $env) {
            $identifier = $identifiers->get($doctor->id);
            $status = match (true) {
                ! (bool) $doctor->is_active => 'inactive',
                blank($doctor->name) => 'data_incomplete',
                $identifier === null => 'ready_for_lookup',
                $identifier->verified_at === null => 'identifier_present_unverified',
                default => $env === 'production' ? 'verified_production' : 'verified_sandbox',
            };

            return [
                'doctor_id' => (int) $doctor->id,
                'name' => (string) $doctor->name,
                'code' => (string) $doctor->code,
                'status' => $status,
            ];
        })->values()->all();
    }

    /**
     * Organization (branch) + Location (room) readiness placeholders. Remote
     * ids come only from real Kemkes onboarding — absent ⇒ awaiting.
     *
     * @return array{organizations: list<array<string, mixed>>, locations: list<array<string, mixed>>}
     */
    public function organizationLocationReadiness(): array
    {
        $env = (string) config('satusehat.environment');
        $rmeBranches = $this->branches->listRmeEnabled();
        $branchIds = $rmeBranches->pluck('id')->map(fn ($id) => (int) $id)->all();

        // One query per entity type (batched keyed lookup — no per-row query).
        $identifierMap = SatusehatEntityIdentifier::query()
            ->where('environment', $env)
            ->whereIn('entity_type', [SatusehatEntityIdentifier::ENTITY_ORGANIZATION, SatusehatEntityIdentifier::ENTITY_LOCATION])
            ->where('status', SatusehatEntityIdentifier::STATUS_ACTIVE)
            ->get(['entity_type', 'local_entity_type', 'local_entity_id', 'verified_at'])
            ->keyBy(fn ($row) => $row->entity_type.'|'.$row->local_entity_type.'|'.$row->local_entity_id);

        $identifierStatus = function (string $entityType, string $localType, int $localId) use ($identifierMap): string {
            $identifier = $identifierMap->get($entityType.'|'.$localType.'|'.$localId);

            return match (true) {
                $identifier === null => 'awaiting_external_identifier',
                $identifier->verified_at === null => 'identifier_present_unverified',
                default => 'verified_external',
            };
        };

        $organizations = $rmeBranches->map(fn ($branch) => [
            'branch_id' => (int) $branch->id,
            'code' => (string) $branch->code,
            'name' => (string) $branch->name,
            'status' => $identifierStatus(SatusehatEntityIdentifier::ENTITY_ORGANIZATION, 'branch', (int) $branch->id),
        ])->values()->all();

        $locations = ($branchIds === [] ? collect() : ClinicRoom::query()
            ->whereIn('branch_id', $branchIds)
            ->where('status', ClinicRoom::STATUS_ACTIVE)
            ->orderBy('branch_id')->orderBy('name')
            ->limit(self::LOCATION_LIMIT)
            ->get(['id', 'branch_id', 'code', 'name'])
        )->map(fn (ClinicRoom $room) => [
            'clinic_room_id' => (int) $room->id,
            'branch_id' => (int) $room->branch_id,
            'code' => (string) $room->code,
            'name' => (string) $room->name,
            'status' => $identifierStatus(SatusehatEntityIdentifier::ENTITY_LOCATION, 'clinic_room', (int) $room->id),
        ])->values()->all();

        return ['organizations' => $organizations, 'locations' => $locations];
    }

    /**
     * Treatment mapping governance summary (single aggregate queries).
     *
     * @return array<string, int>
     */
    public function treatmentMappingSummary(): array
    {
        $env = (string) config('satusehat.environment');
        $totalActive = Treatment::query()->where('is_active', true)->count();

        $byStatus = SatusehatCodeMapping::query()
            ->where('environment', $env)
            ->where('local_entity_type', 'treatment')
            ->selectRaw('status, count(distinct local_entity_id) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($v) => (int) $v)
            ->all();

        $mappedActive = SatusehatCodeMapping::query()
            ->where('environment', $env)
            ->where('local_entity_type', 'treatment')
            ->where('status', SatusehatCodeMapping::STATUS_ACTIVE)
            ->whereIn('local_entity_id', Treatment::query()->where('is_active', true)->select('id'))
            ->distinct()
            ->count('local_entity_id');

        return [
            'total_active_treatments' => $totalActive,
            'mapped_active' => $mappedActive,
            'unmapped' => max(0, $totalActive - $mappedActive),
            'draft' => $byStatus[SatusehatCodeMapping::STATUS_DRAFT] ?? 0,
            'deprecated' => $byStatus[SatusehatCodeMapping::STATUS_DEPRECATED] ?? 0,
        ];
    }
}
