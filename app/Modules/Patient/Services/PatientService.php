<?php

namespace App\Modules\Patient\Services;

use App\Modules\Patient\Interfaces\PatientRepositoryInterface;
use App\Modules\Patient\Models\Patient;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PatientService
{
    public function __construct(
        private readonly PatientRepositoryInterface $patients,
        private readonly PatientCodeGenerator $codeGenerator,
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

    public function create(array $data): Patient
    {
        return DB::transaction(function () use ($data) {
            $code = trim((string) ($data['medical_record_number'] ?? ''));

            if ($code === '' && config('patient.code.auto_generate', true)) {
                $data['medical_record_number'] = $this->codeGenerator->generate();
            }

            return $this->patients->create($data);
        });
    }

    public function update(Patient $patient, array $data): Patient
    {
        return DB::transaction(fn () => $this->patients->update($patient, $data));
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
