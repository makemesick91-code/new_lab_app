<?php

namespace App\Modules\Satusehat\Services;

use App\Modules\Branch\Services\BranchService;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\MedicalRecord\Models\ClinicalDiagnosis;
use App\Modules\MedicalRecord\Models\DiagnosisRequirementOverride;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Models\MedicalRecordDiagnosis;
use App\Modules\MedicalRecord\Services\DiagnosisRolloutService;
use App\Modules\Satusehat\Models\SatusehatCandidate;
use App\Modules\Satusehat\Models\SatusehatDataQualityIssue;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/**
 * SATUSEHAT-4B — structured diagnosis adoption analytics.
 *
 * Read-only, bounded, PII-free aggregation (no NIK, no clinical notes, no
 * patient names). Branch scope is resolved server-side from the RME-enabled
 * branch set; a requested branch outside the scope is dropped, never trusted.
 * Metrics are operational quality indicators — not a punitive doctor ranking.
 * Zero denominators yield null rates ("N/A"), never a fabricated 0%.
 */
class SatusehatDiagnosisAdoptionService
{
    /** Diagnosis-related data-quality rule codes surfaced on the dashboard. */
    public const DIAGNOSIS_RULE_CODES = [
        'structured_diagnosis',
        'duplicate_primary_diagnosis',
        'deprecated_diagnosis_selected',
        'diagnosis_code_invalid',
        'diagnosis_mapping',
    ];

    public function __construct(
        private readonly BranchService $branches,
        private readonly DiagnosisRolloutService $rollout,
    ) {}

    /**
     * @param  array{from?: ?string, to?: ?string, branch_id?: ?int, doctor_id?: ?int}  $filters
     * @return array<string, mixed>
     */
    public function metrics(array $filters = []): array
    {
        $scope = $this->branches->rmeEnabledIds();

        // A requested branch outside the RME scope is dropped (IDOR-safe).
        $branchId = isset($filters['branch_id']) ? (int) $filters['branch_id'] : null;
        if ($branchId !== null && ! in_array($branchId, $scope, true)) {
            $branchId = null;
        }
        $scoped = $branchId !== null ? [$branchId] : $scope;

        [$from, $to] = $this->period($filters);
        $doctorId = isset($filters['doctor_id']) && (int) $filters['doctor_id'] > 0 ? (int) $filters['doctor_id'] : null;

        if ($scoped === []) {
            return $this->emptyMetrics($from, $to);
        }

        $base = $this->baseQuery($scoped, $from, $to, $doctorId);

        $totals = (clone $base)
            ->selectRaw($this->adoptionSelect())
            ->first();

        $eligible = (int) ($totals->eligible ?? 0);
        $withDiagnosis = (int) ($totals->with_diagnosis ?? 0);
        $withPrimary = (int) ($totals->with_primary ?? 0);

        $perBranch = (clone $base)
            ->selectRaw('trx_medical_records.branch_id, '.$this->adoptionSelect())
            ->groupBy('trx_medical_records.branch_id')
            ->orderBy('trx_medical_records.branch_id')
            ->limit((int) config('clinical_diagnosis_rollout.adoption.max_branch_rows', 50))
            ->get();

        $perDoctor = (clone $base)
            ->selectRaw('v.doctor_id, '.$this->adoptionSelect())
            ->whereNotNull('v.doctor_id')
            ->groupBy('v.doctor_id')
            ->orderByRaw('count(*) desc')
            ->limit((int) config('clinical_diagnosis_rollout.adoption.max_doctor_rows', 50))
            ->get();

        $branchNames = $this->branches->listRmeEnabled()->pluck('name', 'id');
        $doctorNames = Doctor::query()
            ->whereIn('id', $perDoctor->pluck('doctor_id')->filter()->all())
            ->pluck('name', 'id');

        $secondaryCount = MedicalRecordDiagnosis::query()
            ->where('diagnosis_role', MedicalRecordDiagnosis::ROLE_SECONDARY)
            ->whereIn('trx_medical_record_diagnoses.branch_id', $scoped)
            ->whereBetween('diagnosed_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->count();

        $deprecatedUsage = MedicalRecordDiagnosis::query()
            ->whereIn('trx_medical_record_diagnoses.branch_id', $scoped)
            ->whereHas('clinicalDiagnosis', fn ($q) => $q->where('status', '!=', ClinicalDiagnosis::STATUS_ACTIVE))
            ->count();

        $overrides = DiagnosisRequirementOverride::query()
            ->whereIn('branch_id', $scoped)
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->count();

        $sourceChanged = SatusehatCandidate::query()
            ->whereIn('branch_id', $scoped)
            ->where('readiness_status', SatusehatCandidate::READINESS_SOURCE_CHANGED)
            ->count();

        $openIssues = SatusehatDataQualityIssue::query()
            ->whereIn('branch_id', $scoped)
            ->whereIn('rule_code', self::DIAGNOSIS_RULE_CODES)
            ->whereNotIn('status', [SatusehatDataQualityIssue::STATUS_RESOLVED, SatusehatDataQualityIssue::STATUS_WAIVED])
            ->selectRaw('rule_code, count(*) as total')
            ->groupBy('rule_code')
            ->pluck('total', 'rule_code');

        $rolloutModes = $this->rollout->board()
            ->filter(fn (array $row) => in_array((int) $row['branch']->id, $scoped, true))
            ->map(fn (array $row) => [
                'branch_id' => (int) $row['branch']->id,
                'branch_name' => (string) $row['branch']->name,
                'mode' => $row['mode'],
            ])->values()->all();

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'scope_branch_ids' => $scoped,
            'eligible_visits' => $eligible,
            'with_structured_diagnosis' => $withDiagnosis,
            'with_primary_diagnosis' => $withPrimary,
            'missing_structured_diagnosis' => max(0, $eligible - $withDiagnosis),
            'adoption_rate' => $this->rate($withDiagnosis, $eligible),
            'primary_completeness_rate' => $this->rate($withPrimary, $eligible),
            'secondary_diagnosis_count' => $secondaryCount,
            'deprecated_diagnosis_usage' => $deprecatedUsage,
            'override_count' => $overrides,
            'source_changed_candidates' => $sourceChanged,
            'open_diagnosis_issues' => $openIssues->all(),
            'rollout_modes' => $rolloutModes,
            'per_branch' => $perBranch->map(fn ($row) => [
                'branch_id' => (int) $row->branch_id,
                'branch_name' => (string) ($branchNames[(int) $row->branch_id] ?? ('Cabang #'.$row->branch_id)),
                'eligible' => (int) $row->eligible,
                'with_diagnosis' => (int) $row->with_diagnosis,
                'with_primary' => (int) $row->with_primary,
                'adoption_rate' => $this->rate((int) $row->with_diagnosis, (int) $row->eligible),
            ])->values()->all(),
            'per_doctor' => $perDoctor->map(fn ($row) => [
                'doctor_id' => (int) $row->doctor_id,
                'doctor_name' => (string) ($doctorNames[(int) $row->doctor_id] ?? ('Dokter #'.$row->doctor_id)),
                'eligible' => (int) $row->eligible,
                'with_diagnosis' => (int) $row->with_diagnosis,
                'with_primary' => (int) $row->with_primary,
                'adoption_rate' => $this->rate((int) $row->with_diagnosis, (int) $row->eligible),
            ])->values()->all(),
        ];
    }

    /**
     * Eligible = medical records on non-cancelled RME-branch visits within the
     * period. Legacy records count as eligible-but-missing (honest baseline).
     *
     * @param  list<int>  $scoped
     */
    private function baseQuery(array $scoped, Carbon $from, Carbon $to, ?int $doctorId): Builder
    {
        return MedicalRecord::query()
            ->join('trx_clinic_visits as v', 'v.id', '=', 'trx_medical_records.clinic_visit_id')
            ->whereNull('trx_medical_records.deleted_at')
            ->whereIn('trx_medical_records.branch_id', $scoped)
            ->where('v.status', '!=', ClinicVisit::STATUS_CANCELLED)
            // Datetime bounds: portable across PG (date column casts the string)
            // and SQLite (the `date` cast stores "Y-m-d H:i:s" strings).
            ->whereBetween('v.visit_date', [
                $from->copy()->startOfDay()->toDateTimeString(),
                $to->copy()->endOfDay()->toDateTimeString(),
            ])
            ->when($doctorId !== null, fn ($q) => $q->where('v.doctor_id', $doctorId));
    }

    /** Portable (PG + SQLite) correlated-subquery adoption aggregate. */
    private function adoptionSelect(): string
    {
        $exists = 'select 1 from trx_medical_record_diagnoses d '
            .'where d.medical_record_id = trx_medical_records.id and d.deleted_at is null';

        return 'count(*) as eligible, '
            ."sum(case when exists ({$exists}) then 1 else 0 end) as with_diagnosis, "
            ."sum(case when exists ({$exists} and d.diagnosis_role = 'primary') then 1 else 0 end) as with_primary";
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function period(array $filters): array
    {
        $maxDays = (int) config('clinical_diagnosis_rollout.adoption.max_period_days', 366);

        $to = $this->parseDate($filters['to'] ?? null) ?? Carbon::today();
        $from = $this->parseDate($filters['from'] ?? null) ?? $to->copy()->subDays(29);

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to->copy(), $from->copy()];
        }
        if ($from->diffInDays($to) > $maxDays) {
            $from = $to->copy()->subDays($maxDays);
        }

        return [$from, $to];
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse(trim($value))->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    private function rate(int $numerator, int $denominator): ?float
    {
        return $denominator > 0 ? round($numerator * 100 / $denominator, 1) : null;
    }

    /** @return array<string, mixed> */
    private function emptyMetrics(Carbon $from, Carbon $to): array
    {
        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'scope_branch_ids' => [],
            'eligible_visits' => 0,
            'with_structured_diagnosis' => 0,
            'with_primary_diagnosis' => 0,
            'missing_structured_diagnosis' => 0,
            'adoption_rate' => null,
            'primary_completeness_rate' => null,
            'secondary_diagnosis_count' => 0,
            'deprecated_diagnosis_usage' => 0,
            'override_count' => 0,
            'source_changed_candidates' => 0,
            'open_diagnosis_issues' => [],
            'rollout_modes' => [],
            'per_branch' => [],
            'per_doctor' => [],
        ];
    }
}
