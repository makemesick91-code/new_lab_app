<?php

declare(strict_types=1);

namespace App\Modules\Patient\Repositories;

use App\Modules\Patient\Interfaces\PatientRmSequenceRepositoryInterface;
use App\Modules\Patient\Models\Patient;
use App\Modules\Patient\Models\PatientRmSequence;
use App\Modules\Patient\Services\PatientMedicalRecordNumberService;
use Illuminate\Support\LazyCollection;
use LogicException;

class PatientRmSequenceRepository implements PatientRmSequenceRepositoryInterface
{
    public function lockOrCreate(int $branchId, int $year, callable $seed): PatientRmSequence
    {
        $existing = $this->lockedRow($branchId, $year);

        if ($existing !== null) {
            return $existing;
        }

        $now = now();

        // ON CONFLICT DO NOTHING: when a concurrent registration inserted the row
        // first, this waits for it to commit and then does nothing, and the lock
        // below serialises the two.
        PatientRmSequence::query()->insertOrIgnore([
            'branch_id' => $branchId,
            'year' => $year,
            'last_number' => max(0, (int) $seed()),
            'seed_source' => PatientRmSequence::SOURCE_LAZY,
            'seeded_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->lockedRow($branchId, $year)
            ?? throw new LogicException('The Nomor RM counter row could not be created.');
    }

    public function advanceTo(PatientRmSequence $sequence, int $lastNumber): void
    {
        if ($lastNumber <= (int) $sequence->last_number) {
            return;
        }

        $sequence->forceFill(['last_number' => $lastNumber])->save();
    }

    public function raiseTo(int $branchId, int $year, int $value): string
    {
        $value = max(0, $value);
        $created = false;

        $row = $this->lockedRow($branchId, $year);

        if ($row === null) {
            $now = now();
            $created = PatientRmSequence::query()->insertOrIgnore([
                'branch_id' => $branchId,
                'year' => $year,
                'last_number' => $value,
                'seed_source' => PatientRmSequence::SOURCE_COMMAND,
                'seeded_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]) === 1;

            $row = $this->lockedRow($branchId, $year)
                ?? throw new LogicException('The Nomor RM counter row could not be created.');
        }

        if ($created) {
            return 'created';
        }

        if ($value <= (int) $row->last_number) {
            return 'unchanged';
        }

        $row->forceFill([
            'last_number' => $value,
            'seed_source' => PatientRmSequence::SOURCE_COMMAND,
            'seeded_at' => now(),
        ])->save();

        return 'raised';
    }

    public function anyNumberIssued(array $medicalRecordNumbers): bool
    {
        $numbers = array_values(array_filter($medicalRecordNumbers, fn ($n) => is_string($n) && $n !== ''));

        if ($numbers === []) {
            return false;
        }

        return Patient::withTrashed()->whereIn('medical_record_number', $numbers)->exists();
    }

    public function issuedNumbersFor(array $branchCodes, int $year): LazyCollection
    {
        $prefixes = array_map(
            fn (string $code): string => PatientMedicalRecordNumberService::PREFIX.'-'.$code.'-'.$year.'-',
            array_values($branchCodes),
        );

        if ($prefixes === []) {
            return LazyCollection::empty();
        }

        return Patient::withTrashed()
            ->select(['id', 'medical_record_number'])
            ->where(function ($query) use ($prefixes): void {
                foreach ($prefixes as $prefix) {
                    $query->orWhere('medical_record_number', 'like', $this->likePrefix($prefix));
                }
            })
            ->lazyById(500)
            ->map(fn (Patient $patient): string => (string) $patient->medical_record_number);
    }

    public function issuedPrefixedNumbers(): LazyCollection
    {
        return Patient::withTrashed()
            ->select(['id', 'medical_record_number'])
            ->where('medical_record_number', 'like', $this->likePrefix(PatientMedicalRecordNumberService::PREFIX.'-'))
            ->lazyById(500)
            ->map(fn (Patient $patient): string => (string) $patient->medical_record_number);
    }

    private function lockedRow(int $branchId, int $year): ?PatientRmSequence
    {
        return PatientRmSequence::query()
            ->where('branch_id', $branchId)
            ->where('year', $year)
            ->lockForUpdate()
            ->first();
    }

    /** LIKE pattern for a literal prefix (escapes the LIKE wildcards it may contain). */
    private function likePrefix(string $prefix): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $prefix).'%';
    }
}
