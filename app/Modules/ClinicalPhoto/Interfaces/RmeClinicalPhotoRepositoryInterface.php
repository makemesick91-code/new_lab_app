<?php

namespace App\Modules\ClinicalPhoto\Interfaces;

use App\Modules\ClinicalPhoto\Models\RmeClinicalPhoto;
use Illuminate\Support\Collection;

interface RmeClinicalPhotoRepositoryInterface
{
    /** @param  array<string, mixed>  $attributes */
    public function create(array $attributes): RmeClinicalPhoto;

    /**
     * A visit's photos that are NOT voided, oldest first, with the uploader
     * eager-loaded (one query for the photos, one for the uploaders, whatever
     * the count).
     *
     * @return Collection<int, RmeClinicalPhoto>
     */
    public function forVisit(int $visitId): Collection;

    /**
     * A visit's voided photos, in the order they were voided, with the uploader
     * and the voider eager-loaded (rule 242 §9.4).
     *
     * @return Collection<int, RmeClinicalPhoto>
     */
    public function voidedForVisit(int $visitId): Collection;

    /** The photo row locked FOR NO KEY UPDATE (rule 242 §9; the visit row is locked before it). */
    public function lockForVoid(int $id): ?RmeClinicalPhoto;

    /**
     * Write the void columns only (voided_at, voided_by, void_reason); the model
     * refuses any other change.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function markVoided(RmeClinicalPhoto $photo, array $attributes): RmeClinicalPhoto;
}
