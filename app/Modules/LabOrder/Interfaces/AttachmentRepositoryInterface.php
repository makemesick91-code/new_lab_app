<?php

namespace App\Modules\LabOrder\Interfaces;

use App\Modules\LabOrder\Models\Attachment;
use Illuminate\Support\Collection;

interface AttachmentRepositoryInterface
{
    public function forEntity(string $entityType, int $entityId): Collection;

    public function findById(int $id): ?Attachment;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Attachment;

    public function softDelete(Attachment $attachment): bool;
}
