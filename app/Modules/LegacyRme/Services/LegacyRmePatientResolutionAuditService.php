<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Services;

use App\Modules\LegacyRme\Models\LegacyRmeImport;
use App\Modules\LegacyRme\Support\LegacyRmeImportStatus;
use App\Modules\LegacyRme\Support\LegacyRmePatientResolution;
use App\Modules\Patient\Models\Patient;
use App\Modules\Patient\Services\CrossBranchPatientLookupService;
use App\Modules\Patient\Services\PatientMedicalRecordNumberService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * LEGACY-RME-MASTERDATA-1 — answering "which patient does this Nomor RM name?"
 * and "is the patient master sound enough to answer it?", read-only.
 *
 * WHY THIS EXISTS. A legacy document carries a Nomor RM printed on paper. When
 * it resolves to nothing, an operator needs to know WHICH of these is true, and
 * the difference decides whether a document may ever be migrated:
 *
 *   - the patient genuinely is not registered           → a master-data action
 *   - the number is not a canonical Nomor RM            → a source problem
 *   - the patient IS registered but the master is broken → a defect to fix
 *
 * Before this service the only way to tell them apart was ad-hoc SQL against
 * production. LEGACY-RME-OPS-CLI-1 established that an operational question
 * deserves a canonical command instead: SQL/Tinker is never a supported path,
 * so the diagnosis lives here, is read-only, and is reproducible.
 *
 * IDENTITY IS EXACT, ALWAYS. This service reports a match only when the stored
 * Nomor RM equals the input, or ends with it as a WHOLE manual segment. A
 * near-miss (one digit away, same length) is computed deliberately — but it is
 * returned under `investigative_signal` and stamped `bindable => false`, because
 * "27541 looks like 22541" is a lead for a human, never an identity. Nothing
 * here may be used to bind a document to a patient.
 *
 * IT CHANGES NOTHING. No insert, no update, no soft delete, no repair. A wrong
 * or missing Nomor RM is corrected by a human through canonical registration,
 * not by this audit.
 *
 * PII POLICY. Patient id, canonical Nomor RM, branch code, counts, booleans and
 * stable reason codes. NEVER a name, KTP/NIK, phone, address, birth date or any
 * clinical detail — the Nomor RM is the identifier the archive already reasons
 * about, and it is the only identifying value this report is allowed to print.
 *
 * SOFT-DELETED ROWS COUNT. Existence is asked with `withTrashed()`, the same way
 * {@see PatientMedicalRecordNumberService::exists()} asks it. A soft-deleted
 * patient still owns its Nomor RM, so reporting "not found" for one would be a
 * lie that invites a duplicate registration.
 */
class LegacyRmePatientResolutionAuditService
{
    /**
     * Shortest input that may be matched as a suffix. Mirrors
     * {@see CrossBranchPatientLookupService::MIN_SUFFIX_LENGTH}
     * so the audit never claims to resolve something the operator-facing search
     * would refuse to look for.
     */
    public const MIN_SUFFIX_LENGTH = 4;

    /** Hard cap on rows echoed for any single finding, so a broken master cannot flood a terminal. */
    public const REPORT_LIMIT = 25;

    /**
     * Ceiling on the same-length candidate set the near-miss scan may hydrate.
     * Generous enough that the signal stays useful on a real branch, bounded so
     * the audit can never turn into a full read of the patient master.
     */
    public const SIGNAL_SCAN_LIMIT = 2000;

    public function __construct(
        private readonly PatientMedicalRecordNumberService $medicalRecordNumbers,
    ) {}

    /**
     * Resolve one Nomor RM — raw manual number or full canonical value — to the
     * patient it names, if any, WITH the investigative near-miss signal.
     *
     * This is the DIAGNOSTIC entry point, for an operator asking a question at a
     * terminal. It is deliberately not the one the upload write path calls: see
     * {@see self::resolveIdentity()}.
     *
     * @return array<string, mixed>
     */
    public function resolve(string $input): array
    {
        $identity = $this->identify(trim($input));

        // The signal is skipped for the two codes that mean "nothing was really
        // asked" — an empty or too-short input has no meaningful neighbourhood.
        $signal = in_array($identity['code'], [
            LegacyRmePatientResolution::CODE_EMPTY_INPUT,
            LegacyRmePatientResolution::CODE_TOO_SHORT,
        ], true)
            ? []
            : $this->investigativeSignal($identity['query']);

        return $this->report(
            $identity['query'],
            $identity['code'],
            $identity['matches'],
            $signal,
            $identity['crossed'],
        );
    }

    /**
     * LEGACY-RME-SOURCE-RM-BINDING-1 — identity ONLY: exact match, then whole
     * manual segment, and nothing else.
     *
     * WHY IT IS SEPARATE FROM {@see self::resolve()}. The identity rules are
     * IDENTICAL — both call the same private {@see self::identify()}, so there
     * is exactly one implementation of "which patient does this number name?"
     * and the write path cannot drift from the diagnostic. What differs is the
     * near-miss signal, and it differs for two independent reasons:
     *
     *   COST. `investigativeSignal()` hydrates up to SIGNAL_SCAN_LIMIT rows and
     *   runs a Levenshtein distance over each. That is the right price for an
     *   operator who typed one command; it is the wrong price on a request that
     *   is about to store a file, and the database performance contract does not
     *   want it on a write path.
     *
     *   MEANING. The signal answers "what does this number look like?", and the
     *   binding gate must never be able to see that answer, let alone act on it.
     *   A component that decides identity should not be holding a list of
     *   patients who are one digit away.
     *
     * @return array<string, mixed>
     */
    public function resolveIdentity(string $input): array
    {
        $identity = $this->identify(trim($input));

        return $this->report(
            $identity['query'],
            $identity['code'],
            $identity['matches'],
            [],
            $identity['crossed'],
        );
    }

    /**
     * THE single implementation of "which patient does this Nomor RM name?".
     *
     * Exact first, then the whole manual segment. Nothing fuzzy, ever.
     *
     * @return array{query: string, code: string, matches: list<array<string, mixed>>, crossed: list<array<string, mixed>>}
     */
    private function identify(string $query): array
    {
        if ($query === '') {
            return ['query' => $query, 'code' => LegacyRmePatientResolution::CODE_EMPTY_INPUT, 'matches' => [], 'crossed' => []];
        }

        // Exact first — the only form that is identity on its own terms.
        //
        // REVISION-TELKOMAS-BRANCH-CODE-TKM1-TO-TLK1-1 — "exact" spans every
        // spelling of the SAME number. When a branch's code is revised, the
        // patient's stored Nomor RM is migrated to the canonical form, but the
        // card in their wallet and the number printed on the archived documents
        // still carry the deprecated one. Matching the literal string alone
        // would report those numbers as belonging to nobody — and this is the
        // resolver the legacy-archive write path binds identity with, so a
        // document would be refused as "wrong patient" on the strength of a
        // rename. The variants come from PatientMedicalRecordNumberService, so
        // only the branch-code SEGMENT ever varies; the year and the manual
        // sequence are matched exactly, and a value that is not a canonical
        // Nomor RM yields itself alone.
        //
        // This does not weaken ambiguity handling: if two patients somehow held
        // the two spellings, both rows come back and the caller refuses as
        // EXACT_AMBIGUOUS rather than picking one.
        $exact = $this->rows(
            Patient::withTrashed()->whereIn(
                'medical_record_number',
                $this->medicalRecordNumbers->equivalentNumbers($query),
            )
        );

        if ($exact->isNotEmpty()) {
            return [
                'query' => $query,
                'code' => $exact->count() === 1
                    ? LegacyRmePatientResolution::CODE_EXACT_UNIQUE
                    : LegacyRmePatientResolution::CODE_EXACT_AMBIGUOUS,
                'matches' => $exact->all(),
                'crossed' => [],
            ];
        }

        if (mb_strlen($query) < self::MIN_SUFFIX_LENGTH) {
            return ['query' => $query, 'code' => LegacyRmePatientResolution::CODE_TOO_SHORT, 'matches' => [], 'crossed' => []];
        }

        // A raw manual number is matched against the MANUAL SEGMENT of a
        // canonical Nomor RM, never against the whole string. `LIKE '%2541'`
        // would also match the manual number `22541`, which is a different
        // patient — dropping a significant digit is exactly what identity
        // resolution may never do.
        $suffix = $this->rows(
            Patient::withTrashed()->where('medical_record_number', 'LIKE', '%'.$this->escapeLike($query))
        );

        $segmentMatches = $suffix->filter(
            fn (array $row): bool => $row['manual_segment'] !== null && $row['manual_segment'] === $query
        )->values();

        $crossed = $suffix->reject(
            fn (array $row): bool => $row['manual_segment'] !== null && $row['manual_segment'] === $query
        )->values();

        if ($segmentMatches->isNotEmpty()) {
            return [
                'query' => $query,
                'code' => $segmentMatches->count() === 1
                    ? LegacyRmePatientResolution::CODE_SEGMENT_UNIQUE
                    : LegacyRmePatientResolution::CODE_SEGMENT_AMBIGUOUS,
                'matches' => $segmentMatches->all(),
                'crossed' => $crossed->all(),
            ];
        }

        return [
            'query' => $query,
            'code' => LegacyRmePatientResolution::CODE_NOT_FOUND,
            'matches' => [],
            'crossed' => $crossed->all(),
        ];
    }

    /**
     * Master-data conditions that make a legacy archive impossible or unsafe for
     * a patient. Every one of them is a HUMAN correction through canonical
     * registration — this method only names them.
     *
     * @return array<string, mixed>
     */
    public function integrity(): array
    {
        $live = Patient::query()
            ->select(['id', 'medical_record_number', 'branch_id', 'is_active'])
            ->with('branch:id,code')
            ->get();

        // A Nomor RM the canonical parser cannot read has no branch segment, so
        // LegacyRmeBranchResolver fails closed for this patient forever: they can
        // never receive a legacy archive until the master data is corrected.
        $unparseable = $live
            ->filter(fn (Patient $p): bool => $this->medicalRecordNumbers->parse($p->medical_record_number) === null)
            ->map(fn (Patient $p): array => [
                'patient_id' => (int) $p->getKey(),
                'medical_record_number' => $p->medical_record_number,
                'branch_code' => $p->branch?->code,
                'is_active' => (bool) $p->is_active,
                'consequence' => 'ARCHIVE_BRANCH_UNRESOLVABLE',
            ])
            ->values();

        // A duplicated Nomor RM makes every lookup ambiguous, and an ambiguous
        // identity is refused rather than guessed.
        //
        // DEFENCE IN DEPTH. `mst_patients.medical_record_number` carries a
        // non-partial UNIQUE index — soft-deleted rows included — so this can
        // report nothing while that constraint holds, exactly like the
        // ambiguous-branch arm of LegacyRmeBranchResolver. It is kept because
        // the alternative, if the index were ever relaxed, would be to let the
        // archive silently file a document against one of two identities.
        $duplicates = Patient::withTrashed()
            ->selectRaw('medical_record_number, count(*) as total')
            ->whereNotNull('medical_record_number')
            ->groupBy('medical_record_number')
            ->havingRaw('count(*) > 1')
            ->limit(self::REPORT_LIMIT)
            ->get()
            ->map(fn ($row): array => [
                'medical_record_number' => $row->medical_record_number,
                'patients' => (int) $row->total,
            ])
            ->values();

        return [
            'live_patients' => $live->count(),
            'unparseable_medical_record_numbers' => $unparseable->all(),
            'unparseable_count' => $unparseable->count(),
            'duplicate_medical_record_numbers' => $duplicates->all(),
            'duplicate_count' => $duplicates->count(),
            'archive_bindings' => $this->archiveBindings(),
        ];
    }

    /**
     * Distinct source documents filed against the SAME patient inside one wave.
     *
     * A patient legitimately owns several archive pages over the years, so this
     * is a SIGNAL, not a verdict — but it is the only mis-binding an audit can
     * detect today, because the Nomor RM printed on a document is never captured
     * (see the product gap recorded with this sprint). It is reported separately
     * for withdrawn and published rows: a cancelled binding was already caught
     * by the wave, a published one deserves a human look.
     *
     * @return array<string, mixed>
     */
    private function archiveBindings(): array
    {
        $imports = LegacyRmeImport::query()
            ->select(['id', 'patient_id', 'migration_wave_id', 'status', 'source_pdf_sha256'])
            ->whereNotNull('patient_id')
            ->whereNotNull('migration_wave_id')
            ->get();

        $shared = $imports
            ->groupBy(fn (LegacyRmeImport $i): string => $i->migration_wave_id.':'.$i->patient_id)
            ->filter(fn (Collection $group): bool => $group->pluck('source_pdf_sha256')->unique()->count() > 1)
            ->map(function (Collection $group): array {
                $first = $group->first();

                return [
                    'migration_wave_id' => (int) $first->migration_wave_id,
                    'patient_id' => (int) $first->patient_id,
                    'distinct_documents' => $group->pluck('source_pdf_sha256')->unique()->count(),
                    'import_ids' => $group->pluck('id')->map(fn ($id): int => (int) $id)->sort()->values()->all(),
                    'statuses' => $group->pluck('status')->unique()->sort()->values()->all(),
                    'published' => $group->contains(
                        fn (LegacyRmeImport $i): bool => $i->status === LegacyRmeImportStatus::PUBLISHED
                    ),
                ];
            })
            ->values();

        return [
            'multi_document_patients' => $shared->all(),
            'withdrawn_only' => $shared->reject(fn (array $r): bool => $r['published'])->count(),
            'published' => $shared->filter(fn (array $r): bool => $r['published'])->count(),
        ];
    }

    /**
     * Near-miss numbers: same length, exactly one differing character in the
     * MANUAL SEGMENT.
     *
     * This is the one place similarity is computed, and it exists so an operator
     * can SEE that `27541` sits one digit from `22541` without any part of the
     * system being able to act on it. Every row is stamped `bindable => false`.
     * A near miss is never promoted to a match, never ordered ahead of an exact
     * result, and never returned when the input resolved exactly.
     *
     * BOUNDED IN SQL. A near miss must have a manual segment of exactly the same
     * length, so the candidate set is narrowed with `_` — the single-character
     * LIKE wildcard — before any row is hydrated: `%-_____` means "a dash, then
     * exactly five characters, then end". Scanning the whole patient master into
     * PHP to compute an edit distance would be an unbounded master-table read,
     * which the database performance contract forbids. The wildcard is built
     * from the needle's LENGTH only; the needle's own characters are never
     * interpolated, so nothing here is user-controlled SQL.
     *
     * @return list<array<string, mixed>>
     */
    private function investigativeSignal(string $query): array
    {
        $needle = $this->medicalRecordNumbers->parse($query)?->sequence ?? $query;

        if ($needle === '' || mb_strlen($needle) < self::MIN_SUFFIX_LENGTH) {
            return [];
        }

        return Patient::withTrashed()
            ->select(['id', 'medical_record_number', 'branch_id', 'is_active', 'deleted_at'])
            ->where('medical_record_number', 'LIKE', '%-'.str_repeat('_', mb_strlen($needle)))
            ->with('branch:id,code')
            ->limit(self::SIGNAL_SCAN_LIMIT)
            ->get()
            ->map(fn (Patient $p): array => $this->row($p))
            ->filter(function (array $row) use ($needle): bool {
                $segment = $row['manual_segment'];

                return is_string($segment)
                    && $segment !== $needle
                    && mb_strlen($segment) === mb_strlen($needle)
                    && levenshtein($segment, $needle) === 1;
            })
            ->map(fn (array $row): array => $row + [
                'bindable' => false,
                'identity_authority' => false,
                'note' => 'INVESTIGATIVE_SIGNAL_ONLY',
            ])
            ->take(self::REPORT_LIMIT)
            ->values()
            ->all();
    }

    /**
     * @param  Builder<Patient>  $query
     * @return Collection<int, array<string, mixed>>
     */
    private function rows($query): Collection
    {
        return $query
            ->select(['id', 'medical_record_number', 'branch_id', 'is_active', 'deleted_at'])
            ->with('branch:id,code')
            ->limit(self::REPORT_LIMIT)
            ->get()
            ->map(fn (Patient $p): array => $this->row($p));
    }

    /** @return array<string, mixed> */
    private function row(Patient $patient): array
    {
        $parts = $this->medicalRecordNumbers->parse($patient->medical_record_number);

        return [
            'patient_id' => (int) $patient->getKey(),
            'medical_record_number' => $patient->medical_record_number,
            'manual_segment' => $parts?->sequence,
            'branch_code' => $parts?->branchCode ?? $patient->branch?->code,
            'is_active' => (bool) $patient->is_active,
            'is_soft_deleted' => $patient->deleted_at !== null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $matches
     * @param  list<array<string, mixed>>  $signal
     * @param  list<array<string, mixed>>  $crossed
     * @return array<string, mixed>
     */
    private function report(string $query, string $code, array $matches, array $signal, array $crossed = []): array
    {
        return [
            'query' => $query,
            'resolution' => $code,
            'resolved' => LegacyRmePatientResolution::isResolved($code),
            'bindable' => LegacyRmePatientResolution::isBindable($code),
            'match_count' => count($matches),
            'matches' => $matches,
            'investigative_signal' => $signal,
            'suffix_crossed_manual_segment' => $crossed,
            'explanation' => LegacyRmePatientResolution::explain($code),
        ];
    }

    /** Escape LIKE metacharacters so the input is matched literally. */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
