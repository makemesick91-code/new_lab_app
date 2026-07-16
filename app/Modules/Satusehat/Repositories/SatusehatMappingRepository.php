<?php

namespace App\Modules\Satusehat\Repositories;

use App\Modules\Satusehat\Interfaces\SatusehatMappingRepositoryInterface;
use App\Modules\Satusehat\Models\SatusehatCodeMapping;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class SatusehatMappingRepository implements SatusehatMappingRepositoryInterface
{
    public function paginate(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        return SatusehatCodeMapping::query()
            ->with(['createdBy:id,name', 'reviewedBy:id,name'])
            ->when($this->str($filters, 'environment'), fn (Builder $q, $v) => $q->where('environment', $v))
            ->when($this->str($filters, 'local_entity_type'), fn (Builder $q, $v) => $q->where('local_entity_type', $v))
            ->when($this->str($filters, 'status'), fn (Builder $q, $v) => $q->where('status', $v))
            ->when($this->str($filters, 'search'), function (Builder $q, $v) {
                $q->where(function (Builder $inner) use ($v) {
                    $inner->where('local_code', 'like', "%{$v}%")
                        ->orWhere('target_code', 'like', "%{$v}%")
                        ->orWhere('target_display', 'like', "%{$v}%");
                });
            })
            ->orderBy('local_entity_type')
            ->orderByDesc('version')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function findActive(
        string $environment,
        string $localEntityType,
        ?int $localEntityId,
        ?string $localCode,
        string $targetResourceType,
    ): ?SatusehatCodeMapping {
        return SatusehatCodeMapping::query()
            ->where('environment', $environment)
            ->where('local_entity_type', $localEntityType)
            ->where('target_resource_type', $targetResourceType)
            ->where('status', SatusehatCodeMapping::STATUS_ACTIVE)
            ->when($localEntityId !== null,
                fn (Builder $q) => $q->where('local_entity_id', $localEntityId),
                fn (Builder $q) => $q->whereNull('local_entity_id'))
            ->when($localCode !== null && $localCode !== '',
                fn (Builder $q) => $q->where('local_code', $localCode),
                fn (Builder $q) => $q->whereNull('local_code'))
            ->orderByDesc('version')
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
