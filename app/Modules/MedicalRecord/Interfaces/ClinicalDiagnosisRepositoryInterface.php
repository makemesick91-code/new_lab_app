<?php

namespace App\Modules\MedicalRecord\Interfaces;

use App\Modules\MedicalRecord\Models\ClinicalDiagnosis;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface ClinicalDiagnosisRepositoryInterface
{
    /**
     * Doctor-facing bounded search over ACTIVE master diagnoses only
     * (synthetic/deprecated entries excluded).
     *
     * @return Collection<int, ClinicalDiagnosis>
     */
    public function search(string $term, int $limit = 20): Collection;

    /**
     * Master governance listing.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters, int $perPage = 20): LengthAwarePaginator;
}
