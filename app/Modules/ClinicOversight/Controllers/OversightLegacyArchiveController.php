<?php

namespace App\Modules\ClinicOversight\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ClinicOversight\Services\OversightAuditService;
use App\Modules\ClinicOversight\Services\OversightClinicalRecordService;
use App\Modules\ClinicOversight\Support\ClinicOversightScope;
use App\Modules\LegacyOdontogram\Interfaces\LegacyOdontogramRecordRepositoryInterface;
use App\Modules\LegacyOdontogram\Models\LegacyOdontogramRecord;
use App\Modules\LegacyOdontogram\Services\LegacyOdontogramStorageService;
use App\Modules\LegacyRme\Interfaces\LegacyRmeRecordRepositoryInterface;
use App\Modules\LegacyRme\Models\LegacyRmeRecord;
use App\Modules\LegacyRme\Services\LegacyRmeStorageService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * FEATURE-FINANCE-KLINIK-DATA-ANALYST-ROLES-1 — read-only access to PUBLISHED
 * legacy RME and legacy odontogram archives for the oversight roles.
 *
 * WHY A SEPARATE CONTROLLER. The canonical legacy viewers authorize through
 * LegacyRme/LegacyOdontogram WorkspaceScope, which only widens to every RME
 * branch for holders of a GOVERNANCE permission (review/publish/void) — and
 * rules 173/174 forbid putting a read permission there. So the oversight
 * module resolves the record through the SAME branch-scoped repository
 * reader, but with its own explicit branch set, and streams through the SAME
 * private-disk storage services. No governance permission is involved, and
 * nothing here can void, publish, review or create an archive.
 *
 * Only a PUBLISHED archive renders or streams. That rule is asserted here and
 * not only in the gate, because Super Admin passes every gate.
 */
class OversightLegacyArchiveController extends Controller
{
    use AuthorizesRequests;

    /** Same bound as the canonical legacy export: bounded memory per PDF. */
    public const MAX_EXPORT_PAGES = 30;

    public function __construct(
        private readonly OversightClinicalRecordService $records,
        private readonly LegacyRmeRecordRepositoryInterface $legacyRme,
        private readonly LegacyOdontogramRecordRepositoryInterface $legacyOdontogram,
        private readonly LegacyRmeStorageService $rmeStorage,
        private readonly LegacyOdontogramStorageService $odontogramStorage,
        private readonly OversightAuditService $audit,
    ) {}

    // ------------------------------------------------------------------
    // Legacy RME
    // ------------------------------------------------------------------

    public function rmeShow(Request $request, int $record): View
    {
        $archive = $this->resolveRme($request, $record);

        $this->auditLegacy(OversightAuditService::LEGACY_RME_VIEWED, 'legacy_rme', $archive->id, (int) $archive->patient_id, $request);

        return view('oversight.legacy.rme-show', [
            'record' => $archive->load(['patient', 'originBranch', 'publishedBy']),
            'pages' => $this->legacyRme->pagesFor($archive),
            'canExport' => $request->user()->can('clinic-oversight.export', ClinicOversightScope::PERMISSION_CLINICAL),
        ]);
    }

    public function rmeSource(Request $request, int $record): StreamedResponse
    {
        $archive = $this->resolveRme($request, $record);
        $this->authorize('clinic-oversight.export', ClinicOversightScope::PERMISSION_CLINICAL);

        $path = (string) $archive->source_pdf_path;
        $disk = $this->rmeStorage->diskFor($archive->source_disk);
        abort_unless($path !== '' && $disk->exists($path), 404);

        $this->auditLegacy(OversightAuditService::LEGACY_RME_FILE_STREAMED, 'legacy_rme', $archive->id, (int) $archive->patient_id, $request, ['variant' => 'source_pdf']);

        return $disk->response($path, 'arsip-rme-lama.pdf', $this->pdfHeaders('arsip-rme-lama.pdf'));
    }

    public function rmePage(Request $request, int $record, int $page): StreamedResponse
    {
        $archive = $this->resolveRme($request, $record);

        $pageRecord = $this->legacyRme->findPage($archive, $page);
        abort_if($pageRecord === null, 404);

        $thumbnail = $request->string('variant')->toString() === 'thumbnail';
        $path = $thumbnail ? $pageRecord->thumbnail_path : $pageRecord->background_path;
        $disk = $this->rmeStorage->diskFor($pageRecord->background_disk);
        abort_unless(is_string($path) && $path !== '' && $disk->exists($path), 404);

        if (! $thumbnail) {
            $this->auditLegacy(OversightAuditService::LEGACY_RME_FILE_STREAMED, 'legacy_rme', $archive->id, (int) $archive->patient_id, $request, ['page_number' => $page, 'variant' => 'page']);
        }

        return $disk->response($path, sprintf('halaman-%04d.png', $page), $this->imageHeaders($page));
    }

    public function rmePrint(Request $request, int $record): View
    {
        $archive = $this->resolveRme($request, $record);
        $this->authorize('clinic-oversight.export', ClinicOversightScope::PERMISSION_CLINICAL);

        $this->auditLegacy(OversightAuditService::LEGACY_RME_PRINTED, 'legacy_rme', $archive->id, (int) $archive->patient_id, $request, ['format' => 'print']);

        return view('oversight.legacy.print', [
            'kind' => 'rme',
            'title' => 'ARSIP RME LAMA',
            'record' => $archive->load(['patient', 'originBranch']),
            'documentDate' => $archive->rme_date,
            'branchName' => $archive->originBranch?->name,
            'pages' => $this->legacyRme->pagesFor($archive),
            'pageRoute' => 'oversight.legacy.rme.pages.show',
            'backUrl' => route('oversight.legacy.rme.show', $archive->id),
        ]);
    }

    public function rmePdf(Request $request, int $record): Response
    {
        $archive = $this->resolveRme($request, $record);
        $this->authorize('clinic-oversight.export', ClinicOversightScope::PERMISSION_CLINICAL);

        $pages = $this->legacyRme->pagesFor($archive);
        $embedded = $this->embed($pages, fn ($p) => [$p->background_disk, $p->background_path], $this->rmeStorage);

        $this->auditLegacy(OversightAuditService::LEGACY_RME_PDF, 'legacy_rme', $archive->id, (int) $archive->patient_id, $request, [
            'format' => 'pdf',
            'page_count' => count($embedded),
            'truncated' => $pages->count() > count($embedded),
        ]);

        // The canonical legacy export template: it embeds the pages itself and
        // references no route, so it renders identically for this reader.
        return Pdf::loadView('rme.legacy-records.export-pdf', [
            'record' => $archive->load(['patient:id,name,medical_record_number', 'originBranch:id,name,code', 'publishedBy:id,name']),
            'pages' => $embedded,
            'truncated' => $pages->count() > count($embedded),
            'totalPages' => $pages->count(),
        ])->download('arsip-rme-lama-'.$archive->id.'.pdf');
    }

    // ------------------------------------------------------------------
    // Legacy odontogram
    // ------------------------------------------------------------------

    public function odontogramShow(Request $request, int $record): View
    {
        $archive = $this->resolveOdontogram($request, $record);

        $this->auditLegacy(OversightAuditService::LEGACY_ODONTOGRAM_VIEWED, 'legacy_odontogram', $archive->id, (int) $archive->patient_id, $request);

        return view('oversight.legacy.odontogram-show', [
            'record' => $archive->load(['patient', 'branch', 'publishedBy']),
            'pages' => $this->legacyOdontogram->pagesFor($archive),
            'canExport' => $request->user()->can('clinic-oversight.export', ClinicOversightScope::PERMISSION_CLINICAL),
        ]);
    }

    public function odontogramSource(Request $request, int $record): StreamedResponse
    {
        $archive = $this->resolveOdontogram($request, $record);
        $this->authorize('clinic-oversight.export', ClinicOversightScope::PERMISSION_CLINICAL);

        $path = (string) $archive->source_pdf_path;
        $disk = $this->odontogramStorage->diskFor($archive->source_disk);
        abort_unless($path !== '' && $disk->exists($path), 404);

        $this->auditLegacy(OversightAuditService::LEGACY_ODONTOGRAM_FILE_STREAMED, 'legacy_odontogram', $archive->id, (int) $archive->patient_id, $request, ['variant' => 'source_pdf']);

        return $disk->response($path, 'arsip-odontogram-lama.pdf', $this->pdfHeaders('arsip-odontogram-lama.pdf'));
    }

    public function odontogramPage(Request $request, int $record, int $page): StreamedResponse
    {
        $archive = $this->resolveOdontogram($request, $record);

        $pageRecord = $this->legacyOdontogram->findPage($archive, $page);
        abort_if($pageRecord === null, 404);

        $thumbnail = $request->string('variant')->toString() === 'thumbnail';
        $path = $thumbnail ? $pageRecord->thumbnail_path : $pageRecord->image_path;
        $disk = $this->odontogramStorage->diskFor($pageRecord->image_disk);
        abort_unless(is_string($path) && $path !== '' && $disk->exists($path), 404);

        if (! $thumbnail) {
            $this->auditLegacy(OversightAuditService::LEGACY_ODONTOGRAM_FILE_STREAMED, 'legacy_odontogram', $archive->id, (int) $archive->patient_id, $request, ['page_number' => $page, 'variant' => 'page']);
        }

        return $disk->response($path, sprintf('halaman-%04d.png', $page), $this->imageHeaders($page));
    }

    public function odontogramPrint(Request $request, int $record): View
    {
        $archive = $this->resolveOdontogram($request, $record);
        $this->authorize('clinic-oversight.export', ClinicOversightScope::PERMISSION_CLINICAL);

        $this->auditLegacy(OversightAuditService::LEGACY_ODONTOGRAM_PRINTED, 'legacy_odontogram', $archive->id, (int) $archive->patient_id, $request, ['format' => 'print']);

        return view('oversight.legacy.print', [
            'kind' => 'odontogram',
            'title' => 'ARSIP ODONTOGRAM LAMA',
            'record' => $archive->load(['patient', 'branch']),
            'documentDate' => $archive->odontogram_date,
            'branchName' => $archive->branch?->name,
            'pages' => $this->legacyOdontogram->pagesFor($archive),
            'pageRoute' => 'oversight.legacy.odontogram.pages.show',
            'backUrl' => route('oversight.legacy.odontogram.show', $archive->id),
        ]);
    }

    // ------------------------------------------------------------------

    private function resolveRme(Request $request, int $id): LegacyRmeRecord
    {
        $this->authorize('clinic-oversight.view-clinical-records');

        $record = $this->legacyRme->findByIdInBranches($this->records->branchIdsFor($request->user()), $id, false);

        // Out of scope or not PUBLISHED: one 404, so neither existence nor a
        // VOID status is confirmed. Re-asserted here, not only in the gate.
        abort_if($record === null || ! $record->isPublished(), 404);
        $this->authorize('clinic-oversight.view-legacy-rme', $record);

        return $record;
    }

    private function resolveOdontogram(Request $request, int $id): LegacyOdontogramRecord
    {
        $this->authorize('clinic-oversight.view-clinical-records');

        $record = $this->legacyOdontogram->findByIdInBranches($this->records->branchIdsFor($request->user()), $id, false);

        abort_if($record === null || ! $record->isPublished(), 404);
        $this->authorize('clinic-oversight.view-legacy-odontogram', $record);

        return $record;
    }

    /**
     * @param  callable(mixed): array{0: ?string, 1: ?string}  $locate
     * @return list<array{page_number: int, data_uri: string}>
     */
    private function embed(Collection $pages, callable $locate, LegacyRmeStorageService $storage): array
    {
        $embedded = [];

        foreach ($pages as $page) {
            if (count($embedded) >= self::MAX_EXPORT_PAGES) {
                break;
            }

            [$diskName, $path] = $locate($page);

            if (! is_string($path) || $path === '') {
                continue;
            }

            $disk = $storage->diskFor($diskName);

            if (! $disk->exists($path)) {
                continue;
            }

            $embedded[] = [
                'page_number' => (int) $page->page_number,
                'data_uri' => 'data:image/png;base64,'.base64_encode((string) $disk->get($path)),
            ];
        }

        return $embedded;
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function auditLegacy(string $action, string $type, int $recordId, int $patientId, Request $request, array $extra = []): void
    {
        $this->audit->log($action, $recordId, [
            'surface' => 'legacy_archive',
            'record_type' => $type,
            'record_id' => $recordId,
            'patient_id' => $patientId,
            ...$extra,
        ], $request->user());
    }

    /**
     * @return array<string, string>
     */
    private function pdfHeaders(string $name): array
    {
        return [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$name.'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function imageHeaders(int $page): array
    {
        return [
            'Content-Type' => 'image/png',
            'Content-Disposition' => sprintf('inline; filename="halaman-%04d.png"', $page),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ];
    }
}
