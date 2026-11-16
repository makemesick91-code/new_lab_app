<?php

declare(strict_types=1);

namespace App\Modules\Patient\Services;

use App\Modules\Branch\Interfaces\BranchRepositoryInterface;
use App\Modules\Branch\Models\Branch;
use App\Modules\Branch\Support\BranchCodeAlias;
use App\Modules\Patient\Interfaces\PatientRmSequenceRepositoryInterface;
use Illuminate\Support\Facades\DB;

/**
 * DMS-RME-COMPREHENSIVE-CLOSURE-1 / memo M4.1 (rule 244 §1) — where the automatic
 * Nomor RM counters start.
 *
 * The highest numeric sequence already issued in a branch-year — under the
 * canonical branch code and its historical aliases, in any zero padding,
 * soft-deleted patients included — is the counter's floor, so the first
 * automatic number continues after it and never collides with a number on an
 * existing card. Non-numeric sequences are counted, never interpreted. A
 * sequence wider than the allocator ever issues ({@see self::isOversized()}, e.g.
 * a phone number typed as the sequence) can never collide with an issuable
 * number, so it is counted and never seeded: one such card would otherwise lift
 * the counter past the allocator's cap and, because a counter is never lowered,
 * stop automatic numbering for that branch-year for good.
 *
 * The allocator calls {@see self::highestIssued()} when a branch-year row does not
 * exist yet (lazy seed); `patient:rm-sequence-seed` reports {@see self::plan()} and,
 * with `--apply`, raises each counter to it ({@see self::apply()}).
 */
final class PatientRmSequenceSeedService
{
    public function __construct(
        private readonly PatientRmSequenceRepositoryInterface $sequences,
        private readonly PatientMedicalRecordNumberService $numbers,
        private readonly BranchRepositoryInterface $branches,
    ) {}

    /** The highest numeric sequence issued for this canonical branch code and year (0 when none). */
    public function highestIssued(string $canonicalCode, int $year, ?int $ignoreAbove = null): int
    {
        $highest = 0;

        foreach ($this->sequences->issuedNumbersFor(BranchCodeAlias::equivalentCodes($canonicalCode), $year) as $number) {
            $value = $this->numbers->numericSequenceOf($number);

            if ($value === null || self::isOversized($value) || ($ignoreAbove !== null && $value > $ignoreAbove)) {
                continue;
            }

            $highest = max($highest, $value);
        }

        return $highest;
    }

    /** Whether a sequence is wider than any number the allocator issues. */
    public static function isOversized(int $value): bool
    {
        return strlen((string) $value) > PatientRmNumberAllocator::MAX_DIGITS;
    }

    /**
     * The seeding report: one group per known, non-MAIN branch and year, with
     * counts and the proposed counter, plus totals. It never holds a Nomor RM;
     * the command prints the proposed counter's digit count only.
     *
     * @return array{groups: list<array{branch_id: int, code: string, year: int, numbers: int, non_numeric: int, above_9999: int, ignored_oversized: int, ignored_above: int, proposed: int}>, totals: array<string, int>}
     */
    public function plan(?int $onlyYear, ?int $ignoreAbove): array
    {
        $groups = [];
        $branchIds = [];
        $unknownCodes = [];
        $totals = [
            'numbers' => 0,
            'not_canonical' => 0,
            'main_numbers' => 0,
            'unknown_code_numbers' => 0,
            'non_numeric' => 0,
            'above_9999' => 0,
            'ignored_oversized' => 0,
            'ignored_above' => 0,
        ];

        foreach ($this->sequences->issuedPrefixedNumbers() as $number) {
            $parts = $this->numbers->parse($number);

            if ($parts === null) {
                $totals['not_canonical']++;

                continue;
            }

            $year = (int) $parts->year;

            if ($onlyYear !== null && $year !== $onlyYear) {
                continue;
            }

            $code = (string) BranchCodeAlias::canonicalize($parts->branchCode);

            if ($code === Branch::MAIN_CODE) {
                $totals['main_numbers']++;

                continue;
            }

            if (! array_key_exists($code, $branchIds)) {
                $branchIds[$code] = $this->branches->findByCode($code)?->getKey();
            }

            if ($branchIds[$code] === null) {
                $totals['unknown_code_numbers']++;
                $unknownCodes[$code] = true;

                continue;
            }

            $key = $code.'|'.$year;
            $groups[$key] ??= [
                'branch_id' => (int) $branchIds[$code],
                'code' => $code,
                'year' => $year,
                'numbers' => 0,
                'non_numeric' => 0,
                'above_9999' => 0,
                'ignored_oversized' => 0,
                'ignored_above' => 0,
                'proposed' => 0,
            ];

            $groups[$key]['numbers']++;
            $totals['numbers']++;

            $value = $this->numbers->numericSequenceOf($number);

            if ($value === null) {
                $groups[$key]['non_numeric']++;
                $totals['non_numeric']++;

                continue;
            }

            if ($value > 9999) {
                $groups[$key]['above_9999']++;
                $totals['above_9999']++;
            }

            if (self::isOversized($value)) {
                $groups[$key]['ignored_oversized']++;
                $totals['ignored_oversized']++;

                continue;
            }

            if ($ignoreAbove !== null && $value > $ignoreAbove) {
                $groups[$key]['ignored_above']++;
                $totals['ignored_above']++;

                continue;
            }

            $groups[$key]['proposed'] = max($groups[$key]['proposed'], $value);
        }

        ksort($groups);
        $totals['groups'] = count($groups);
        $totals['unknown_code_groups'] = count($unknownCodes);

        return ['groups' => array_values($groups), 'totals' => $totals];
    }

    /**
     * Raise each planned counter to its proposed value — GREATEST, never lower —
     * one branch-year per transaction.
     *
     * @param  list<array{branch_id: int, year: int, proposed: int}>  $groups
     * @return array{created: int, raised: int, unchanged: int}
     */
    public function apply(array $groups): array
    {
        $result = ['created' => 0, 'raised' => 0, 'unchanged' => 0];

        foreach ($groups as $group) {
            if ($group['proposed'] < 1) {
                $result['unchanged']++;

                continue;
            }

            $outcome = DB::transaction(fn (): string => $this->sequences->raiseTo(
                $group['branch_id'],
                $group['year'],
                $group['proposed'],
            ));

            $result[$outcome]++;
        }

        return $result;
    }
}
