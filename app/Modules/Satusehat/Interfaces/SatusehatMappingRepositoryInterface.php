<?php

namespace App\Modules\Satusehat\Interfaces;

use App\Modules\Satusehat\Models\SatusehatCodeMapping;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface SatusehatMappingRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters, int $perPage = 20): LengthAwarePaginator;

    /**
     * The single ACTIVE mapping for a logical key, or null.
     */
    public function findActive(
        string $environment,
        string $localEntityType,
        ?int $localEntityId,
        ?string $localCode,
        string $targetResourceType,
    ): ?SatusehatCodeMapping;
}
