<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\LegacyRme\Interfaces\LegacyRmeRecordRepositoryInterface;
use App\Modules\LegacyRme\Models\LegacyRmeRecord;
use App\Modules\LegacyRme\Models\LegacyRmeRecordPage;
use App\Modules\LegacyRme\Services\LegacyRmeAuditService;
use App\Modules\LegacyRme\Services\LegacyRmeStorageService;
use App\Modules\LegacyRme\Support\LegacyRmeAuditEvent;
use App\Modules\LegacyRme\Support\LegacyRmeFeatureGuard;
use App\Modules\LegacyRme\Support\LegacyRmeWorkspaceScope;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * LEGACY-RME-PDF-1C — the read-only viewer for a PUBLISHED legacy RME record.
 *
 * READ ONLY BY CONSTRUCTION. There is no update, no delete and no republish
 * action here, because a published legacy record is immutable clinical
 * evidence; the only supported correction is VOID (its own permission) plus a
 * fresh import.
 *
 * PRIVACY. The record's source PDF and rendered pages stay on the private disk
 * and are only ever reachable through these policy-gated streaming actions —
 * never a public URL, never a storage symlink, never a signed direct link. The
 * request supplies an id, never a path: the record is resolved through the
 * repository with the caller's server-resolved branch scope, and a page is
 * resolved THROUGH its record, so neither an id nor a page number can traverse
 * to another patient's archive. The absolute filesystem path never leaves the
 * storage service, and the download filename is generic.
 */
class LegacyRmeRecordController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly LegacyRmeRecordRepositoryInterface $records,
        private readonly LegacyRmeStorageService $storage,
        private readonly LegacyRmeWorkspaceScope $scope,
        private readonly LegacyRmeAuditService $audit,
        private readonly LegacyRmeFeatureGuard $feature,
    ) {}

    public function show(Request $request, int $record): View
    {
        $this->assertFeatureEnabled();

        $legacyRecord = $this->resolve($request, $record);
        $this->authorize('view', $legacyRecord);

        $this->audit->logRecordEvent(LegacyRmeAuditEvent::RECORD_VIEWED, $legacyRecord, [], $request->user());

        return view('rme.legacy-records.show', [
            'record' => $legacyRecord->load([
                'patient:id,name,medical_record_number',
                'originBranch:id,name,code',
                'publishedBy:id,name',
            ]),
            'pages' => $this->records->pagesFor($legacyRecord),
        ]);
    }

    public function source(Request $request, int $record): StreamedResponse
    {
        $this->assertFeatureEnabled();

        $legacyRecord = $this->resolve($request, $record);
        $this->authorize('viewFile', $legacyRecord);
        $this->assertStreamable($legacyRecord);

        $path = (string) $legacyRecord->source_pdf_path;

        $disk = $this->storage->diskFor($legacyRecord->source_disk);

        abort_unless($path !== '' && $disk->exists($path), 404);

        $this->audit->logRecordEvent(LegacyRmeAuditEvent::RECORD_SOURCE_VIEWED, $legacyRecord, [], $request->user());

        return $disk->response($path, 'arsip-rme-lama.pdf', [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="arsip-rme-lama.pdf"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function page(Request $request, int $record, int $page): StreamedResponse
    {
        $this->assertFeatureEnabled();

        $legacyRecord = $this->resolve($request, $record);
        $this->authorize('viewFile', $legacyRecord);
        $this->assertStreamable($legacyRecord);

        $pageRecord = $this->records->findPage($legacyRecord, $page);

        abort_if($pageRecord === null, 404);

        $wantsThumbnail = $request->string('variant')->toString() === 'thumbnail';
        $path = $this->resolvePagePath($pageRecord, $wantsThumbnail);

        $disk = $this->storage->diskFor($pageRecord->background_disk);

        abort_unless($path !== null && $disk->exists($path), 404);

        // Only a real page view is audited. Opening the archive already wrote a
        // RECORD_VIEWED row, and the gallery requests one thumbnail per page —
        // auditing those too would put a row per thumbnail in the trail and
        // bury the meaningful events.
        if (! $wantsThumbnail) {
            $this->audit->logRecordEvent(LegacyRmeAuditEvent::RECORD_PAGE_VIEWED, $legacyRecord, [
                'page_number' => $page,
                'variant' => 'page',
            ], $request->user());
        }

        return $disk->response($path, sprintf('halaman-%04d.png', $page), [
            'Content-Type' => 'image/png',
            'Content-Disposition' => sprintf('inline; filename="halaman-%04d.png"', $page),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Out of scope is a 404 rather than a 403, exactly as on the staging side:
     * an operator must not be able to probe which archive ids exist in a branch
     * they cannot see.
     */
    private function resolve(Request $request, int $id): LegacyRmeRecord
    {
        $user = $request->user();

        $record = $this->records->findByIdInBranches(
            $this->scope->branchIdsFor($user),
            $id,
            $this->scope->includesUnscopedRowsFor($user),
        );

        abort_if($record === null, 404);

        return $record;
    }

    private function resolvePagePath(LegacyRmeRecordPage $page, bool $wantsThumbnail): ?string
    {
        if ($wantsThumbnail) {
            return is_string($page->thumbnail_path) && $page->thumbnail_path !== ''
                ? $page->thumbnail_path
                : null;
        }

        return is_string($page->background_path) && $page->background_path !== ''
            ? $page->background_path
            : null;
    }

    /**
     * Only a PUBLISHED archive streams its bytes.
     *
     * Enforced HERE and not only in the policy: the single global Gate::before
     * grants Super Admin every ability, and Super Admin is precisely who
     * operates this capability — so a policy-only rule would be no rule at all
     * for the one actor that matters. The policy keeps the same check as
     * defence in depth.
     *
     * VOID exists for a mis-filed archive (canonically: attached to the WRONG
     * patient), so serving those bytes on would keep serving the leak the void
     * retracted. The record row itself stays readable — retracted, not erased.
     */
    private function assertStreamable(LegacyRmeRecord $record): void
    {
        abort_unless($record->isPublished(), 404);
    }

    private function assertFeatureEnabled(): void
    {
        abort_unless($this->feature->enabled(), 404);
    }
}
