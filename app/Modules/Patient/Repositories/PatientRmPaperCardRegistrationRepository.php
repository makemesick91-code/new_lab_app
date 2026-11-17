<?php

declare(strict_types=1);

namespace App\Modules\Patient\Repositories;

use App\Modules\Patient\Interfaces\PatientRmPaperCardRegistrationRepositoryInterface;
use App\Modules\Patient\Models\Patient;
use App\Modules\Patient\Models\PatientRmPaperCardRegistration;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use LogicException;
use RuntimeException;

class PatientRmPaperCardRegistrationRepository implements PatientRmPaperCardRegistrationRepositoryInterface
{
    public function record(#[\SensitiveParameter] array $attributes): PatientRmPaperCardRegistration
    {
        if (PatientRmPaperCardRegistration::query()->getConnection()->transactionLevel() < 1) {
            throw new LogicException('A paper-card registration is recorded only inside the claim transaction.');
        }

        try {
            return PatientRmPaperCardRegistration::query()->create($attributes);
        } catch (QueryException $e) {
            // PR-3b review B1 — the driver error carries the SQL bindings, the
            // note among them, and a reported exception lands in the log. It is
            // replaced by one with no value and no previous exception; the
            // caller's transaction rolls the registration back.
            // Re-review F2: only the five-character SQLSTATE, which carries no value
            // (errorInfo[2] would: its DETAIL echoes the row).
            $state = is_array($e->errorInfo ?? null) && is_string($e->errorInfo[0] ?? null) && preg_match('/^[0-9A-Z]{5}$/', $e->errorInfo[0]) === 1
                ? $e->errorInfo[0]
                : 'unknown';

            throw new RuntimeException('The paper-card registration could not be stored (SQLSTATE '.$state.').');
        }
    }

    public function forPatient(int $patientId, array $branchIds): Collection
    {
        if ($branchIds === []) {
            return new Collection;
        }

        // PR-3b review B4 — the patient's own rows and, read only, those of
        // patients merged INTO it (the rows themselves stay on the source).
        return PatientRmPaperCardRegistration::query()
            ->with(['actor:id,name', 'branch:id,code,name', 'patient:id,name,medical_record_number'])
            ->where(function ($query) use ($patientId): void {
                $query->where('patient_id', $patientId)
                    ->orWhereIn('patient_id', Patient::withTrashed()->select('id')->where('merged_into_patient_id', $patientId));
            })
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
