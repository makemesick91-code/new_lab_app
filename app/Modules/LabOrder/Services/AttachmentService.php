<?php

namespace App\Modules\LabOrder\Services;

use App\Models\User;
use App\Modules\LabOrder\Interfaces\AttachmentRepositoryInterface;
use App\Modules\LabOrder\Models\Attachment;
use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\LabOrder\Models\LabOrder;
use App\Support\Storage\ClinicalEvidenceStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Stores Lab Order attachments on the public disk and records metadata only.
 * Never stores file content in the database (PROJECT_RULES §16).
 */
class AttachmentService
{
    public function __construct(
        private readonly AttachmentRepositoryInterface $attachments,
        private readonly AuditLogService $auditLogs,
    ) {}

    public function upload(
        LabOrder $order,
        UploadedFile $file,
        string $category,
        ?User $actor = null,
        string $auditAction = AuditLog::ACTION_UPLOAD_ATTACHMENT,
    ): Attachment {
        $actor = $actor ?? auth()->user();

        return DB::transaction(function () use ($order, $file, $category, $actor, $auditAction) {
            $extension = $file->getClientOriginalExtension() ?: $file->guessExtension();
            $storedName = Str::uuid()->toString().($extension ? '.'.$extension : '');
            $directory = 'lab-orders/'.$order->order_number;

            $path = $file->storeAs($directory, $storedName, ClinicalEvidenceStorage::diskName());
            // STORAGE-PUBLIC-CLINICAL-EVIDENCE-1 — these attachments are
            // patient-linked evidence and must not land on the 'public' disk,
            // which the web server serves without authentication.

            $attachment = $this->attachments->create([
                'entity_type' => LabOrder::ENTITY_TYPE,
                'entity_id' => $order->id,
                'category' => $category,
                'file_name' => $file->getClientOriginalName(),
                'file_path' => $path,
                'mime_type' => $file->getClientMimeType(),
                'file_size' => $file->getSize(),
                'uploaded_by' => $actor?->id,
                'uploaded_at' => now(),
            ]);

            $this->auditLogs->log(
                LabOrder::ENTITY_TYPE,
                $order->id,
                $auditAction,
                null,
                ['attachment_id' => $attachment->id, 'category' => $category, 'file_name' => $attachment->file_name],
                $actor,
            );

            return $attachment;
        });
    }

    public function delete(Attachment $attachment, ?User $actor = null): bool
    {
        $actor = $actor ?? auth()->user();

        return DB::transaction(function () use ($attachment, $actor) {
            $result = $this->attachments->softDelete($attachment);

            $this->auditLogs->log(
                $attachment->entity_type,
                (int) $attachment->entity_id,
                AuditLog::ACTION_DELETE_ATTACHMENT,
                ['attachment_id' => $attachment->id, 'file_name' => $attachment->file_name],
                null,
                $actor,
            );

            return $result;
        });
    }
}
