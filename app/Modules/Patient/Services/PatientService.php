<?php

namespace App\Modules\Patient\Services;

use App\Modules\Branch\Interfaces\BranchRepositoryInterface;
use App\Modules\Patient\Interfaces\PatientRepositoryInterface;
use App\Modules\Patient\Models\Patient;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PatientService
{
    public function __construct(
        private readonly PatientRepositoryInterface $patients,
        private readonly PatientCodeGenerator $codeGenerator,
        private readonly PatientMedicalRecordNumberService $rmNumbers,
        private readonly BranchRepositoryInterface $branches,
    ) {}

    public function list(array $filters = [], int $perPage = 10): LengthAwarePaginator
    {
        return $this->patients->paginate($filters, $perPage);
    }

    public function listAll(): Collection
    {
        return $this->patients->listAll();
    }

    public function find(int $id): ?Patient
    {
        return $this->patients->findById($id);
    }

    /**
     * Read-only preview of legacy patients without a Cabang RME (branch_id null).
     * Never mutates data — Sprint 23 Phase 23.10 explicitly defers any RM/branch
     * backfill to a future controlled migration phase.
     *
     * @return Collection<int, Patient>
     */
    public function legacyWithoutBranch(): Collection
    {
        return $this->patients->legacyWithoutBranch();
    }

    public function create(array $data): Patient
    {
        return DB::transaction(function () use ($data) {
            $data = $this->applyMedicalRecordNumber($data);

            return $this->patients->create($data);
        });
    }

    public function update(Patient $patient, array $data): Patient
    {
        return DB::transaction(function () use ($patient, $data) {
            $data = $this->applyMedicalRecordNumber($data, $patient);

            return $this->patients->update($patient, $data);
        });
    }

    /**
     * Resolve the patient's medical_record_number from the supplied data.
     *
     * Precedence:
     *   1. An explicit, non-empty medical_record_number is always respected
     *      (legacy patients and manual overrides keep working unchanged).
     *   2. When a branch + manual RM number are supplied, compose the finalized
     *      format DG-{KODE_CABANG}-{TAHUN_DAFTAR}-{NOMOR_RM_MANUAL}. The
     *      registration year comes from registered_at (defaulting to today).
     *   3. Otherwise fall back to the legacy auto-sequence generator (config).
     *
     * Existing patients are never rewritten unless their identity components
     * are explicitly changed on update.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function applyMedicalRecordNumber(array $data, ?Patient $patient = null): array
    {
        $explicitCode = trim((string) ($data['medical_record_number'] ?? ''));

        if ($explicitCode !== '') {
            $data['medical_record_number'] = $explicitCode;

            return $data;
        }

        $branchId = $data['branch_id'] ?? $patient?->branch_id;
        $manualRmNumber = trim((string) ($data['manual_rm_number'] ?? $patient?->manual_rm_number ?? ''));

        if ($branchId && $manualRmNumber !== '') {
            $registeredAt = $this->resolveRegisteredAt($data, $patient);
            $data['registered_at'] = $registeredAt->toDateString();
            $data['manual_rm_number'] = $manualRmNumber;

            $branch = $this->branches->findById((int) $branchId);

            if (! $branch) {
                throw new RuntimeException('Cabang RME yang dipilih tidak ditemukan.');
            }

            $data['medical_record_number'] = $this->rmNumbers->composeForRegistration(
                $branch->code,
                $registeredAt,
                $manualRmNumber,
            );

            return $data;
        }

        // No explicit code and no branch/manual components: keep legacy behavior
        // for backward compatibility (auto-sequence pilot codes) on create only.
        if ($patient === null && config('patient.code.auto_generate', true)) {
            $data['medical_record_number'] = $this->codeGenerator->generate();
        }

        return $data;
    }

    private function resolveRegisteredAt(array $data, ?Patient $patient): Carbon
    {
        $value = $data['registered_at'] ?? $patient?->registered_at;

        return $value ? Carbon::parse($value) : Carbon::today();
    }

    public function delete(Patient $patient): bool
    {
        return DB::transaction(fn () => $this->patients->delete($patient));
    }

    public function activate(Patient $patient): Patient
    {
        return $this->patients->setActiveStatus($patient, true);
    }

    public function deactivate(Patient $patient): Patient
    {
        return $this->patients->setActiveStatus($patient, false);
    }
}
