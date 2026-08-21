<?php

declare(strict_types=1);

namespace App\Modules\LegacyOdontogram\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\LegacyOdontogram\Interfaces\LegacyOdontogramRecordRepositoryInterface;
use App\Modules\LegacyOdontogram\Models\LegacyOdontogramRecord;
use App\Modules\LegacyOdontogram\Models\LegacyOdontogramRecordPage;
use App\Modules\LegacyOdontogram\Requests\VoidLegacyOdontogramRecordRequest;
use App\Modules\LegacyOdontogram\Services\LegacyOdontogramAuditService;
use App\Modules\LegacyOdontogram\Services\LegacyOdontogramFeatureGuard;
use App\Modules\LegacyOdontogram\Services\LegacyOdontogramStorageService;
use App\Modules\LegacyOdontogram\Services\LegacyOdontogramVoidService;
use App\Modules\LegacyOdontogram\Support\LegacyOdontogramAuditEvent;
use App\Modules\LegacyOdontogram\Support\LegacyOdontogramWorkspaceScope;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * FIX-04b — the read-only viewer for a PUBLISHED legacy odontogram record.
 *
 * READ ONLY BY CONSTRUCTION. There is no update, no delete and no republish
 * action here, because a published legacy odontogram is immutable clinical
 * evidence; the only supported correction is VOID (its own permission) plus a
 * fresh import.
 *
 * PRIVACY. The source PDF and the rendered pages stay on the private disk and
 * are only ever reachable through these policy-gated streaming actions — never
 * a public URL, never a storage symlink, never a signed direct link. The
 * request supplies an ID, never a path: the record is resolved through the
 * repository with the caller's server-resolved branch scope, and a page is
 * resolved THROUGH its record, so neither an id nor a page number can traverse
 * to another patient's archive. The absolute filesystem path never leaves the
 * storage service, and the download filename is generic.
 *
 * READ IS NOT GATED ON THE MIGRATION CAPABILITY. `show`, `source` and `page`
 * deliberately do NOT consult LegacyOdontogramFeatureGuard. That flag is the
 * migration switch; an already-PUBLISHED record is the patient's real clinical
 * history, and a treating doctor must still be able to read it at the next
 * visit without the owner re-opening the ability to import new documents.
 *
 * Nothing is loosened by that: every read still passes `resolve()` (branch
 * scoped — out of scope is a 404), the policy (read permission + branch scope +
 * the doctor's treating relationship) and, for the byte-streaming actions,
 * `assertStreamable()`.
 *
 * `void` is the one action here that CHANGES state, so it stays behind the
 * migration capability exactly like publish: with the capability off the
 * archive is frozen — readable, but neither extended nor retracted.
 */
class LegacyOdontogramRecordController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly LegacyOdontogramRecordRepositoryInterface $records,
        private readonly LegacyOdontogramStorageService $storage,
        private readonly LegacyOdontogramWorkspaceScope $scope,
        private readonly LegacyOdontogramAuditService $audit,
        private readonly LegacyOdontogramFeatureGuard $feature,
    ) {}

    public function show(Request $request, int $record): View
    {
        $legacyRecord = $this->resolve($request, $record);
        $this->authorize('view', $legacyRecord);

        $this->audit->logRecordEvent(
            LegacyOdontogramAuditEvent::RECORD_VIEWED,
            $legacyRecord,
            [],
            $request->user(),
        );

        return view('settings.legacy-odontograms.record', [
            'record' => $legacyRecord->load([
                'patient:id,name,medical_record_number',
                'branch:id,name,code',
                'publishedBy:id,name',
                'voidedBy:id,name',
            ]),
            'pages' => $this->records->pagesFor($legacyRecord),
        ]);
    }

    public function source(Request $request, int $record): StreamedResponse
    {
        $legacyRecord = $this->resolve($request, $record);
        $this->authorize('viewFile', $legacyRecord);
        $this->assertStreamable($legacyRecord);

        $path = (string) $legacyRecord->source_pdf_path;
        $disk = $this->storage->diskFor($legacyRecord->source_disk);

        abort_unless($path !== '' && $disk->exists($path), 404);

        $this->audit->logRecordEvent(
            LegacyOdontogramAuditEvent::RECORD_SOURCE_VIEWED,
            $legacyRecord,
            [],
            $request->user(),
        );

        // A generic filename: the download name must never carry the patient's
        // name or medical-record number out into the reader's file system.
        return $disk->response($path, 'arsip-odontogram-lama.pdf', [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="arsip-odontogram-lama.pdf"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function page(Request $request, int $record, int $page): StreamedResponse
    {
        $legacyRecord = $this->resolve($request, $record);
        $this->authorize('viewFile', $legacyRecord);
        $this->assertStreamable($legacyRecord);

        $pageRecord = $this->records->findPage($legacyRecord, $page);

        abort_if($pageRecord === null, 404);

        $wantsThumbnail = $request->string('variant')->toString() === 'thumbnail';
        $path = $this->resolvePagePath($pageRecord, $wantsThumbnail);

        $disk = $this->storage->diskFor($pageRecord->image_disk);

        abort_unless($path !== null && $disk->exists($path), 404);

        // Only a real page view is audited. Opening the archive already wrote a
        // RECORD_VIEWED row, and the gallery requests one thumbnail per page —
        // auditing those too would bury the meaningful events.
        if (! $wantsThumbnail) {
            $this->audit->logRecordEvent(LegacyOdontogramAuditEvent::RECORD_PAGE_VIEWED, $legacyRecord, [
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
     * Retract a published archive.
     *
     * The record is resolved through the caller's branch scope FIRST, so an
     * out-of-scope id is a 404 and never a 403 that would confirm it exists.
     * Only then is the policy ability checked, and only then does the service
     * re-assert the transition under a row lock.
     */
    public function void(VoidLegacyOdontogramRecordRequest $request, LegacyOdontogramVoidService $voider, int $record): RedirectResponse
    {
        $this->assertMigrationCapabilityEnabled();

        $legacyRecord = $this->resolve($request, $record);
        $this->authorize('void', $legacyRecord);

        $voided = $voider->void($legacyRecord, $request->reason(), $request->user());

        return redirect()
            ->route('rme.legacy-odontograms.show', $voided->getKey())
            ->with('status', 'Arsip odontogram lama dibatalkan (VOID). Dokumen tetap tersimpan sebagai bukti, tetapi tidak lagi menjadi bagian dari riwayat aktif pasien.');
    }

    private function resolve(Request $request, int $id): LegacyOdontogramRecord
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

    private function resolvePagePath(LegacyOdontogramRecordPage $page, bool $wantsThumbnail): ?string
    {
        if ($wantsThumbnail) {
            return is_string($page->thumbnail_path) && $page->thumbnail_path !== ''
                ? $page->thumbnail_path
                : null;
        }

        return is_string($page->image_path) && $page->image_path !== ''
            ? $page->image_path
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
     */
    private function assertStreamable(LegacyOdontogramRecord $record): void
    {
        abort_unless($record->isPublished(), 404);
    }

    /**
     * For MUTATIONS only. Never call this from a read action: published
     * clinical evidence stays readable while migration is switched off.
     */
    private function assertMigrationCapabilityEnabled(): void
    {
        abort_unless($this->feature->migrationEnabled(), 404);
    }
}
