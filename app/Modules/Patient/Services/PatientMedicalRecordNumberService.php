<?php

namespace App\Modules\Patient\Services;

use App\Modules\Branch\Support\BranchCodeAlias;
use App\Modules\Patient\Support\MedicalRecordNumberParts;
use App\Support\Clinical\ClinicalClock;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Sprint 23 Phase 23.8 — finalized patient medical record number composer.
 *
 * The clinic owner locked the final format to:
 *
 *     DG-{KODE_CABANG}-{TAHUN_DAFTAR}-{NOMOR_RM_MANUAL}
 *
 * Examples:
 *   - DG-TKM1-2026-0001
 *   - DG-LDK2-2026-25
 *
 * Rules enforced here:
 *   - Prefix is exactly "DG".
 *   - Branch code is uppercased and trimmed.
 *   - Year must be four digits.
 *   - Manual RM number is trimmed but NOT auto-generated and NOT auto-padded.
 *     Leading zeros entered by the admin are preserved verbatim.
 *   - The composed value must be globally unique in mst_patients.
 *
 * This composer never invents a sequence; the manual RM number always comes
 * from the user/admin. (The separate Sprint 23.3 `PatientCodeGenerator` UTC
 * fallback was retired by rule 244 PR-2.)
 *
 * REVISED BY RULE 244 §2–§3 (DMS-RME-COMPREHENSIVE-CLOSURE-1 / memo M4.1, owner
 * decision 2026-11-17 "Auto, read-only"; active since rule 244 PR-2) — the
 * Sprint 23.8 "manual, never generated" rule above no longer governs NEW
 * registrations: {@see PatientRmNumberAllocator} issues the sequence per branch
 * and clinic year, padded to four digits ({@see self::formatSequence()}), and no
 * registration form or request field supplies it. A typed sequence survives only
 * for a number that already exists outside the system — the legacy patient CSV
 * import and (rule 244 PR-3) the Supervisor RME paper-card exception — and each
 * is claimed through {@see PatientRmNumberAllocator::claimExisting()} under the
 * branch-year counter lock. compose() and parse() are unchanged, and no existing
 * Nomor RM is ever rewritten.
 */
class PatientMedicalRecordNumberService
{
    public const PREFIX = 'DG';

    /**
     * POST-RME-ODONTOGRAM-STABILIZATION-1 / FIX-03 — the clinical calendar is
     * the authority for the registration YEAR baked into a patient's Nomor RM.
     */
    public function __construct(
        private readonly ClinicalClock $clock,
    ) {}

    /**
     * Compose the final medical record number from its components.
     */
    public function compose(string $branchCode, int|string $year, string $manualRmNumber): string
    {
        $branchCode = strtoupper(trim($branchCode));
        $manualRmNumber = trim($manualRmNumber);
        $year = $this->normalizeYear($year);

        if ($branchCode === '') {
            throw new InvalidArgumentException('Branch code is required to compose a medical record number.');
        }

        if ($manualRmNumber === '') {
            throw new InvalidArgumentException('Manual RM number is required to compose a medical record number.');
        }

        return self::PREFIX.'-'.$branchCode.'-'.$year.'-'.$manualRmNumber;
    }

    /**
     * Convenience composer that derives the year from a registration date.
     *
     * POST-RME-ODONTOGRAM-STABILIZATION-1 / FIX-03 — when no registration date
     * is supplied, "today" is TODAY IN THE CLINIC, resolved through
     * {@see ClinicalClock} (Asia/Makassar), not through the process clock.
     *
     * This closes the residual recorded in
     * docs/sprints/fix-clinic-ops-branch-context-wa-1.md ("Known residual"),
     * which that sprint reported rather than fixed because it touches RM-number
     * composition. `config/app.php` hard-codes `'timezone' => 'UTC'` on purpose
     * — technical instants stay UTC — so the previous `Carbon::now()` fallback
     * resolved the UTC calendar day. Between 00:00 and 08:00 WITA the UTC date
     * is still YESTERDAY, so a patient registered in that window on 1 January
     * was issued an RM number carrying the PREVIOUS year, permanently, while
     * the visit created in the same registration already used ClinicalClock and
     * so carried the correct year. One transaction, two different years.
     *
     * A caller that DOES supply a date is trusted verbatim: that value is a
     * calendar date a human entered or the workflow already stamped, and
     * pushing it through a timezone conversion would corrupt it.
     */
    public function composeForRegistration(string $branchCode, ?CarbonInterface $registeredAt, string $manualRmNumber): string
    {
        $registeredAt = $registeredAt ?? $this->clock->today();

        return $this->compose($branchCode, (int) $registeredAt->format('Y'), $manualRmNumber);
    }

    /**
     * LEGACY-RME-PDF-FIX-ROLL2-1 — the canonical PARSER for the format this
     * class composes. It is the exact inverse of {@see self::compose()}.
     *
     * It exists because the branch a document belongs to is encoded in the
     * patient's own Nomor RM, and every consumer of that fact must read it the
     * same way. No controller, form request, service or view may carry its own
     * regex for this format: parsing lives here, next to the composer, so the
     * two can never drift apart.
     *
     * Returns NULL — never a partial guess — for anything that is not exactly
     * `DG-{KODE_CABANG}-{TAHUN}-{NOMOR}`. A caller that needs a branch must
     * treat null as fail-closed; nothing here invents a default.
     *
     * The manual sequence may itself contain a hyphen (compose() accepts any
     * non-empty trimmed string), so the split is bounded to four parts and the
     * remainder is kept verbatim. That guarantees
     * `parse($x)?->toString() === $x` for every value compose() can produce.
     */
    public function parse(?string $medicalRecordNumber): ?MedicalRecordNumberParts
    {
        $value = trim((string) $medicalRecordNumber);

        if ($value === '') {
            return null;
        }

        $parts = explode('-', $value, 4);

        if (count($parts) !== 4) {
            return null;
        }

        [$prefix, $branchCode, $year, $sequence] = $parts;

        // The prefix is a fixed literal; anything else is not our format.
        if (strtoupper(trim($prefix)) !== self::PREFIX) {
            return null;
        }

        // compose() uppercases and trims the branch code, so a stored value is
        // already normalized. Reject anything with padding or a separator in
        // it rather than silently repairing it — a value that does not round
        // trip is not a canonical Nomor RM.
        if ($branchCode === '' || preg_match('/^[A-Z0-9]{2,16}$/', $branchCode) !== 1) {
            return null;
        }

        if (preg_match('/^\d{4}$/', $year) !== 1) {
            return null;
        }

        if (trim($sequence) === '') {
            return null;
        }

        return MedicalRecordNumberParts::make(self::PREFIX, $branchCode, $year, $sequence);
    }

    /**
     * The branch code encoded in a Nomor RM, or null when the value is not a
     * canonical medical record number.
     *
     * REVISION-TELKOMAS-BRANCH-CODE-TKM1-TO-TLK1-1 — the returned code is
     * CANONICAL. A Nomor RM issued under a deprecated branch code (`DG-TKM1-…`)
     * reports the branch's current code (`TLK1`), because the question every
     * caller is really asking is "which branch is this?", and the answer is one
     * branch identity that has not changed. Callers therefore compare a single
     * spelling against `mst_branches` and against rollout allowlists, and an
     * unknown code still fails closed — see {@see BranchCodeAlias}.
     *
     * The stored Nomor RM itself is NOT rewritten by this call.
     */
    public function branchCodeFrom(?string $medicalRecordNumber): ?string
    {
        $branchCode = $this->parse($medicalRecordNumber)?->branchCode;

        return $branchCode === null ? null : BranchCodeAlias::canonicalize($branchCode);
    }

    /**
     * The branch code EXACTLY as it is spelled in the Nomor RM, without alias
     * resolution.
     *
     * This is the historical fact — what the number literally says — and is used
     * where the distinction matters: reporting that a value is out of date, and
     * migrating it. Prefer {@see self::branchCodeFrom()} for "which branch?".
     */
    public function literalBranchCodeFrom(?string $medicalRecordNumber): ?string
    {
        return $this->parse($medicalRecordNumber)?->branchCode;
    }

    /**
     * Rewrite ONLY the branch-code segment of a Nomor RM to its canonical form.
     *
     * `DG-TKM1-2024-9985` → `DG-TLK1-2024-9985`. The prefix, the year and the
     * manual sequence are carried through verbatim, so a number is never
     * regenerated and a sequence is never renumbered.
     *
     * This goes through parse()/compose() rather than a string replacement on
     * purpose. A blind `replace('TKM1','TLK1')` would also rewrite a `TKM1`
     * appearing INSIDE a manual sequence, and would happily corrupt a value that
     * is not a Nomor RM at all. Anything that is not exactly
     * `DG-{KODE}-{TAHUN}-{NOMOR}` returns null and must be left untouched.
     *
     * Returns the value unchanged (not null) when it is already canonical, so
     * the method is idempotent: canonicalize(canonicalize($x)) === canonicalize($x).
     */
    public function canonicalizeBranchCode(?string $medicalRecordNumber): ?string
    {
        $parts = $this->parse($medicalRecordNumber);

        if ($parts === null) {
            return null;
        }

        $canonical = BranchCodeAlias::canonicalize($parts->branchCode);

        if ($canonical === null) {
            return null;
        }

        return $this->compose($canonical, $parts->year, $parts->sequence);
    }

    /**
     * Every spelling of a Nomor RM that names the same patient record — the
     * canonical form first, then the deprecated forms.
     *
     * A patient's card printed before a branch-code revision carries the old
     * spelling. Looking that number up must still find them, so search widens
     * over these values rather than the operator being told the number does not
     * exist. Non-canonical input yields the trimmed input alone: this never
     * invents variants for a value it does not understand.
     *
     * @return list<string>
     */
    public function equivalentNumbers(?string $medicalRecordNumber): array
    {
        $value = trim((string) $medicalRecordNumber);

        if ($value === '') {
            return [];
        }

        $parts = $this->parse($value);

        if ($parts === null) {
            return [$value];
        }

        $numbers = array_map(
            fn (string $code): string => $this->compose($code, $parts->year, $parts->sequence),
            BranchCodeAlias::equivalentCodes($parts->branchCode),
        );

        // The value as supplied always stays in the set: a caller searching for
        // exactly what the operator typed must never lose that candidate.
        return array_values(array_unique(array_merge($numbers, [$value])));
    }

    /**
     * Rule 244 §1 — the minimum width of an automatic sequence.
     */
    public const SEQUENCE_PAD_WIDTH = 4;

    /**
     * Rule 244 §1 — the widest numeric sequence the allocator and the seeding
     * report read or spell (the allocator refuses at nine digits).
     */
    public const MAX_NUMERIC_DIGITS = 18;

    /**
     * Rule 244 §1 — an automatic sequence, zero-padded to four digits and never
     * truncated: 7 → "0007", 12345 → "12345".
     */
    public function formatSequence(int $number): string
    {
        if ($number < 1) {
            throw new InvalidArgumentException('An automatic RM sequence starts at 1.');
        }

        return str_pad((string) $number, self::SEQUENCE_PAD_WIDTH, '0', STR_PAD_LEFT);
    }

    /**
     * Rule 244 §1 — the numeric value of a canonical Nomor RM's sequence
     * ("0007" and "7" are both 7), or null when the value is not canonical or its
     * sequence is not purely numeric ("7A", "REG-4").
     */
    public function numericSequenceOf(?string $medicalRecordNumber): ?int
    {
        $sequence = $this->parse($medicalRecordNumber)?->sequence;

        if ($sequence === null || preg_match('/^\d{1,'.self::MAX_NUMERIC_DIGITS.'}$/', $sequence) !== 1) {
            return null;
        }

        return (int) $sequence;
    }

    /**
     * Rule 244 §1 — every spelling under which sequence `$number` may already have
     * been issued for this branch and year: each equivalent branch code (the
     * canonical code and its historical aliases, {@see BranchCodeAlias}) times
     * every zero-padding width from the bare digits up to
     * {@see self::MAX_NUMERIC_DIGITS} digits. A number is taken when ANY of these
     * exists, so the allocator never reissues `0007` because `7` or
     * `DG-TKM1-…-0007` is already on a card. (Patients are compared by numeric
     * VALUE in any width — {@see self::numericValueOf()}; the explicit spellings
     * also serve the merge-alias lookup, which compares exact strings.)
     *
     * @return list<string>
     */
    public function numericSpellings(string $branchCode, int|string $year, int $number): array
    {
        if ($number < 1) {
            throw new InvalidArgumentException('An automatic RM sequence starts at 1.');
        }

        $digits = (string) $number;
        $widest = max(self::MAX_NUMERIC_DIGITS, strlen($digits));
        $spellings = [];

        foreach (BranchCodeAlias::equivalentCodes($branchCode) as $code) {
            for ($width = strlen($digits); $width <= $widest; $width++) {
                $spellings[] = $this->compose($code, $year, str_pad($digits, $width, '0', STR_PAD_LEFT));
            }
        }

        return array_values(array_unique($spellings));
    }

    /**
     * Rule 244 §2.5 — every spelling under which `$medicalRecordNumber`, or a
     * number naming the same branch, year and sequence, may already be held: the
     * value as given and — when it reads as a Nomor RM once uppercased — each
     * equivalent branch code times every zero padding for a numeric sequence
     * ({@see self::numericSpellings()}), or each equivalent branch code with the
     * sequence verbatim otherwise ({@see self::equivalentNumbers()}). Callers
     * compare these case-insensitively.
     *
     * @return list<string>
     */
    public function collisionSpellings(?string $medicalRecordNumber): array
    {
        $value = trim((string) $medicalRecordNumber);

        if ($value === '') {
            return [];
        }

        $upper = strtoupper($value);
        $parts = $this->parse($upper);
        $spellings = [$value];

        if ($parts !== null) {
            $sequence = $this->numericSequenceOf($upper);

            $spellings = array_merge($spellings, $sequence !== null && $sequence >= 1
                ? $this->numericSpellings($parts->branchCode, $parts->year, $sequence)
                : $this->equivalentNumbers($upper));
        }

        return array_values(array_unique($spellings));
    }

    /**
     * Rule 244 §2.5 — one comparison key per Nomor RM identity: `DG-TKM1-2026-7`,
     * `DG-TLK1-2026-0007` and `dg-tlk1-2026-0007` share a key; a value that is not
     * a canonical number with a numeric sequence keys on its uppercased self.
     */
    public function collisionKey(?string $medicalRecordNumber): string
    {
        $upper = strtoupper(trim((string) $medicalRecordNumber));
        $parts = $this->parse($upper);
        $value = $this->numericValueOf($upper);

        if ($parts === null || $value === null) {
            return $upper;
        }

        return self::PREFIX.'-'.BranchCodeAlias::canonicalize($parts->branchCode).'-'.$parts->year.'-#'.$value;
    }

    /**
     * Rule 244 §2.5 (PR-2 review F1) — the numeric VALUE of a canonical Nomor RM's
     * all-digit sequence, in ANY width, as a digit string without leading zeros
     * (`0006`, `6` and `000000000006` are all "6"); null when the value is not a
     * canonical number, its sequence is not all digits, or it is zero. This is
     * the one notion of "the same number" that {@see collisionKey()}, the
     * repository's taken query and the allocator share, so no zero padding —
     * however wide — escapes the comparison.
     */
    public function numericValueOf(?string $medicalRecordNumber): ?string
    {
        $sequence = $this->parse(strtoupper(trim((string) $medicalRecordNumber)))?->sequence;

        if ($sequence === null || preg_match('/^[0-9]+$/', $sequence) !== 1) {
            return null;
        }

        $value = ltrim($sequence, '0');

        return $value === '' ? null : $value;
    }

    /**
     * Whether the medical record number is already taken — rule 244 §2.5: in ANY
     * spelling ({@see self::collisionSpellings()}), case-insensitively, by any
     * patient (soft-deleted ones included) or by an active merge alias. The one
     * answer is {@see PatientRmNumberAllocator::isTaken()}, resolved at call time
     * because the allocator itself depends on this service.
     *
     * Advisory on its own: a writer that stores such a number claims it under the
     * branch-year counter lock ({@see PatientRmNumberAllocator::claimExisting()}).
     * The former `$ignoreId` argument went with the edit path, which no longer
     * changes a number (rule 244 §2.3).
     */
    public function exists(string $medicalRecordNumber): bool
    {
        return app(PatientRmNumberAllocator::class)->isTaken($medicalRecordNumber);
    }

    private function normalizeYear(int|string $year): string
    {
        $year = trim((string) $year);

        if (! preg_match('/^\d{4}$/', $year)) {
            throw new InvalidArgumentException('Registration year must be exactly four digits.');
        }

        return $year;
    }
}
