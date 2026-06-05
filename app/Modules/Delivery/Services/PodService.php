<?php

namespace App\Modules\Delivery\Services;

use App\Models\User;
use App\Modules\Delivery\Interfaces\DeliveryRepositoryInterface;
use App\Modules\Delivery\Models\Delivery;
use App\Modules\LabOrder\Interfaces\AttachmentRepositoryInterface;
use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\LabOrder\Services\AuditLogService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PodService
{
    public const CATEGORY_DELIVERY_PHOTO = 'DELIVERY_PHOTO';

    public const CATEGORY_POD_SIGNATURE = 'POD_SIGNATURE';

    public const CATEGORY_POD_RECEIVER_PHOTO = 'POD_RECEIVER_PHOTO';

    public const CATEGORY_DELIVERY_EVIDENCE = 'DELIVERY_EVIDENCE';

    public const CATEGORIES = [
        self::CATEGORY_DELIVERY_PHOTO,
        self::CATEGORY_POD_SIGNATURE,
        self::CATEGORY_POD_RECEIVER_PHOTO,
        self::CATEGORY_DELIVERY_EVIDENCE,
    ];

    public function __construct(
        private readonly DeliveryRepositoryInterface $deliveries,
        private readonly AttachmentRepositoryInterface $attachments,
        private readonly AuditLogService $auditLogs,
    ) {}

    public function uploadPod(
        Delivery $delivery,
        string $receiverName,
        UploadedFile $signature,
        UploadedFile $receiverPhoto,
        mixed $receivedAt,
        ?string $notes = null,
        ?User $actor = null,
    ): Delivery {
        $actor = $actor ?? auth()->user();

        return DB::transaction(function () use ($delivery, $receiverName, $signature, $receiverPhoto, $receivedAt, $notes, $actor) {
            $signatureAttachment = $this->storeAttachment($delivery, $signature, self::CATEGORY_POD_SIGNATURE, $actor);
            $photoAttachment = $this->storeAttachment($delivery, $receiverPhoto, self::CATEGORY_POD_RECEIVER_PHOTO, $actor);

            $updated = $this->deliveries->update($delivery, [
                'receiver_name' => $receiverName,
                'receiver_signature_path' => $signatureAttachment->file_path,
                'receiver_photo_path' => $photoAttachment->file_path,
                'received_at' => $receivedAt,
                'delivery_notes' => $notes ?? $delivery->delivery_notes,
            ]);

            $this->auditLogs->log(
                Delivery::ENTITY_TYPE,
                $delivery->id,
                AuditLog::ACTION_UPLOAD_POD,
                null,
                [
                    'receiver_name' => $receiverName,
                    'signature_attachment_id' => $signatureAttachment->id,
                    'receiver_photo_attachment_id' => $photoAttachment->id,
                    'received_at' => $receivedAt,
                ],
                $actor,
            );

            return $updated->refresh();
        });
    }

    public function storeEvidence(Delivery $delivery, UploadedFile $file, string $category, ?User $actor = null): string
    {
        $actor = $actor ?? auth()->user();

        return DB::transaction(function () use ($delivery, $file, $category, $actor) {
            $attachment = $this->storeAttachment($delivery, $file, $category, $actor);

            $this->auditLogs->log(
                Delivery::ENTITY_TYPE,
                $delivery->id,
                AuditLog::ACTION_UPLOAD_POD,
                null,
                ['attachment_id' => $attachment->id, 'category' => $category],
                $actor,
            );

            return $attachment->file_path;
        });
    }

    public function assertComplete(Delivery $delivery): void
    {
        if (! $delivery->hasCompletePod()) {
            throw ValidationException::withMessages([
                'pod' => 'POD wajib lengkap sebelum pengiriman dapat diselesaikan.',
            ]);
        }
    }

    private function storeAttachment(Delivery $delivery, UploadedFile $file, string $category, ?User $actor)
    {
        $extension = $file->getClientOriginalExtension() ?: $file->guessExtension();
        $storedName = Str::uuid()->toString().($extension ? '.'.$extension : '');
        $directory = 'deliveries/'.$delivery->delivery_number;
        $path = $file->storeAs($directory, $storedName, 'public');

        return $this->attachments->create([
            'entity_type' => Delivery::ENTITY_TYPE,
            'entity_id' => $delivery->id,
            'category' => $category,
            'file_name' => $file->getClientOriginalName(),
            'file_path' => $path,
            'mime_type' => $file->getClientMimeType(),
            'file_size' => $file->getSize(),
            'uploaded_by' => $actor?->id,
            'uploaded_at' => now(),
        ]);
    }
}
