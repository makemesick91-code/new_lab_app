<?php

declare(strict_types=1);

namespace App\Modules\MedicalRecord\Services;

use App\Models\User;
use App\Modules\LegacyRme\Models\LegacyRmeRecord;
use App\Modules\LegacyRme\Services\LegacyRmePatientHistoryService;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Support\RmeWorkspaceDocument;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

/**
 * LEGACY-RME-DOCTOR-WORKSPACE-1 — the doctor's RME document rail.
 *
 * THE PROBLEM THIS SOLVES. The patient's published legacy archive was only
 * reachable from the clinical-history card at the very bottom of the RME
 * workspace, below the whole multi-page handwriting canvas and its overlay
 * editor. A doctor who wanted the patient's old RME had to scroll past the
 * surface they were actively writing on to discover it even existed. This
 * builds the same documents as a compact selector that sits at the TOP of the
 * workspace instead.
 *
 * IT IS A PROJECTION, NOT A SECOND SOURCE OF TRUTH.
 *
 *  - The native side is the sheet collection the workspace ALREADY resolved
 *    under its own authorization. It is passed in, never re-queried, so the
 *    rail can never disagree with the sheet navigation about which sheets the
 *    patient has.
 *  - The legacy side comes from `LegacyRmePatientHistoryService`, the canonical
 *    published-archive read. Publication status, VOID filtering, branch scope,
 *    the feature flag and the doctor's clinical scope all stay inside that one
 *    service — none of it is reimplemented here.
 *
 * Consequently a document appearing in this rail is exactly a document the
 * caller was already entitled to read. The rail is a shortcut to existing
 * evidence, never a widening of it — and it is not the authorization boundary
 * either: every byte is still fetched through the policy-gated streaming
 * routes, which re-authorize on their own.
 *
 * Read-only: nothing here writes, and no legacy record is turned into a
 * ClinicVisit or a MedicalRecord so that it can be listed.
 */
class RmeWorkspaceDocumentPresenter
{
    public function __construct(
        private readonly LegacyRmePatientHistoryService $legacyHistory,
    ) {}

    /**
     * The patient's selectable RME documents, NEWEST FIRST.
     *
     * Newest first matches how a doctor reads in the workspace — from the
     * encounter they are standing in, backwards into the past — and matches
     * the ordering the clinical-history card already uses.
     *
     * `$anchorVisitId` MUST be the patient's CANONICAL workspace visit, the
     * same anchor the sheet navigation uses. The workspace redirects any
     * non-canonical visit to the canonical URL and that redirect does not carry
     * `sheet`, so anchoring a sheet link on its own visit would silently lose
     * the selection for every sheet outside the canonical visit.
     *
     * @param  iterable<int, MedicalRecord>  $nativeSheets  already authorized by the caller
     * @return Collection<int, RmeWorkspaceDocument>
     */
    public function documentsFor(
        ?User $user,
        int $patientId,
        iterable $nativeSheets,
        ?int $activeSheetId = null,
        ?int $anchorVisitId = null,
        // LEGACY-RME-DOCTOR-WORKSPACE-1A — the caller may pass the records it
        // already resolved for the unified page sequence. Same collection, same
        // authorization; it only avoids resolving the patient's archive twice
        // in one request. Null keeps the original self-resolving behaviour.
        ?Collection $legacyRecords = null,
    ): Collection {
        $documents = collect();

        foreach ($nativeSheets as $index => $sheet) {
            $documents->push($this->nativeDocument($sheet, $index, $activeSheetId, $anchorVisitId));
        }

        foreach ($legacyRecords ?? $this->legacyRecordsFor($user, $patientId) as $record) {
            $documents->push($this->legacyDocument($record));
        }

        return $documents
            ->sortByDesc(fn (RmeWorkspaceDocument $document): string => $document->sortKey())
            ->values();
    }

    /**
     * Whether this patient has any published legacy archive the caller may see.
     *
     * Used to decide whether the rail says anything about legacy documents at
     * all, so a patient with a purely native record keeps an uncluttered
     * workspace.
     */
    public function hasLegacyDocuments(?User $user, int $patientId): bool
    {
        return $this->legacyRecordsFor($user, $patientId)->isNotEmpty();
    }

    /**
     * @return Collection<int, LegacyRmeRecord>
     */
    private function legacyRecordsFor(?User $user, int $patientId): Collection
    {
        return $this->legacyHistory->publishedRecordsFor($user, $patientId);
    }

    private function nativeDocument(
        MedicalRecord $sheet,
        int $index,
        ?int $activeSheetId,
        ?int $anchorVisitId,
    ): RmeWorkspaceDocument {
        $visit = $sheet->clinicVisit;

        // The canonical clinical date of a native sheet is its visit's
        // visit_date — the same field the legacy date rule compares against.
        // Never created_at: a sheet may be written long after the encounter.
        $clinicalDate = $visit?->visit_date;

        return RmeWorkspaceDocument::native(
            sheetId: (int) $sheet->getKey(),
            clinicalDate: $clinicalDate,
            label: 'Lembar '.($index + 1),
            detail: is_string($visit?->visit_number) ? $visit->visit_number : null,
            isCurrent: $activeSheetId !== null && (int) $sheet->getKey() === $activeSheetId,
            url: $this->nativeUrl($sheet, $anchorVisitId),
        );
    }

    private function legacyDocument(LegacyRmeRecord $record): RmeWorkspaceDocument
    {
        $pageCount = (int) ($record->page_count ?? 0);

        return RmeWorkspaceDocument::legacy(
            recordId: (int) $record->getKey(),
            clinicalDate: $record->rme_date,
            label: 'RME Lama (Arsip)',
            detail: $pageCount > 0 ? $pageCount.' halaman' : 'Dokumen arsip',
            url: $this->legacyUrl($record),
            pageCount: $pageCount,
        );
    }

    /**
     * Selecting a native sheet reuses the workspace's OWN existing mechanism —
     * the canonical workspace URL with `?sheet=` — so this rail introduces no
     * second way to open a sheet and no new route.
     *
     * `Route::has` guards keep the projection renderable where a route is
     * intentionally absent (a trimmed route cache); a missing link degrades to
     * plain text rather than a 500.
     */
    private function nativeUrl(MedicalRecord $sheet, ?int $anchorVisitId): ?string
    {
        $anchorVisitId ??= $sheet->clinic_visit_id;

        if ($anchorVisitId === null || ! Route::has('rme.visits.medical-record.show')) {
            return null;
        }

        return route('rme.visits.medical-record.show', [
            $anchorVisitId,
            'sheet' => $sheet->getKey(),
        ]);
    }

    /**
     * The standalone viewer page. It is the no-JavaScript fallback for opening
     * an archive; the workspace's own overlay viewer is the primary path and
     * streams through the same policy-gated routes.
     */
    private function legacyUrl(LegacyRmeRecord $record): ?string
    {
        return Route::has('rme.legacy-records.show')
            ? route('rme.legacy-records.show', $record->getKey())
            : null;
    }
}
