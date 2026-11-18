<?php

namespace App\Modules\ClinicalPhoto\Repositories;

use App\Modules\ClinicalPhoto\Interfaces\RmeClinicalPhotoRepositoryInterface;
use App\Modules\ClinicalPhoto\Models\RmeClinicalPhoto;
use Illuminate\Support\Collection;

class RmeClinicalPhotoRepository implements RmeClinicalPhotoRepositoryInterface
{
    public function create(array $attributes): RmeClinicalPhoto
    {
        return RmeClinicalPhoto::query()->create($attributes);
    }

    public function forVisit(int $visitId): Collection
    {
        return RmeClinicalPhoto::query()
            ->where('clinic_visit_id', $visitId)
            ->whereNull('voided_at')
            ->with('uploader:id,name')
            ->orderBy('uploaded_at')
            ->orderBy('id')
            ->get();
    }

    public function voidedForVisit(int $visitId): Collection
    {
        return RmeClinicalPhoto::query()
            ->where('clinic_visit_id', $visitId)
            ->whereNotNull('voided_at')
            ->with(['uploader:id,name', 'voider:id,name'])
            ->orderBy('voided_at')
            ->orderBy('id')
            ->get();
    }

    public function lockForVoid(int $id): ?RmeClinicalPhoto
    {
        return RmeClinicalPhoto::query()->whereKey($id)->lock('for no key update')->first();
    }

    public function markVoided(RmeClinicalPhoto $photo, array $attributes): RmeClinicalPhoto
    {
        $photo->forceFill(array_intersect_key($attributes, array_flip(RmeClinicalPhoto::VOID_COLUMNS)))->save();

        return $photo;
    }
}
