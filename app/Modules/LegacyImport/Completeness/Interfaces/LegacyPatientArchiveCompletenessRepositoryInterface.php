<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\Completeness\Interfaces;

use App\Modules\LegacyImport\Completeness\Support\LegacyCompletenessQuery;
use App\Modules\LegacyRme\Interfaces\LegacyRmeImportRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * FEATURE-LEGACY-PATIENT-DOCUMENT-COMPLETENESS-1 — the read boundary for the
 * legacy archive completeness report.
 *
 * READ-ONLY BY CONSTRUCTION. There is no create, update, delete, upsert or
 * status-transition method here and there must never be one. The completeness
 * page is a monitor: it reports what the legacy lifecycles have already
 * decided and has no authority over any of them. A write method on this
 * interface would be the first step toward a second, competing legacy
 * subsystem.
 *
 * BRANCH SCOPE ARRIVES ALREADY RESOLVED. Every method takes the actor's branch
 * ids as a value object the SERVICE built from the server's own authorities. The
 * repository never reads a request, never resolves a scope and never widens
 * one; an empty id list resolves to no rows at all (fail closed), never to "no
 * filter".
 *
 * PROVENANCE IS NOT NEGOTIABLE. Every query here is unconditionally restricted
 * to patients created by the canonical legacy patient import, and every one of
 * them derives that restriction from the single `scopedPatients()` definition
 * in {@see LegacyPatientArchiveCompletenessRepository} rather than restating it
 * — including the methods that also receive explicit patient ids, where the ids
 * narrow and the scope authorises. A natively registered patient is out of
 * scope for this report by definition, not by filter, so no caller can ask for
 * one.
 */
interface LegacyPatientArchiveCompletenessRepositoryInterface
{
    /**
     * One page of legacy patients, with the effective state of both document
     * types already derived in SQL.
     *
     * @return LengthAwarePaginator<int, object>
     */
    public function paginate(LegacyCompletenessQuery $query): LengthAwarePaginator;

    /**
     * The summary counters for everything the actor can currently reach — not
     * just the current page, and never the whole estate.
     *
     * Computed in ONE aggregate pass over the same join set, scope and search
     * the listing uses, so the cards and the table can never disagree about
     * what is incomplete. Only the status filter is excluded, because the cards
     * are what the operator pivots between.
     *
     * @return array<string, int>
     */
    public function summary(LegacyCompletenessQuery $query): array;

    /**
     * The canonical staging status of the slot-occupying import for each of the
     * given patients, keyed by patient id.
     *
     * Mirrors {@see LegacyRmeImportRepositoryInterface::firstSlotOccupyingForPatient()}
     * — lowest id first, soft-deleted rows INCLUDED — so the label this page
     * shows describes the same row the occupancy guard would find.
     *
     * The ids are a page-sized NARROWING, not an authority: the scope and the
     * provenance predicate are re-applied from `$query`, so an id from outside
     * the actor's scope yields nothing.
     *
     * @param  list<int>  $patientIds
     * @return array<int, string>
     */
    public function slotOccupyingRmeStatuses(LegacyCompletenessQuery $query, array $patientIds): array;

    /**
     * @param  list<int>  $patientIds
     * @return array<int, string>
     */
    public function slotOccupyingOdontogramStatuses(LegacyCompletenessQuery $query, array $patientIds): array;

    /**
     * Patient ids whose in-flight import of each type is held by a blocking
     * review triage annotation.
     *
     * Scoped the same way as the status lookups above: `$patientIds` narrows,
     * `$query` authorises.
     *
     * @param  list<int>  $patientIds
     * @return array{legacy_rme: list<int>, legacy_odontogram: list<int>}
     */
    public function reviewBlockedPatientIds(LegacyCompletenessQuery $query, array $patientIds): array;

    /**
     * The branches that actually hold legacy patients inside the actor's scope,
     * for the branch filter.
     *
     * Derived from the scope rather than from the branch master, so the filter
     * can never offer a branch whose rows the actor may not read.
     *
     * @return array<int, string>
     */
    public function branchOptions(LegacyCompletenessQuery $query): array;
}
