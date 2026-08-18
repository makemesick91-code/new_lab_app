<?php

declare(strict_types=1);

namespace App\Modules\MedicalRecord\Services;

use App\Models\User;
use App\Modules\LegacyRme\Models\LegacyRmeRecord;
use App\Modules\LegacyRme\Services\LegacyRmePatientHistoryService;
use App\Modules\MedicalRecord\Support\RmeWorkspacePage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

/**
 * LEGACY-RME-DOCTOR-WORKSPACE-1A — builds the doctor's UNIFIED
 * "RME Tulisan Tangan Lengkap" page sequence.
 *
 * WHY THIS EXISTS
 * ---------------
 * LEGACY-RME-DOCTOR-WORKSPACE-1 put the published archive in a rail at the top
 * of the workspace and opened it in a separate viewer. That is reachable, but
 * it is not the workflow the owner asked for: a doctor should reach historical
 * evidence with the SAME previous/next/swipe they already use for handwritten
 * pages, not by breaking out into a second navigation experience.
 *
 * So this service merges two sources into ONE 1-based page sequence:
 *
 *     native handwriting pages  (editable, persisted)
 *     legacy archive PDF pages  (read-only, virtual)
 *
 * A legacy PDF with N pages contributes N virtual pages, so `next` walks
 * naturally from page 1 to page 2 of the same document and then on to the next
 * document. Nothing is persisted: no ClinicVisit, no MedicalRecord and no
 * handwriting row is created to make an archive page "fit" the sequence.
 *
 * ORDERING (see the sprint doc for the rationale)
 * -----------------------------------------------
 * Native pages first, in their existing chronological order, then the archive
 * newest-document-first with pages ascending inside each document.
 *
 * Strictly chronological order would put every legacy page BEFORE every native
 * page — the date rule guarantees a legacy date is earlier than the earliest
 * native RME date — which would force a doctor to swipe through years of
 * history before reaching the page they need to write on. The editable present
 * therefore stays at the front, and history begins one swipe past the last
 * native page, newest first (the same "newest first" the document rail uses).
 *
 * AUTHORIZATION
 * -------------
 * This service resolves NOTHING itself. The legacy side comes from
 * LegacyRmePatientHistoryService::publishedRecordsFor(), which is the canonical
 * read path and already applies the read permissions, the branch scope, the
 * doctor patient scope and the published-only/VOID policy. Re-deriving any of
 * those here would create a second, divergent definition of "which archive may
 * this user see" — exactly the drift LEGACY-RME-PDF-ROLL-2 found.
 *
 * Being listed here is NOT an authorization decision either: every byte is
 * still fetched through the policy-gated streaming routes, which re-authorize
 * on their own request.
 */
class RmeWorkspacePageSequencer
{
    public function __construct(
        private readonly LegacyRmePatientHistoryService $legacyHistory,
    ) {}

    /**
     * The unified page sequence for one patient.
     *
     * @param  iterable<int, array<string, mixed>>  $nativeBook  orderedHandwritingBookForPatient()
     * @param  Collection<int, LegacyRmeRecord>|null  $legacyRecords  already-resolved records; resolved here when null
     * @return Collection<int, RmeWorkspacePage>
     */
    public function sequenceFor(
        ?User $user,
        int $patientId,
        iterable $nativeBook,
        ?Collection $legacyRecords = null,
    ): Collection {
        $pages = collect();
        $index = 1;

        foreach ($nativeBook as $nativePage) {
            $pages->push(RmeWorkspacePage::native(
                workspaceIndex: $index,
                nativePage: $nativePage,
            ));
            $index++;
        }

        $records = $legacyRecords ?? $this->legacyRecordsFor($user, $patientId);

        // ONE query for every record's rendered-page count. Loading it per
        // record inside the loop below would be an N+1 on a patient with many
        // archived documents.
        $records = $this->withRenderedPageCounts($records);

        $documentIndex = 1;

        foreach ($this->orderedLegacyRecords($records) as $record) {
            $renderedPages = $this->renderedPageCount($record);

            // A record whose pages were never rasterised still belongs in the
            // sequence — it is real evidence the doctor may read. It gets ONE
            // virtual page that falls back to the inline source PDF, instead of
            // being silently dropped or turned into broken images.
            $pageCount = max(1, $renderedPages);

            for ($pdfPage = 1; $pdfPage <= $pageCount; $pdfPage++) {
                $pages->push(RmeWorkspacePage::legacy(
                    workspaceIndex: $index,
                    legacyRecordId: (int) $record->getKey(),
                    legacyPdfPage: $pdfPage,
                    legacyPdfPageCount: $pageCount,
                    legacyDocumentIndex: $documentIndex,
                    clinicalDate: $record->rme_date,
                    pageImageUrl: $renderedPages > 0 ? $this->pageUrl($record, $pdfPage) : null,
                    sourceUrl: $this->sourceUrl($record),
                    hasRenderedPage: $renderedPages > 0,
                ));
                $index++;
            }

            $documentIndex++;
        }

        return $pages->values();
    }

    /**
     * Resolve a requested workspace page index against the sequence.
     *
     * Out-of-range and non-numeric input is CLAMPED to the sequence rather than
     * trusted, so a crafted `?rm_page=` can only ever land on a page this
     * patient's sequence actually contains.
     *
     * @param  Collection<int, RmeWorkspacePage>  $pages
     */
    public function resolveActivePage(Collection $pages, int $requestedIndex): ?RmeWorkspacePage
    {
        if ($pages->isEmpty()) {
            return null;
        }

        $clamped = max(1, min($requestedIndex, $pages->count()));

        return $pages->firstWhere('workspaceIndex', $clamped) ?? $pages->first();
    }

    /**
     * The LAST native page at or before the given index, else the first native
     * page of the sequence.
     *
     * The handwriting form, the canvas and "+ Tambah Halaman RM" must always be
     * bound to a REAL native record. When the doctor is looking at a read-only
     * archive page there is no native page under the cursor, so the form keeps
     * the nearest native page instead — it is never bound to archive evidence.
     *
     * @param  Collection<int, RmeWorkspacePage>  $pages
     */
    public function nativeAnchorFor(Collection $pages, int $activeIndex): ?RmeWorkspacePage
    {
        $natives = $pages->filter(fn (RmeWorkspacePage $page): bool => $page->isNative());

        if ($natives->isEmpty()) {
            return null;
        }

        return $natives->filter(fn (RmeWorkspacePage $page): bool => $page->workspaceIndex <= $activeIndex)->last()
            ?? $natives->first();
    }

    /**
     * @param  Collection<int, LegacyRmeRecord>  $records
     * @return Collection<int, LegacyRmeRecord>
     */
    private function withRenderedPageCounts(Collection $records): Collection
    {
        if ($records->isEmpty()) {
            return $records;
        }

        $records->loadCount('pages');

        return $records;
    }

    /**
     * The rendered-page count is the number of ACTUAL rasterised page rows, not
     * the declared `page_count`.
     *
     * A record can carry a declared count while no page was ever rendered
     * (imported before/without a working rasteriser). Trusting the declared
     * value there would put pages in the sequence that can only ever render as
     * a broken image.
     */
    private function renderedPageCount(LegacyRmeRecord $record): int
    {
        $counted = $record->pages_count ?? null;

        if (is_numeric($counted)) {
            return max(0, (int) $counted);
        }

        return max(0, (int) $record->pages()->count());
    }

    /**
     * Newest archive first, then id — a deterministic order that never falls
     * back to the database's row order on a same-day collision.
     *
     * @param  Collection<int, LegacyRmeRecord>  $records
     * @return Collection<int, LegacyRmeRecord>
     */
    private function orderedLegacyRecords(Collection $records): Collection
    {
        return $records
            ->sortByDesc(fn (LegacyRmeRecord $record): string => sprintf(
                '%s|%012d',
                $record->rme_date?->format('Y-m-d') ?? '0000-01-01',
                (int) $record->getKey(),
            ))
            ->values();
    }

    /**
     * @return Collection<int, LegacyRmeRecord>
     */
    private function legacyRecordsFor(?User $user, int $patientId): Collection
    {
        return $this->legacyHistory->publishedRecordsFor($user, $patientId);
    }

    private function pageUrl(LegacyRmeRecord $record, int $page): ?string
    {
        if (! Route::has('rme.legacy-records.pages.show')) {
            return null;
        }

        return route('rme.legacy-records.pages.show', [$record->getKey(), $page]);
    }

    private function sourceUrl(LegacyRmeRecord $record): ?string
    {
        if (! Route::has('rme.legacy-records.source')) {
            return null;
        }

        return route('rme.legacy-records.source', [$record->getKey()]);
    }
}
