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

    /**
     * Rule 244 §2.5 — whether any patient, soft-deleted ones included, holds one of
     * these numbers, compared case-insensitively (`dg-spn4-2026-0007` holds
     * `DG-SPN4-2026-0007`, as the merge-alias lookup already compares).
     *
     * PR-2 review F1 — a canonical number with an all-digit sequence is compared
     * by its numeric VALUE in any zero padding, not by a list of spellings: a
     * patient holds `DG-SPN4-2026-6` when its own number starts with the same
     * prefix (case-insensitively) and the rest, with leading zeros stripped, is
     * exactly `6` — so `…-000000000006` (or a 30-digit padding) is caught too.
     * `LTRIM(text, chars)` and `SUBSTR(text, start)` mean the same on PostgreSQL
     * and SQLite; the start offset is an integer computed from a validated
     * prefix, never request text. Anything else is compared exactly.
     */
    public function anyNumberIssued(array $medicalRecordNumbers): bool
    {
        $exact = [];
        $byValue = [];

        foreach ($medicalRecordNumbers as $number) {
            if (! is_string($number) || trim($number) === '') {
                continue;
            }

            $lower = mb_strtolower(trim($number));

            if (preg_match('/^(dg-[a-z0-9]{2,16}-[0-9]{4}-)([0-9]+)$/', $lower, $match) === 1 && ltrim($match[2], '0') !== '') {
                $value = ltrim($match[2], '0');
                $byValue[$match[1].'|'.$value] = [$match[1], $value];

                continue;
            }

            $exact[$lower] = true;
        }

        foreach (array_chunk(array_keys($exact), 1000) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '?'));

            if (Patient::withTrashed()->whereRaw("LOWER(medical_record_number) IN ({$placeholders})", $chunk)->exists()) {
                return true;
            }
        }

        foreach (array_chunk(array_values($byValue), 200) as $chunk) {
            $held = Patient::withTrashed()->where(function ($query) use ($chunk): void {
                foreach ($chunk as [$prefix, $value]) {
                    $query->orWhere(function ($query) use ($prefix, $value): void {
                        $query->whereRaw('LOWER(medical_record_number) LIKE ?', [$this->likePrefix($prefix)])
                            ->whereRaw('LTRIM(SUBSTR(LOWER(medical_record_number), '.(strlen($prefix) + 1).'), ?) = ?', ['0', $value]);
                    });
                }
            })->exists();

            if ($held) {
                return true;
            }
        }

        return false;
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

        // Rule 244 §2.5 — case-insensitive, so a lower-case spelling of a number in
        // this branch-year is read too (the comparison key uppercases it).
        return Patient::withTrashed()
            ->select(['id', 'medical_record_number'])
            ->where(function ($query) use ($prefixes): void {
                foreach ($prefixes as $prefix) {
                    $query->orWhereRaw('LOWER(medical_record_number) LIKE ?', [mb_strtolower($this->likePrefix($prefix))]);
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
