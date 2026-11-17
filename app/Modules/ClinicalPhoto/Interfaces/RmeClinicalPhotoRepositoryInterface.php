<?php

namespace App\Modules\ClinicalPhoto\Interfaces;

use App\Modules\ClinicalPhoto\Models\RmeClinicalPhoto;
use Illuminate\Support\Collection;

interface RmeClinicalPhotoRepositoryInterface
{
    /** @param  array<string, mixed>  $attributes */
    public function create(array $attributes): RmeClinicalPhoto;

    /**
     * A visit's photos, oldest first, with the uploader eager-loaded (one query
     * for the photos, one for the uploaders, whatever the count).
     *
     * @return Collection<int, RmeClinicalPhoto>
     */
    public function forVisit(int $visitId): Collection;
}
