<?php

declare(strict_types=1);

namespace App\Modules\Patient\Repositories;

use App\Modules\Patient\Interfaces\PatientRmPaperCardRegistrationRepositoryInterface;
use App\Modules\Patient\Models\PatientRmPaperCardRegistration;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use LogicException;

class PatientRmPaperCardRegistrationRepository implements PatientRmPaperCardRegistrationRepositoryInterface
{
    public function record(array $attributes): PatientRmPaperCardRegistration
    {
        if (PatientRmPaperCardRegistration::query()->getConnection()->transactionLevel() < 1) {
            throw new LogicException('A paper-card registration is recorded only inside the claim transaction.');
        }

        return PatientRmPaperCardRegistration::query()->create($attributes);
    }

    public function forPatient(int $patientId, array $branchIds): Collection
    {
        if ($branchIds === []) {
            return new Collection;
        }

        return PatientRmPaperCardRegistration::query()
            ->with(['actor:id,name', 'branch:id,code,name'])
            ->where('patient_id', $patientId)
            ->whereIn('branch_id', array_values($branchIds))
            ->orderBy('id')
            ->get();
    }

    public function paginateForBranches(array $branchIds, int $perPage): LengthAwarePaginator
    {
        return PatientRmPaperCardRegistration::query()
            ->select(['id', 'patient_id', 'branch_id', 'year', 'reason_code', 'actor_user_id', 'created_at'])
            ->with(['patient:id,name,medical_record_number', 'actor:id,name', 'branch:id,code,name'])
            ->whereIn('branch_id', $branchIds === [] ? [0] : array_values($branchIds))
            ->orderByDesc('id')
            ->paginate($perPage);
    }
}
