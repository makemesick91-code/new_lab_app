<?php

namespace App\Modules\Satusehat\Interfaces;

use App\Modules\Satusehat\Models\SatusehatEntityIdentifier;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface SatusehatIdentifierRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters, int $perPage = 20): LengthAwarePaginator;

    /**
     * The single ACTIVE identifier for a local entity in an environment, or null.
     */
    public function findActive(
        string $environment,
        string $entityType,
        string $localEntityType,
        int $localEntityId,
    ): ?SatusehatEntityIdentifier;
}
