<?php

namespace App\Modules\PatientMerge\Repositories;

use App\Modules\Patient\Models\Patient;
use App\Modules\PatientMerge\Interfaces\PatientDuplicateCandidateRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class PatientDuplicateCandidateRepository implements PatientDuplicateCandidateRepositoryInterface
{
    /** Indexed block keys. Column names come from here only, never input. */
    private const BLOCK_COLUMNS = ['date_of_birth', 'phone', 'whatsapp_number'];

    /** What a candidate row may read — never address detail or documents. */
    private const COLUMNS = ['id', 'name', 'medical_record_number', 'branch_id', 'date_of_birth', 'gender', 'phone', 'whatsapp_number', 'ktp_number', 'address', 'is_active'];

    public function sharedValues(array $branchIds, string $column, int $limit): array
    {
        $this->assertColumn($column);

        if ($branchIds === [] || $limit < 1) {
            return [];
        }

        return $this->base($branchIds)
            ->whereNotNull($column)
            // An empty-string guard only makes sense for the text columns; a
            // date column cannot hold '' and PostgreSQL rejects the literal.
            ->when($column !== 'date_of_birth', fn (Builder $q) => $q->where($column, '!=', ''))
            ->groupBy($column)
            ->havingRaw('COUNT(*) > 1')
            ->orderBy($column)
            ->limit($limit)
            // toBase(): the RAW stored value, so it can be fed straight back
            // into whereIn() — a cast Carbon re-formatted to Y-m-d would not
            // match SQLite's stored "Y-m-d H:i:s".
            ->toBase()
            ->pluck($column)
            ->map(fn ($value): string => (string) $value)
            ->all();
    }

    public function patientsWithValues(array $branchIds, string $column, array $values, int $limit): Collection
    {
        $this->assertColumn($column);

        if ($branchIds === [] || $values === [] || $limit < 1) {
            return collect();
        }

        return $this->base($branchIds)
            ->with('branch:id,code,name')
            ->select(self::COLUMNS)
            ->whereIn($column, array_values(array_unique($values)))
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    public function registrationCandidates(array $branchIds, ?string $birthDate, array $phones, int $limit): Collection
    {
        $phones = array_values(array_unique(array_filter($phones)));

        if ($branchIds === [] || $limit < 1 || ($birthDate === null && $phones === [])) {
            return collect();
        }

        return $this->base($branchIds)
            ->with('branch:id,code,name')
            ->select(self::COLUMNS)
            ->where(function (Builder $q) use ($birthDate, $phones): void {
                if ($birthDate !== null) {
                    $q->orWhereDate('date_of_birth', $birthDate);
                }

                if ($phones !== []) {
                    $q->orWhereIn('phone', $phones)->orWhereIn('whatsapp_number', $phones);
                }
            })
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /** @return Builder<Patient> */
    private function base(array $branchIds): Builder
    {
        return Patient::query()
            ->whereNull('merged_into_patient_id')
            ->where(function (Builder $q) use ($branchIds): void {
                $q->whereIn('branch_id', $branchIds)->orWhereNull('branch_id');
            });
    }

    private function assertColumn(string $column): void
    {
        if (! in_array($column, self::BLOCK_COLUMNS, true)) {
            throw new InvalidArgumentException("Not a duplicate block column: {$column}");
        }
    }
}
