<?php

declare(strict_types=1);

namespace App\Modules\Patient\Services;

use App\Modules\Branch\Models\Branch;
use App\Modules\Branch\Support\BranchCodeAlias;
use App\Modules\Patient\Exceptions\PatientRmNumberUnavailableException;
use App\Modules\Patient\Interfaces\PatientRmSequenceRepositoryInterface;
use App\Modules\PatientMerge\Interfaces\PatientRmAliasRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use LogicException;

/**
 * DMS-RME-COMPREHENSIVE-CLOSURE-1 / memo M4.1 (owner decision 2026-11-17, "Auto,
 * read-only"; rule 244 §1) — issues the next automatic Nomor RM
 * `DG-{KODE}-{TAHUN}-{NNNN}` for a branch and clinic year.
 *
 * - Runs only inside the caller's registration transaction: the branch-year
 *   counter row is locked FOR UPDATE and stays locked until the patient row is
 *   committed, so two registrations never read the same counter.
 * - Never reissues a number: a candidate is skipped while it exists in ANY
 *   spelling — the canonical branch code or a historical alias (TKM1/SUN4), any
 *   zero padding — on any patient, soft-deleted ones included, or as an active
 *   merge alias. Existing numbers are never rewritten.
 * - Widens past 9999 (10000, 10001, …) instead of stopping registration, warns
 *   from 9000 (no number in the log), and refuses once a sequence would need
 *   nine digits.
 * - Refuses MAIN, which is never an RME branch.
 *
 * Dormant in rule 244 PR-1: nothing in the registration path calls it yet.
 */
final class PatientRmNumberAllocator
{
    /** From this sequence on, each allocation logs a capacity warning. */
    public const WARN_FROM = 9000;

    /** The widest sequence ever issued; nine digits are refused. */
    public const MAX_DIGITS = 8;

    public function __construct(
        private readonly PatientRmSequenceRepositoryInterface $sequences,
        private readonly PatientRmAliasRepositoryInterface $aliases,
        private readonly PatientMedicalRecordNumberService $numbers,
        private readonly PatientRmSequenceSeedService $seeds,
    ) {}

    public function allocate(Branch $branch, int $year): string
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('An automatic Nomor RM is allocated only inside the registration transaction.');
        }

        $code = BranchCodeAlias::canonicalize($branch->code);

        if ($code === null) {
            throw PatientRmNumberUnavailableException::unknownBranch();
        }

        if ($code === Branch::MAIN_CODE) {
            throw PatientRmNumberUnavailableException::mainBranch();
        }

        if ($year < 1000 || $year > 9999) {
            throw new InvalidArgumentException('Registration year must be exactly four digits.');
        }

        $branchId = (int) $branch->getKey();
        $sequence = $this->sequences->lockOrCreate(
            $branchId,
            $year,
            fn (): int => $this->seeds->highestIssued($code, $year),
        );

        $next = (int) $sequence->last_number;

        do {
            $next++;

            if (strlen((string) $next) > self::MAX_DIGITS) {
                throw PatientRmNumberUnavailableException::exhausted();
            }

            $spellings = $this->numbers->numericSpellings($code, $year, $next);
        } while ($this->sequences->anyNumberIssued($spellings) || $this->aliases->findActiveByNumbers($spellings) !== null);

        $this->sequences->advanceTo($sequence, $next);

        if ($next >= self::WARN_FROM) {
            Log::warning('Automatic Nomor RM sequence is nearing or past four digits for a branch-year.', [
                'branch_id' => $branchId,
                'year' => $year,
                'digits' => strlen((string) $next),
            ]);
        }

        return $this->numbers->compose($code, $year, $this->numbers->formatSequence($next));
    }
}
