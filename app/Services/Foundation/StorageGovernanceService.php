<?php

namespace App\Services\Foundation;

use App\Support\Storage\ObjectStorageReadinessService;

/**
 * STORAGE-1 — read-only object storage governance rule catalog.
 *
 * Publishes the STORAGE-R001..R005 rules into the foundation governance
 * summary and reports the current readiness signal. This does not gate
 * release decisions on its own; it is informational, matching the
 * "OFF by default" foundation posture of STORAGE-1.
 */
class StorageGovernanceService
{
    /**
     * @return list<array{id: string, title: string, description: string}>
     */
    public static function rules(): array
    {
        return [
            [
                'id' => 'STORAGE-R001',
                'title' => 'Upload through storage abstraction only',
                'description' => 'New file upload/storage features must go through a storage abstraction/service, not scattered hardcoded Storage::disk() calls in controllers.',
            ],
            [
                'id' => 'STORAGE-R002',
                'title' => 'Private by default',
                'description' => 'Object storage must be private by default; a public URL is only exposed via a signed/controlled route, never a raw public disk URL.',
            ],
            [
                'id' => 'STORAGE-R003',
                'title' => 'Non-destructive healthcheck required',
                'description' => 'A storage healthcheck command must exist and must be non-destructive (write/read/delete a small object only, never touching production files).',
            ],
            [
                'id' => 'STORAGE-R004',
                'title' => 'No secret leakage',
                'description' => 'Object storage secrets must never be logged, never stored in the database, and never appear as real values in docs or the environment example file.',
            ],
            [
                'id' => 'STORAGE-R005',
                'title' => 'No destructive migration without a separate plan',
                'description' => 'Production/storage migrations must not delete or move existing files without an explicit, separate migration plan.',
            ],
            [
                'id' => 'STORAGE-R006',
                'title' => 'Clinical evidence never on a publicly served disk',
                'description' => 'Patient-linked clinical evidence (RME handwriting, prescription and doctor-signature canvases, patient documents, lab and delivery attachments) must be written only to a private disk that the web server does not serve. It must never be placed on a disk that is symlinked into the document root, and it must be read back solely through an authenticated route whose policy enforces role, branch and patient scope. Introduced by STORAGE-PUBLIC-CLINICAL-EVIDENCE-1 after clinical images were found retrievable without any authentication.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        $readiness = app(ObjectStorageReadinessService::class)->check(false);

        $decision = match (true) {
            $readiness['status'] === 'misconfigured' => 'WATCH',
            default => 'GO',
        };

        return [
            'decision' => $decision,
            'rules' => self::rules(),
            'object_storage_enabled' => $readiness['enabled'],
            'disk' => $readiness['disk'],
            'readiness_status' => $readiness['status'],
        ];
    }
}
