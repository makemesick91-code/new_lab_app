<?php

namespace App\Modules\Satusehat\Repositories;

use App\Modules\Satusehat\Interfaces\SatusehatIdentifierRepositoryInterface;
use App\Modules\Satusehat\Models\SatusehatEntityIdentifier;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class SatusehatIdentifierRepository implements SatusehatIdentifierRepositoryInterface
{
    public function paginate(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        return SatusehatEntityIdentifier::query()
            ->with(['createdBy:id,name', 'verifiedBy:id,name'])
            ->when($this->str($filters, 'environment'), fn (Builder $q, $v) => $q->where('environment', $v))
            ->when($this->str($filters, 'entity_type'), fn (Builder $q, $v) => $q->where('entity_type', $v))
            ->when($this->str($filters, 'status'), fn (Builder $q, $v) => $q->where('status', $v))
            ->orderBy('entity_type')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function findActive(
        string $environment,
        string $entityType,
        string $localEntityType,
        int $localEntityId,
    ): ?SatusehatEntityIdentifier {
        return SatusehatEntityIdentifier::query()
            ->where('environment', $environment)
            ->where('entity_type', $entityType)
            ->where('local_entity_type', $localEntityType)
            ->where('local_entity_id', $localEntityId)
            ->where('status', SatusehatEntityIdentifier::STATUS_ACTIVE)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function str(array $filters, string $key): ?string
    {
        $value = $filters[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
