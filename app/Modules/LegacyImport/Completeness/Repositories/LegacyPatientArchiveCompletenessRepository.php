<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\Completeness\Repositories;

use App\Modules\LegacyImport\BatchReview\Support\LegacyReviewTriageStatus;
use App\Modules\LegacyImport\Completeness\Interfaces\LegacyPatientArchiveCompletenessRepositoryInterface;
use App\Modules\LegacyImport\Completeness\Support\LegacyCompletenessFilter;
use App\Modules\LegacyImport\Completeness\Support\LegacyCompletenessQuery;
use App\Modules\LegacyImport\Support\LegacyImportType;
use App\Modules\LegacyOdontogram\Support\LegacyOdontogramImportStatus;
use App\Modules\LegacyOdontogram\Support\LegacyOdontogramRecordStatus;
use App\Modules\LegacyRme\Support\LegacyRmeImportStatus;
use App\Modules\LegacyRme\Support\LegacyRmeRecordStatus;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * FEATURE-LEGACY-PATIENT-DOCUMENT-COMPLETENESS-1 — the set-based read side.
 *
 * SET-BASED, NOT PER-PATIENT. The legacy estate is already ~1500 patients and
 * the scale projection reaches tens of thousands, so the document state for
 * every patient is derived by LEFT JOINing four GROUPED aggregates — one per
 * legacy table — rather than by asking the occupancy service once per row. The
 * aggregates are bounded by the number of DOCUMENTS, not patients, and a
 * patient with no documents simply joins to NULL. There is no N+1 anywhere on
 * this page and the query count is constant in both page size and estate size.
 *
 * CORRELATED EXISTS WAS REJECTED. Six EXISTS subqueries per patient would also
 * be correct, but the filter predicate runs over the whole scope before
 * pagination, so the cost would grow with the PATIENT count — the one dimension
 * that is projected to grow fastest.
 *
 * PORTABILITY IS DELIBERATE, NOT INCIDENTAL. `MAX(CASE WHEN ... THEN 1 ELSE 0
 * END)` is used instead of PostgreSQL's `bool_or`, and `MAX(CASE WHEN ... THEN
 * date END)` instead of `FILTER (WHERE ...)`, so the identical SQL runs on the
 * SQLite the default suite uses and the PostgreSQL 16 production runs. A
 * report whose query only works on one engine is a report nobody can test.
 *
 * SOFT-DELETED STAGING ROWS COUNT, AND THAT IS THE WHOLE POINT. The staging
 * aggregates and the representative-status lookups deliberately apply NO
 * `deleted_at` filter, matching `firstSlotOccupyingForPatient()`, which is
 * `withTrashed()`. The documented rule is that a soft delete NEVER releases a
 * patient's document slot — only an audited CANCEL or VOID does. If this report
 * filtered trashed rows out it would show "Belum Ada" for a slot the server
 * still considers occupied and send the operator to an upload that gets
 * refused. Going through `DB::table()` rather than the Eloquent models is how
 * that is guaranteed: there is no global soft-delete scope to forget to disable.
 *
 * THE RECORD TABLES HAVE NO SOFT DELETE AT ALL. A published archive is
 * immutable and is retracted by VOID, never by deletion, so there is nothing to
 * exclude on that side.
 */
class LegacyPatientArchiveCompletenessRepository implements LegacyPatientArchiveCompletenessRepositoryInterface
{
    /**
     * The LIKE escape character for operator search.
     *
     * `!` AND NEVER A BACKSLASH. A backslash inside the single-quoted `ESCAPE`
     * literal makes PDO's placeholder rewriter treat the closing quote as
     * escaped on pdo_pgsql through PHP 8.3 — the literal runs on, swallows the
     * following placeholders, and every search returns
     * `SQLSTATE[HY093] Invalid parameter number`. That defect took down patient
     * search in production once already; the convention exists so it cannot
     * recur.
     */
    private const LIKE_ESCAPE = '!';

    public function paginate(LegacyCompletenessQuery $query): LengthAwarePaginator
    {
        return $this->baseQuery($query)
            ->select([
                'p.id as patient_id',
                'p.medical_record_number',
                'p.name',
                'p.registered_at',
                'p.import_batch_id',
                'b.code as branch_code',
                'b.name as branch_name',
                DB::raw($this->publishedFlag('rr').' as rme_published'),
                DB::raw($this->voidFlag('rr').' as rme_void'),
                DB::raw($this->activeFlag('ri').' as rme_active'),
                'rr.archive_date as rme_archive_date',
                DB::raw($this->publishedFlag('orr').' as odontogram_published'),
                DB::raw($this->voidFlag('orr').' as odontogram_void'),
                DB::raw($this->activeFlag('oi').' as odontogram_active'),
                'orr.archive_date as odontogram_archive_date',
            ])
            // Stable, deterministic ordering. The RM number is the operator's
            // own identifier and the natural way to scan a migration backlog;
            // the id breaks ties so pagination can never repeat or skip a row.
            ->orderBy('p.medical_record_number')
            ->orderBy('p.id')
            ->paginate($query->perPage);
    }

    public function summary(LegacyCompletenessQuery $query): array
    {
        // One aggregate pass over the same join set, the same scope and the
        // same SEARCH as the listing, with only the status filter deliberately
        // NOT applied.
        //
        // Precisely: the cards describe everything the actor can currently
        // reach, not the current PAGE and not the whole estate. The status
        // filter is excluded because the cards are what the operator pivots
        // between, so a card that counted only its own filter would always
        // read as the row count beside it. The search is NOT excluded — it is
        // a narrowing the operator chose, and the cards follow it, so an
        // active `?q=` legitimately shows the counters for the matches.
        $scoped = $this->baseQuery($query, applyStatusFilter: false);

        $rmeComplete = $this->publishedFlag('rr');
        $odontogramComplete = $this->publishedFlag('orr');
        $rmeActive = $this->activeFlag('ri');
        $odontogramActive = $this->activeFlag('oi');

        // "Needs an upload" is MISSING or VOID — i.e. not published and not in
        // flight. Expressed once here and once in the filter, from the same two
        // flags, so the cards and the filters cannot disagree.
        $rmeNeedsUpload = "(({$rmeComplete}) = 0 and ({$rmeActive}) = 0)";
        $odontogramNeedsUpload = "(({$odontogramComplete}) = 0 and ({$odontogramActive}) = 0)";
        $complete = "(({$rmeComplete}) = 1 and ({$odontogramComplete}) = 1)";
        $anyActive = "(({$rmeActive}) = 1 or ({$odontogramActive}) = 1)";

        $row = $scoped->selectRaw(implode(', ', [
            'count(*) as total',
            $this->countWhen($complete).' as complete',
            $this->countWhen("not {$complete}").' as incomplete',
            $this->countWhen($rmeNeedsUpload).' as missing_rme',
            $this->countWhen($odontogramNeedsUpload).' as missing_odontogram',
            $this->countWhen("({$rmeNeedsUpload} and {$odontogramNeedsUpload})").' as missing_both',
            $this->countWhen("(not {$complete} and {$anyActive})").' as in_progress',
        ]))->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'complete' => (int) ($row->complete ?? 0),
            'incomplete' => (int) ($row->incomplete ?? 0),
            'missing_rme' => (int) ($row->missing_rme ?? 0),
            'missing_odontogram' => (int) ($row->missing_odontogram ?? 0),
            'missing_both' => (int) ($row->missing_both ?? 0),
            'in_progress' => (int) ($row->in_progress ?? 0),
        ];
    }

    public function slotOccupyingRmeStatuses(LegacyCompletenessQuery $query, array $patientIds): array
    {
        return $this->slotOccupyingStatuses(
            'stg_rme_legacy_imports',
            LegacyRmeImportStatus::SLOT_OCCUPYING,
            $query,
            $patientIds,
        );
    }

    public function slotOccupyingOdontogramStatuses(LegacyCompletenessQuery $query, array $patientIds): array
    {
        return $this->slotOccupyingStatuses(
            'stg_odontogram_legacy_imports',
            LegacyOdontogramImportStatus::SLOT_OCCUPYING,
            $query,
            $patientIds,
        );
    }

    public function reviewBlockedPatientIds(LegacyCompletenessQuery $query, array $patientIds): array
    {
        $empty = [LegacyImportType::LEGACY_RME => [], LegacyImportType::LEGACY_ODONTOGRAM => []];

        if ($patientIds === []) {
            return $empty;
        }

        $rows = DB::table('stg_legacy_review_triage')
            ->whereIn('patient_id', $patientIds)
            // THE SCOPE IS RE-APPLIED, NOT TRUSTED FROM THE CALLER. Today the
            // only caller derives these ids from the already-scoped paginator,
            // so this narrows nothing — which is exactly why it is cheap to add
            // and why leaving it out would be invisible. What it buys is that a
            // future row action, drilldown or AJAX refresh taking patient ids
            // from a request cannot read another branch's reviewer holds, and
            // that the interface's promise is structurally true rather than a
            // property of who happens to call it.
            ->whereIn('patient_id', $this->scopedPatientIds($query))
            // The canonical blocking set, read from the review module's own
            // vocabulary rather than copied: BLOCKED and NEEDS_ATTENTION hold an
            // item, CLEARED is a retained history row that holds nothing.
            ->whereIn('triage_status', LegacyReviewTriageStatus::blocking())
            ->whereIn('import_type', [LegacyImportType::LEGACY_RME, LegacyImportType::LEGACY_ODONTOGRAM])
            ->get(['patient_id', 'import_type']);

        $blocked = $empty;

        foreach ($rows as $row) {
            $type = (string) $row->import_type;

            if (array_key_exists($type, $blocked)) {
                $blocked[$type][] = (int) $row->patient_id;
            }
        }

        return [
            LegacyImportType::LEGACY_RME => array_values(array_unique($blocked[LegacyImportType::LEGACY_RME])),
            LegacyImportType::LEGACY_ODONTOGRAM => array_values(array_unique($blocked[LegacyImportType::LEGACY_ODONTOGRAM])),
        ];
    }

    public function branchOptions(LegacyCompletenessQuery $query): array
    {
        if ($query->deniesEverything()) {
            return [];
        }

        // Built from the SAME scoped-patient definition the listing and the
        // counters use, rather than from a hand-restated copy of it. A copy is
        // how the branch filter comes to disagree with the rows it filters:
        // the next edit to the provenance or soft-delete predicate would reach
        // one surface and silently miss the other.
        //
        // The join is INNER on purpose. A legacy patient with no branch has no
        // branch to offer, so they contribute no option — structurally, not by
        // a second predicate that has to be kept in step.
        $rows = $this->scopedPatients($query)
            ->join('mst_branches as b', 'b.id', '=', 'p.branch_id')
            ->distinct()
            ->orderBy('b.code')
            ->get(['b.id', 'b.code', 'b.name']);

        $options = [];

        foreach ($rows as $row) {
            $options[(int) $row->id] = $row->code.' — '.$row->name;
        }

        return $options;
    }

    /**
     * THE ONE DEFINITION OF "A LEGACY PATIENT IN MY SCOPE".
     *
     * Soft-delete exclusion, the provenance predicate and the branch scope,
     * and nothing else — no aggregates, no search, no status filter. Every
     * query in this class that answers a question about which patients the
     * actor may see starts here: the listing, the counters AND the branch
     * filter. That is what makes it impossible for those three surfaces to
     * apply subtly different definitions, which is a divergence a reader
     * cannot see and a test that compares only row sets will not catch.
     *
     * Restating these three predicates by hand anywhere else is the defect.
     */
    private function scopedPatients(LegacyCompletenessQuery $query): Builder
    {
        $builder = DB::table('mst_patients as p')
            // A soft-deleted patient is not an active migration target. Excluded
            // here rather than in a filter, so no caller can ask for one.
            ->whereNull('p.deleted_at')
            // THE PROVENANCE PREDICATE. The single canonical marker that this
            // patient was created by the legacy patient import. It is NOT
            // derived from created_at, the RM format, a missing NIK, the branch,
            // the absence of native RME, or anything else that merely correlates
            // with age — every one of those would list natively registered
            // patients, which this report must never do.
            //
            // Note that `Patient::isLegacyWithoutBranch()` exists and means
            // something entirely different (branch_id IS NULL, from Sprint
            // 23.10). Using it here would list native branchless patients and
            // miss every imported one that has a branch.
            ->whereNotNull('p.import_batch_id');

        $this->applyBranchScope($builder, $query);

        return $builder;
    }

    /**
     * The same definition, shaped as a sub-select of patient ids.
     *
     * Used by the per-page lookups, which receive ids from their caller and
     * must not take the caller's word for which patients those are.
     */
    private function scopedPatientIds(LegacyCompletenessQuery $query): Builder
    {
        return $this->scopedPatients($query)->select('p.id');
    }

    /**
     * The listing query: the scoped-patient definition above, plus the branch
     * label join, the four document aggregates, the search and the status
     * filter.
     *
     * The aggregates live here and not in {@see scopedPatients()} because the
     * branch filter needs the scope without paying for four joins it never
     * reads.
     */
    private function baseQuery(LegacyCompletenessQuery $query, bool $applyStatusFilter = true): Builder
    {
        $builder = $this->scopedPatients($query)
            ->leftJoin('mst_branches as b', 'b.id', '=', 'p.branch_id')
            ->leftJoinSub(
                $this->recordAggregate('trx_rme_legacy_records', 'rme_date', LegacyRmeRecordStatus::PUBLISHED, LegacyRmeRecordStatus::VOID),
                'rr',
                'rr.patient_id',
                '=',
                'p.id',
            )
            ->leftJoinSub(
                $this->importAggregate('stg_rme_legacy_imports', LegacyRmeImportStatus::SLOT_OCCUPYING),
                'ri',
                'ri.patient_id',
                '=',
                'p.id',
            )
            ->leftJoinSub(
                $this->recordAggregate('trx_odontogram_legacy_records', 'odontogram_date', LegacyOdontogramRecordStatus::PUBLISHED, LegacyOdontogramRecordStatus::VOID),
                'orr',
                'orr.patient_id',
                '=',
                'p.id',
            )
            ->leftJoinSub(
                $this->importAggregate('stg_odontogram_legacy_imports', LegacyOdontogramImportStatus::SLOT_OCCUPYING),
                'oi',
                'oi.patient_id',
                '=',
                'p.id',
            );

        if ($query->search !== null && $query->search !== '') {
            $this->applySearch($builder, $query->search);
        }

        if ($applyStatusFilter) {
            $this->applyStatusFilter($builder, $query->filter);
        }

        return $builder;
    }

    private function applyBranchScope(Builder $builder, LegacyCompletenessQuery $query): void
    {
        if ($query->deniesEverything()) {
            // Fail closed with an impossible predicate rather than by omitting
            // the clause. An unresolvable scope must yield nothing, never
            // everything.
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where(function (Builder $scope) use ($query): void {
            if ($query->branchIds !== []) {
                $scope->whereIn('p.branch_id', $query->branchIds);
            }

            if ($query->includeUnscopedBranch) {
                // A legacy patient with no branch carries no provenance, so it
                // is readable only by the tier that governs every branch —
                // exactly the rule LegacyRmeWorkspaceScope applies to
                // provenance-less archive rows.
                $scope->orWhereNull('p.branch_id');
            }
        });

        // A branch-pinned actor needs no extra guard for branchless rows:
        // `branch_id IN (...)` is never true for NULL, so they are invisible to
        // a pinned scope for free.
    }

    private function applySearch(Builder $builder, string $search): void
    {
        $like = '%'.$this->escapeLike($search).'%';

        $builder->where(function (Builder $match) use ($like): void {
            $match
                ->whereRaw("lower(p.medical_record_number) like lower(?) escape '".self::LIKE_ESCAPE."'", [$like])
                ->orWhereRaw("lower(p.name) like lower(?) escape '".self::LIKE_ESCAPE."'", [$like]);
        });
    }

    /**
     * Narrow by completeness, using the SAME flag expressions the summary
     * counters use so a filter can never select a different population than the
     * card that counts it.
     */
    private function applyStatusFilter(Builder $builder, string $filter): void
    {
        $rmePublished = $this->publishedFlag('rr');
        $odontogramPublished = $this->publishedFlag('orr');
        $rmeActive = $this->activeFlag('ri');
        $odontogramActive = $this->activeFlag('oi');

        $rmeNeedsUpload = "(({$rmePublished}) = 0 and ({$rmeActive}) = 0)";
        $odontogramNeedsUpload = "(({$odontogramPublished}) = 0 and ({$odontogramActive}) = 0)";
        $complete = "(({$rmePublished}) = 1 and ({$odontogramPublished}) = 1)";
        $anyActive = "(({$rmeActive}) = 1 or ({$odontogramActive}) = 1)";

        match ($filter) {
            LegacyCompletenessFilter::COMPLETE => $builder->whereRaw($complete),
            LegacyCompletenessFilter::MISSING_RME => $builder->whereRaw($rmeNeedsUpload),
            LegacyCompletenessFilter::MISSING_ODONTOGRAM => $builder->whereRaw($odontogramNeedsUpload),
            LegacyCompletenessFilter::MISSING_BOTH => $builder->whereRaw("({$rmeNeedsUpload} and {$odontogramNeedsUpload})"),
            LegacyCompletenessFilter::IN_PROGRESS => $builder->whereRaw("(not {$complete} and {$anyActive})"),
            // The default, and the only remaining case: everything that is not
            // complete, in-flight work included.
            default => $builder->whereRaw("not {$complete}"),
        };
    }

    /**
     * Published / void flags plus the latest published archive date for one
     * record table, grouped by patient.
     *
     * MAX over the date is deterministic and meaningful: the most recent
     * published archive. Folding it into this aggregate keeps it free — no
     * extra query, no per-row lookup.
     */
    private function recordAggregate(string $table, string $dateColumn, string $publishedStatus, string $voidStatus): Builder
    {
        return DB::table($table)
            ->select('patient_id')
            ->selectRaw('max(case when status = ? then 1 else 0 end) as published', [$publishedStatus])
            ->selectRaw('max(case when status = ? then 1 else 0 end) as voided', [$voidStatus])
            ->selectRaw("max(case when status = ? then {$dateColumn} else null end) as archive_date", [$publishedStatus])
            ->groupBy('patient_id');
    }

    /**
     * The "a lifecycle is alive" flag for one staging table, grouped by patient.
     *
     * NO `deleted_at` FILTER, ON PURPOSE — see the class docblock. Each table is
     * asked about its OWN module's SLOT_OCCUPYING list; the two vocabularies are
     * declared independently and must not be cross-applied even while they
     * happen to agree.
     *
     * @param  list<string>  $slotOccupying
     */
    private function importAggregate(string $table, array $slotOccupying): Builder
    {
        $placeholders = implode(', ', array_fill(0, count($slotOccupying), '?'));

        return DB::table($table)
            ->select('patient_id')
            ->selectRaw("max(case when status in ({$placeholders}) then 1 else 0 end) as active", $slotOccupying)
            ->groupBy('patient_id');
    }

    /**
     * @param  list<string>  $slotOccupying
     * @param  list<int>  $patientIds
     * @return array<int, string>
     */
    private function slotOccupyingStatuses(
        string $table,
        array $slotOccupying,
        LegacyCompletenessQuery $query,
        array $patientIds,
    ): array {
        if ($patientIds === []) {
            return [];
        }

        $rows = DB::table($table)
            ->whereIn('patient_id', $patientIds)
            // See reviewBlockedPatientIds(): the scope is re-applied here so a
            // caller cannot hand in an id from outside it and read another
            // branch's legacy pipeline state.
            ->whereIn('patient_id', $this->scopedPatientIds($query))
            ->whereIn('status', $slotOccupying)
            // Lowest id first, soft-deleted included: the same row
            // firstSlotOccupyingForPatient() would return. The first value wins
            // below, so the label describes the occupant the server would find.
            ->orderBy('id')
            ->get(['patient_id', 'status']);

        $statuses = [];

        foreach ($rows as $row) {
            $patientId = (int) $row->patient_id;

            if (! array_key_exists($patientId, $statuses)) {
                $statuses[$patientId] = (string) $row->status;
            }
        }

        return $statuses;
    }

    /**
     * `coalesce` is what makes a patient with no documents at all read as 0
     * rather than NULL, so every downstream comparison is a plain integer test.
     */
    private function publishedFlag(string $alias): string
    {
        return "coalesce({$alias}.published, 0)";
    }

    private function voidFlag(string $alias): string
    {
        return "coalesce({$alias}.voided, 0)";
    }

    private function activeFlag(string $alias): string
    {
        return "coalesce({$alias}.active, 0)";
    }

    /**
     * Portable conditional count: `sum(case when ... then 1 else 0 end)` works
     * identically on PostgreSQL and SQLite, where `count(*) filter (where ...)`
     * does not.
     */
    private function countWhen(string $condition): string
    {
        return "sum(case when {$condition} then 1 else 0 end)";
    }

    /**
     * Escape the LIKE metacharacters so an operator typing `%` or `_` searches
     * for that character instead of matching everything.
     *
     * The escape character is escaped FIRST, or escaping would corrupt itself.
     */
    private function escapeLike(string $value): string
    {
        return str_replace(
            [self::LIKE_ESCAPE, '%', '_'],
            [self::LIKE_ESCAPE.self::LIKE_ESCAPE, self::LIKE_ESCAPE.'%', self::LIKE_ESCAPE.'_'],
            $value,
        );
    }
}
