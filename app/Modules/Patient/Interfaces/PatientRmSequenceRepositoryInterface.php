<?php

declare(strict_types=1);

namespace App\Modules\Patient\Interfaces;

use App\Modules\Patient\Models\PatientRmSequence;
use Illuminate\Support\LazyCollection;

/**
 * DMS-RME-COMPREHENSIVE-CLOSURE-1 / memo M4.1 (rule 244 §1) — persistence of the
 * automatic Nomor RM counters and the reads that keep them from reissuing a
 * number. Every write method requires the caller's open transaction.
 */
interface PatientRmSequenceRepositoryInterface
{
    /**
     * The branch-year counter row, locked FOR UPDATE. A missing row is created
     * first with INSERT … ON CONFLICT DO NOTHING, its `last_number` taken from
     * `$seed()`, so two first allocations serialise on the unique key.
     *
     * @param  callable(): int  $seed
     */
    public function lockOrCreate(int $branchId, int $year, callable $seed): PatientRmSequence;

    /** Record a newly issued sequence on the locked row (never lowers it). */
    public function advanceTo(PatientRmSequence $sequence, int $lastNumber): void;

    /**
     * Raise the counter to at least `$value` (GREATEST, never lower), creating the
     * row when missing.
     *
     * @return 'created'|'raised'|'unchanged'
     */
    public function raiseTo(int $branchId, int $year, int $value): string;

    /** Whether any patient — soft-deleted ones included — already holds one of these numbers. */
    public function anyNumberIssued(array $medicalRecordNumbers): bool;

    /**
     * Every Nomor RM issued under one of `$branchCodes` for `$year`, soft-deleted
     * patients included (they reserve their number).
     *
     * @param  list<string>  $branchCodes
     * @return LazyCollection<int, string>
     */
    public function issuedNumbersFor(array $branchCodes, int $year): LazyCollection;

    /**
     * Every issued value that starts with the `DG-` prefix, soft-deleted patients
     * included, for the seeding report.
     *
     * @return LazyCollection<int, string>
     */
    public function issuedPrefixedNumbers(): LazyCollection;
}
