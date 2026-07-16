<?php

namespace App\Modules\MedicalRecord\Repositories;

use App\Modules\MedicalRecord\Interfaces\ClinicalDiagnosisRepositoryInterface;
use App\Modules\MedicalRecord\Models\ClinicalDiagnosis;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class ClinicalDiagnosisRepository implements ClinicalDiagnosisRepositoryInterface
{
    public function search(string $term, int $limit = 20): Collection
    {
        $normalized = mb_strtolower(trim($term));
        if ($normalized === '') {
            return collect();
        }

        return ClinicalDiagnosis::query()
            ->where('status', ClinicalDiagnosis::STATUS_ACTIVE)
            ->where('normalized_search', 'like', "%{$normalized}%")
            ->orderBy('code')
            ->limit(min($limit, 50))
            ->get(['id', 'code_system', 'code', 'display']);
    }

    public function paginate(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $search = is_string($filters['search'] ?? null) ? mb_strtolower(trim($filters['search'])) : '';
        $status = is_string($filters['status'] ?? null) ? trim($filters['status']) : '';

        return ClinicalDiagnosis::query()
            ->when($search !== '', fn ($q) => $q->where('normalized_search', 'like', "%{$search}%"))
            ->when($status !== '', fn ($q) => $q->where('status', $status))
            ->orderBy('code_system')
            ->orderBy('code')
            ->paginate($perPage)
            ->withQueryString();
    }
}
