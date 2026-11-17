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
            ->with('uploader:id,name')
            ->orderBy('uploaded_at')
            ->orderBy('id')
            ->get();
    }
}
