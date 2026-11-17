<?php

declare(strict_types=1);

namespace App\Modules\Patient\Services;

use App\Modules\Branch\Models\Branch;
use App\Modules\Branch\Support\BranchCodeAlias;
use App\Modules\Patient\Exceptions\PatientRmNumberUnavailableException;
use App\Modules\Patient\Interfaces\PatientRmSequenceRepositoryInterface;
use App\Modules\Patient\Models\PatientRmSequence;
use App\Modules\PatientMerge\Interfaces\PatientRmAliasRepositoryInterface;
use App\Support\Clinical\ClinicalClock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use LogicException;
use Throwable;

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
 * - Refuses MAIN, which is never an RME branch, and a branch code the Nomor RM
 *   format cannot carry: the composed number must parse back to the same branch
 *   and sequence ({@see PatientMedicalRecordNumberService::parse()}), or nothing
 *   is issued (a code with '-' or longer than 16 characters would not).
 *
 * Active since rule 244 PR-2 (§2): `PatientService::create()` is its only
 * caller, inside the registration transaction. The same class is the one answer
 * to "is this Nomor RM taken?" ({@see self::isTaken()}, {@see self::takenAmong()})
 * and the gate for a number that already exists outside the system — a legacy
 * CSV row, an old paper card, a pilot backup row — which is stored only after
 * {@see self::claimExisting()} has locked its branch-year counter and passed the
 * claim cap (rule 244 §2.6).
 */
final class PatientRmNumberAllocator
{
    /** From this sequence on, each allocation logs a capacity warning. */
    public const WARN_FROM = 9000;

    /** The widest sequence ever issued; nine digits are refused. */
    public const MAX_DIGITS = 8;

    /**
     * Rule 244 §2.6 (owner decisions 2026-11-17, "RM CLAIM CAP: refuse jump >
     * +1000" and "Per batch") — the most the current clinical year's counter may
     * be raised: by one paper-card claim, or by a whole legacy CSV batch or pilot
     * backup run in total, measured from the counter the batch locked at its start.
     */
    public const CLAIM_MAX_JUMP = 1000;

    /** Rule 244 §2.6 — a claim never raises the current year's counter past the four-digit range. */
    public const CLAIM_MAX_CURRENT_YEAR_VALUE = 9999;

    /**
     * Rule 244 §2.6 — the exception code of a claim REFUSED by the cap or by the
     * format capacity: never accepted automatically, the operator escalates the
     * case to Supervisor RME. Callers tell it apart from the other
     * {@see PatientRmNumberUnavailableException} reasons by this code.
     */
    public const CLAIM_REFUSED = 2446;

    /** Rule 244 §2.6 — the operator message of a jump past the cap; no number in it. */
    public const CLAIM_JUMP_MESSAGE = 'Nomor RM ini melompat lebih dari 1000 nomor di atas nomor terakhir cabang untuk tahun berjalan, atau melewati 9999. Nomor tidak diterima otomatis: eskalasikan kasus ini ke Supervisor RME.';

    /** Rule 244 §2.6 — the operator message of a number the format cannot carry; no number in it. */
    public const CLAIM_CAPACITY_MESSAGE = 'Nomor RM ini di luar kapasitas format Nomor RM (nomor urut berupa angka 1 sampai 8 digit, bukan nol). Nomor tidak diterima: eskalasikan kasus ini ke Supervisor RME.';

    /** Rule 244 §2.6 (review A4) — a claim for a year that has not started; no number in it. */
    public const CLAIM_FUTURE_YEAR_MESSAGE = 'Tahun pada Nomor RM ini belum berjalan menurut kalender klinik. Nomor tidak diterima: eskalasikan kasus ini ke Supervisor RME.';

    public function __construct(
        private readonly PatientRmSequenceRepositoryInterface $sequences,
        private readonly PatientRmAliasRepositoryInterface $aliases,
        private readonly PatientMedicalRecordNumberService $numbers,
        private readonly PatientRmSequenceSeedService $seeds,
        // Rule 244 §2.6 — which year is "current" for the claim cap (CG-12).
        private readonly ClinicalClock $clock,
    ) {}

    public function allocate(Branch $branch, int $year): string
    {
        [$sequence, $code] = $this->lockedCounter(
            $branch,
            $year,
            'An automatic Nomor RM is allocated only inside the registration transaction.',
        );
        $branchId = (int) $branch->getKey();

        // PR-2 review item 2 — the candidate after the counter is almost always
        // free (the seed and every claim raise the counter to the highest). When
        // it is not, ONE set-based read of the branch-year finds the first value
        // above it that no patient holds in any padding; the per-candidate check
        // stays as the safety net (and covers merge aliases).
        $next = (int) $sequence->last_number + 1;

        while (true) {
            if (strlen((string) $next) > self::MAX_DIGITS) {
                throw PatientRmNumberUnavailableException::exhausted();
            }

            $spellings = $this->numbers->numericSpellings($code, $year, $next);

            if (! $this->sequences->anyNumberIssued($spellings) && $this->aliases->findActiveByNumbers($spellings) === null) {
                break;
            }

            $next = $this->firstFreeAbove($code, $year, $next);
        }

        $number = $this->numbers->compose($code, $year, $this->numbers->formatSequence($next));

        // Checked before the counter moves: a number that the one Nomor RM parser
        // does not read back to this branch and sequence is never issued.
        if ($this->numbers->parse($number)?->toString() !== $number
            || $this->numbers->branchCodeFrom($number) !== $code
            || $this->numbers->numericSequenceOf($number) !== $next) {
            throw PatientRmNumberUnavailableException::unparseableBranchCode();
        }

        $this->sequences->advanceTo($sequence, $next);

        if ($next >= self::WARN_FROM) {
            Log::warning('Automatic Nomor RM sequence is nearing or past four digits for a branch-year.', [
                'branch_id' => $branchId,
                'year' => $year,
                'digits' => strlen((string) $next),
            ]);
        }

        return $number;
    }

    /**
     * Rule 244 §2.5 — whether a Nomor RM is already taken: any of its collision
     * spellings ({@see PatientMedicalRecordNumberService::collisionSpellings()})
     * held, case-insensitively, by any patient — soft-deleted ones included — or
     * by an active merge alias. The one answer to "is this number taken?";
     * advisory unless the caller holds the branch-year counter lock
     * ({@see self::claimExisting()}).
     */
    public function isTaken(string $medicalRecordNumber): bool
    {
        $spellings = $this->numbers->collisionSpellings($medicalRecordNumber);

        return $spellings !== []
            && ($this->sequences->anyNumberIssued($spellings) || $this->aliases->findActiveByNumbers($spellings) !== null);
    }

    /**
     * Rule 244 §2.5 — {@see self::isTaken()} for a whole batch in a bounded number
     * of queries: each branch-year's issued numbers are read once and compared by
     * {@see PatientMedicalRecordNumberService::collisionKey()}; the merge aliases
     * are asked once per chunk (plus once per alias actually found).
     *
     * @param  array<int, string|null>  $medicalRecordNumbers
     * @return array<string, true> the given values that are taken, keyed by value
     */
    public function takenAmong(array $medicalRecordNumbers): array
    {
        $values = array_values(array_unique(array_filter(
            array_map(static fn ($n): string => trim((string) $n), $medicalRecordNumbers),
            static fn (string $n): bool => $n !== '',
        )));

        $taken = [];
        $byKey = [];
        $groups = [];

        foreach ($values as $value) {
            $parts = $this->numbers->parse(strtoupper($value));

            if ($parts === null) {
                // Not a Nomor RM: only its own spelling can collide.
                if ($this->sequences->anyNumberIssued([$value])) {
                    $taken[$value] = true;
                }

                continue;
            }

            $code = (string) BranchCodeAlias::canonicalize($parts->branchCode);
            $byKey[$this->numbers->collisionKey($value)][] = $value;
            $groups[$code.'|'.$parts->year] = [$code, (int) $parts->year];
        }

        foreach ($groups as [$code, $year]) {
            foreach ($this->sequences->issuedNumbersFor(BranchCodeAlias::equivalentCodes($code), $year) as $issued) {
                foreach ($byKey[$this->numbers->collisionKey($issued)] ?? [] as $value) {
                    $taken[$value] = true;
                }
            }
        }

        $remaining = array_values(array_filter($values, static fn (string $v): bool => ! isset($taken[$v])));

        foreach (array_chunk($remaining, 50) as $chunk) {
            /** @var array<string, list<string>> $owners lower-case spelling => given values */
            $owners = [];

            foreach ($chunk as $value) {
                foreach ($this->numbers->collisionSpellings($value) as $spelling) {
                    $owners[mb_strtolower($spelling)][] = $value;
                }
            }

            while ($owners !== [] && ($alias = $this->aliases->findActiveByNumbers(array_keys($owners))) !== null) {
                $hit = mb_strtolower(trim((string) $alias->alias_medical_record_number));

                if (! isset($owners[$hit])) {
                    break;
                }

                foreach ($owners[$hit] as $value) {
                    $taken[$value] = true;
                }

                unset($owners[$hit]);
            }
        }

        return $taken;
    }

    /**
     * Rule 244 §2.6 — lock a branch-year counter row FOR UPDATE inside the caller's
     * transaction (creating and seeding it exactly as {@see self::allocate()} does
     * when it is missing), so no automatic number is issued for that branch-year
     * until the caller commits. MAIN and an unknown code are refused, as there.
     *
     * Returns the counter as locked — the batch BASELINE (owner decision
     * 2026-11-17, "Per batch"): a legacy CSV commit or a pilot backup run passes
     * it to every {@see self::claimExisting()} of the batch, so the batch as a
     * whole raises the branch-year by at most {@see self::CLAIM_MAX_JUMP}.
     */
    public function lockCounter(Branch $branch, int $year): int
    {
        [$sequence] = $this->lockedCounter($branch, $year, 'A Nomor RM counter is locked only inside the caller\'s transaction.');

        return (int) $sequence->last_number;
    }

    /**
     * Rule 244 §2.6 — claim a number that already exists outside the system (a
     * legacy CSV row, an old paper card, a pilot backup row): lock its branch-year
     * counter, then report whether it is free in every spelling. TRUE means the
     * caller may store it verbatim before its transaction ends; FALSE means it is
     * taken. A successful claim raises the counter to the claimed value
     * (GREATEST, never lower), so the sequence continues after the highest number
     * on record (owner default 3).
     *
     * The claim cap (owner decisions 2026-11-17, "RM CLAIM CAP: refuse jump >
     * +1000" and "Per batch") — refused with a
     * {@see PatientRmNumberUnavailableException} whose code is
     * {@see self::CLAIM_REFUSED}, before anything moves:
     *  - before the counter is even locked ({@see self::formatRefusal()}): a year
     *    after the clinical year (review A4); for the current year a sequence
     *    that is not all digits (review A3); in any year an all-digit sequence
     *    whose value is zero or wider than {@see self::MAX_DIGITS} digits;
     *  - for the current clinical year ({@see ClinicalClock}), a value above
     *    {@see self::CLAIM_MAX_CURRENT_YEAR_VALUE}, or more than
     *    {@see self::CLAIM_MAX_JUMP} above the reference: the locked counter,
     *    including this transaction's earlier claims (a paper card: per claim),
     *    or — for a batch — `$batchBaseline`, the counter {@see self::lockCounter()}
     *    returned when the batch started, so the whole batch raises it by at most
     *    +1000. Decided under the counter row lock, the unique index as backstop.
     *    A value at or below the counter moves nothing and is not a jump.
     * A past year's claim only ever raises that year's counter, and a past-year
     * sequence that is not all digits never reaches a counter (only "taken").
     */
    public function claimExisting(Branch $branch, string $medicalRecordNumber, ?int $batchBaseline = null): bool
    {
        $parts = $this->numbers->parse(strtoupper(trim($medicalRecordNumber)));
        $code = BranchCodeAlias::canonicalize($branch->code);

        if ($parts === null || $code === null || BranchCodeAlias::canonicalize($parts->branchCode) !== $code) {
            throw new InvalidArgumentException('A claimed Nomor RM must belong to the branch it is registered under.');
        }

        if (($refusal = $this->formatRefusal($medicalRecordNumber)) !== null) {
            throw new PatientRmNumberUnavailableException($refusal, self::CLAIM_REFUSED);
        }

        $value = $this->numbers->numericValueOf($medicalRecordNumber);
        $year = (int) $parts->year;

        [$sequence] = $this->lockedCounter(
            $branch,
            $year,
            'A Nomor RM is claimed only inside the caller\'s transaction.',
        );

        if ($this->isTaken($medicalRecordNumber)) {
            return false;
        }

        if ($value === null) {
            return true;
        }

        $claimed = (int) $value;
        $counter = (int) $sequence->last_number;
        // Per batch: measured from where the batch started (never above the
        // counter, which only this batch has raised since, under the held lock).
        $reference = $batchBaseline !== null ? min($batchBaseline, $counter) : $counter;

        if ($year === (int) $this->clock->today()->format('Y') && $claimed > $counter
            && ($claimed > self::CLAIM_MAX_CURRENT_YEAR_VALUE || $claimed - $reference > self::CLAIM_MAX_JUMP)) {
            throw new PatientRmNumberUnavailableException(self::CLAIM_JUMP_MESSAGE, self::CLAIM_REFUSED);
        }

        // Owner default 3 ("continue after the highest", PR-2 review item 2):
        // GREATEST, never lower, under the same lock.
        $this->sequences->advanceTo($sequence, $claimed);

        return true;
    }

    /**
     * Rule 244 §2.6 — why no claim may ever accept this Nomor RM, whatever the
     * counter says (the operator message, without the number), or null:
     *  - its year is after the clinical year (review A4: never plausible);
     *  - for the current clinical year, its sequence is not all digits (review
     *    A3); a past year stays lenient (only "taken" is checked);
     *  - in any year, its all-digit sequence is zero or wider than
     *    {@see self::MAX_DIGITS} digits (the format capacity).
     * Null for a value that is not a Nomor RM. It reads no counter, so a dry run
     * can answer it too. Digits are read as
     * {@see PatientMedicalRecordNumberService::numericValueOf()} reads them.
     */
    public function formatRefusal(string $medicalRecordNumber): ?string
    {
        $parts = $this->numbers->parse(strtoupper(trim($medicalRecordNumber)));

        if ($parts === null) {
            return null;
        }

        $year = (int) $parts->year;
        $currentYear = (int) $this->clock->today()->format('Y');

        if ($year > $currentYear) {
            return self::CLAIM_FUTURE_YEAR_MESSAGE;
        }

        if (preg_match('/^[0-9]+$/', $parts->sequence) !== 1) {
            return $year === $currentYear ? self::CLAIM_CAPACITY_MESSAGE : null;
        }

        $value = ltrim($parts->sequence, '0');

        return $value === '' || strlen($value) > self::MAX_DIGITS ? self::CLAIM_CAPACITY_MESSAGE : null;
    }

    /** Rule 244 §2.6 — whether a claim was refused by the cap or the format capacity (escalate to Supervisor RME). */
    public static function isClaimRefusal(Throwable $e): bool
    {
        return $e instanceof PatientRmNumberUnavailableException && $e->getCode() === self::CLAIM_REFUSED;
    }

    /**
     * PR-2 review item 2 — the first value above `$after` that no patient holds
     * in this branch-year, in any alias code, letter case or zero padding: one
     * pass over the branch-year's issued numbers ({@see PatientRmSequenceRepositoryInterface::issuedNumbersFor()}),
     * compared by numeric value ({@see PatientMedicalRecordNumberService::numericValueOf()}).
     */
    private function firstFreeAbove(string $code, int $year, int $after): int
    {
        $held = [];

        foreach ($this->sequences->issuedNumbersFor(BranchCodeAlias::equivalentCodes($code), $year) as $issued) {
            $value = $this->numbers->numericValueOf($issued);

            if ($value !== null && strlen($value) <= self::MAX_DIGITS && (int) $value > $after) {
                $held[(int) $value] = true;
            }
        }

        $next = $after + 1;

        while (isset($held[$next])) {
            $next++;
        }

        return $next;
    }

    /**
     * The branch-year counter row, locked FOR UPDATE (rule 244 §1.2): the lock
     * lasts until the caller's transaction ends, so it must already be open.
     *
     * @return array{0: PatientRmSequence, 1: string} the row and the canonical branch code
     */
    private function lockedCounter(Branch $branch, int $year, string $outsideTransaction): array
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException($outsideTransaction);
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

        $sequence = $this->sequences->lockOrCreate(
            (int) $branch->getKey(),
            $year,
            fn (): int => $this->seeds->highestIssued($code, $year),
        );

        return [$sequence, $code];
    }
}
