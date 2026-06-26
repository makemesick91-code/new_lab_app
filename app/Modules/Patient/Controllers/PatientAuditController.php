<?php

namespace App\Modules\Patient\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Branch\Models\Branch;
use App\Modules\Patient\Interfaces\PatientRepositoryInterface;
use App\Modules\Patient\Models\Patient;
use App\Modules\Patient\Services\PatientDataCompletenessService;
use App\Modules\Patient\Services\PatientRmGapReviewService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sprint 61.0 — Patient Data Completeness Audit & RM Gap Review.
 *
 * Read-only reporting surface (RME → Audit Data Pasien). Never mutates patient
 * data. Access is gated at the route layer by `view_rme_patient_reports` OR
 * `manage patients`, so owners / RME report viewers and FO/admin patient
 * managers can reach it, but doctors and cashiers cannot.
 *
 * Branch isolation: the branch filter only offers active RME-enabled branches
 * (MAIN with is_rme_enabled = false never appears) and a requested branch_id is
 * validated against that same set. The current schema has no per-user branch
 * assignment, so all authorised users may filter across every RME branch.
 *
 * Privacy: full KTP is never rendered or exported — only a masked "****####"
 * availability indicator.
 */
class PatientAuditController extends Controller
{
    private const PER_PAGE = 25;

    public function __construct(
        private readonly PatientRepositoryInterface $patients,
        private readonly PatientDataCompletenessService $completeness,
        private readonly PatientRmGapReviewService $rmGap,
    ) {}

    public function index(Request $request): View
    {
        $selectedBranchId = $this->resolveBranchId($request);
        $rows = $this->buildRows($request, $selectedBranchId);
        $baseFiltered = $this->applyScopeFilters($rows, $request);

        $tableRows = $this->applyDisplayFilters($baseFiltered, $request);
        $tableRows = $this->sortRows($tableRows, $request->string('sort')->toString());

        return view('rme.patients.audit.index', [
            'branches' => $this->rmeBranches(),
            'selectedBranchId' => $selectedBranchId,
            'filters' => $this->filters($request, $selectedBranchId),
            'statusOptions' => $this->completeness->statusOptions(),
            'missingFieldOptions' => $this->completeness->missingFieldOptions(),
            'kpi' => $this->buildKpi($baseFiltered),
            'rows' => $this->paginate($tableRows, $request),
            'rmGap' => $this->rmGap->review($selectedBranchId),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $selectedBranchId = $this->resolveBranchId($request);
        $rows = $this->buildRows($request, $selectedBranchId);
        $baseFiltered = $this->applyScopeFilters($rows, $request);
        $exportRows = $this->sortRows($this->applyDisplayFilters($baseFiltered, $request), $request->string('sort')->toString());

        $filename = 'audit-data-pasien-'.now()->format('Ymd-Hi').'.csv';

        // Privacy: branch / rm / name / score / missing fields / duplicate summary
        // only — full KTP is intentionally excluded from the export.
        return response()->streamDownload(function () use ($exportRows) {
            echo "\xEF\xBB\xBF";
            $handle = fopen('php://output', 'w');
            fputcsv($handle, [
                'branch',
                'rm_number',
                'patient_name',
                'completeness_score',
                'missing_fields',
                'duplicate_risk_summary',
                'created_at',
                'updated_at',
            ]);

            foreach ($exportRows as $row) {
                /** @var Patient $patient */
                $patient = $row['patient'];
                fputcsv($handle, [
                    $patient->branch?->name ?? '',
                    $patient->medical_record_number ?? '',
                    $patient->name ?? '',
                    $row['evaluation']['score'].'%',
                    implode('; ', $row['evaluation']['missing_fields']),
                    implode('; ', $row['duplicate_reasons']),
                    $patient->created_at?->format('Y-m-d H:i') ?? '',
                    $patient->updated_at?->format('Y-m-d H:i') ?? '',
                ]);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Load the branch+active scope, then attach evaluation, masked KTP and
     * duplicate-risk reasons. Duplicate detection runs over the full scope so a
     * patient's duplicate partner is still seen even when later filtered out.
     *
     * @return Collection<int, array{patient: Patient, evaluation: array<string, mixed>, masked_ktp: ?string, duplicate_reasons: list<string>}>
     */
    private function buildRows(Request $request, ?int $selectedBranchId): Collection
    {
        /** @var Collection<int, Patient> $patients */
        $patients = $this->patients->forAudit([
            'branch_id' => $selectedBranchId,
            'is_active' => $this->resolveActiveStatus($request),
        ]);

        $duplicates = $this->completeness->detectDuplicateRisks($patients);

        return $patients->map(fn (Patient $patient) => [
            'patient' => $patient,
            'evaluation' => $this->completeness->evaluate($patient),
            'masked_ktp' => $this->completeness->maskKtp($patient->ktp_number),
            'duplicate_reasons' => $duplicates[$patient->id] ?? [],
        ])->values();
    }

    /**
     * Search + registration-date filters (the KPI/duplicate scope). Completeness
     * and missing-field-type filters are NOT applied here so the KPI cards stay
     * meaningful regardless of the completeness lens.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function applyScopeFilters(Collection $rows, Request $request): Collection
    {
        $search = mb_strtolower(trim($request->string('q')->toString()));
        $dateFrom = $request->filled('date_from') ? $request->date('date_from') : null;
        $dateTo = $request->filled('date_to') ? $request->date('date_to') : null;

        return $rows->filter(function (array $row) use ($search, $dateFrom, $dateTo) {
            /** @var Patient $patient */
            $patient = $row['patient'];

            if ($search !== '') {
                $haystack = mb_strtolower(implode(' ', array_filter([
                    $patient->name,
                    $patient->medical_record_number,
                    $patient->phone,
                    $patient->whatsapp_number,
                ])));

                if (! str_contains($haystack, $search)) {
                    return false;
                }
            }

            $registeredAt = $patient->registered_at ?? $patient->created_at;

            if ($dateFrom !== null && ($registeredAt === null || $registeredAt->lt($dateFrom->startOfDay()))) {
                return false;
            }

            if ($dateTo !== null && ($registeredAt === null || $registeredAt->gt($dateTo->endOfDay()))) {
                return false;
            }

            return true;
        })->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function applyDisplayFilters(Collection $rows, Request $request): Collection
    {
        $status = $request->string('status')->toString() ?: null;
        $missingField = $request->string('missing_field')->toString() ?: null;
        $duplicatesOnly = $request->boolean('duplicates_only');

        return $rows->filter(function (array $row) use ($status, $missingField, $duplicatesOnly) {
            if (! $this->completeness->matchesStatus($row['evaluation'], $status)) {
                return false;
            }

            if ($missingField !== null && ! array_key_exists($missingField, $row['evaluation']['missing_fields'])) {
                return false;
            }

            if ($duplicatesOnly && $row['duplicate_reasons'] === []) {
                return false;
            }

            return true;
        })->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function sortRows(Collection $rows, string $sort): Collection
    {
        return match ($sort) {
            'oldest' => $rows->sortBy(fn (array $r) => $r['patient']->id)->values(),
            'most_incomplete' => $rows->sortBy(fn (array $r) => $r['evaluation']['score'])->values(),
            default => $rows->sortByDesc(fn (array $r) => $r['patient']->id)->values(), // newest
        };
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function buildKpi(Collection $rows): array
    {
        $total = $rows->count();
        $complete = $rows->filter(fn (array $r) => $r['evaluation']['complete'])->count();

        $perBranch = $rows
            ->groupBy(fn (array $r) => $r['patient']->branch?->name ?? 'Tanpa Cabang')
            ->map->count()
            ->sortKeys();

        return [
            'total' => $total,
            'complete' => $complete,
            'incomplete' => $total - $complete,
            'completeness_percentage' => $total > 0 ? (int) round($complete / $total * 100) : 0,
            'missing_rm' => $this->countMissing($rows, 'medical_record_number'),
            'missing_contact' => $this->countMissing($rows, 'contact'),
            'missing_address' => $this->countMissing($rows, 'address'),
            'missing_birth_date' => $this->countMissing($rows, 'date_of_birth'),
            'duplicate_risk' => $rows->filter(fn (array $r) => $r['duplicate_reasons'] !== [])->count(),
            'per_branch' => $perBranch,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     */
    private function countMissing(Collection $rows, string $field): int
    {
        return $rows->filter(fn (array $r) => array_key_exists($field, $r['evaluation']['missing_fields']))->count();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return LengthAwarePaginator<array<string, mixed>>
     */
    private function paginate(Collection $rows, Request $request): LengthAwarePaginator
    {
        $page = max(1, (int) $request->integer('page', 1));
        $items = $rows->forPage($page, self::PER_PAGE)->values();

        return new LengthAwarePaginator(
            $items,
            $rows->count(),
            self::PER_PAGE,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );
    }

    private function resolveBranchId(Request $request): ?int
    {
        if (! $request->filled('branch_id')) {
            return null;
        }

        return Branch::query()
            ->where('id', (int) $request->input('branch_id'))
            ->where('is_active', true)
            ->rmeEnabled()
            ->value('id');
    }

    private function resolveActiveStatus(Request $request): ?bool
    {
        return match ($request->string('active')->toString()) {
            'active' => true,
            'inactive' => false,
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(Request $request, ?int $selectedBranchId): array
    {
        return [
            'branch_id' => $selectedBranchId,
            'status' => $request->string('status')->toString() ?: null,
            'missing_field' => $request->string('missing_field')->toString() ?: null,
            'active' => $request->string('active')->toString() ?: null,
            'duplicates_only' => $request->boolean('duplicates_only'),
            'q' => $request->string('q')->toString() ?: null,
            'date_from' => $request->string('date_from')->toString() ?: null,
            'date_to' => $request->string('date_to')->toString() ?: null,
            'sort' => $request->string('sort')->toString() ?: 'newest',
        ];
    }

    private function rmeBranches(): Collection
    {
        return Branch::query()
            ->where('is_active', true)
            ->rmeEnabled()
            ->orderBy('name')
            ->get(['id', 'name', 'code']);
    }
}
