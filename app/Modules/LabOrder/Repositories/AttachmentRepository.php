<?php

namespace App\Modules\LabOrder\Repositories;

use App\Modules\LabOrder\Interfaces\AttachmentRepositoryInterface;
use App\Modules\LabOrder\Models\Attachment;
use Illuminate\Support\Collection;

class AttachmentRepository implements AttachmentRepositoryInterface
{
    public function forEntity(string $entityType, int $entityId): Collection
    {
        return Attachment::query()
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->with('uploader')
            ->orderByDesc('id')
            ->get();
    }

    public function findById(int $id): ?Attachment
    {
        return Attachment::find($id);
    }

    public function create(array $data): Attachment
    {
        return Attachment::create($data);
    }

    public function softDelete(Attachment $attachment): bool
    {
        return (bool) $attachment->delete();
    }
}
