<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Services;

use App\Modules\LegacyRme\Support\LegacyRmeDateRuleResult;
use App\Modules\Patient\Models\Patient;
use App\Support\Clinical\ClinicalClock;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * LEGACY-RME-PDF-1A — the legacy RME date rules.
 *
 * One reusable domain rule set for every stage of the legacy archive workflow:
 * draft creation, upload, review, publish and any later revalidation. No other
 * layer may re-derive these bounds.
 *
 * A DOCUMENT IS A DATE RANGE, NOT A DATE (LEGACY-RME-PDF-FIX-ROLL2-1). One
 * historical PDF often carries several clinical dates. The operator therefore
 * declares the EARLIEST and the LATEST clinical date the document shows:
 *
 *  - `selected_rme_date` is the REPRESENTATIVE date and is always the EARLIEST
 *    one. It is what the patient's history displays and what is persisted as
 *    the record's `rme_date`.
 *  - `latest_rme_date` is the SAFETY BOUNDARY. A single-date document simply
 *    repeats the same value.
 *
 * Validating only the representative date would be unsafe: a document whose
 * earliest entry predates the native RME but whose latest entry falls after it
 * would slip through and hide a mixed legacy/native chronology behind its
 * oldest date. So the whole range is checked.
 *
 * THE RULES (evaluated in this order):
 *  1. Both dates must be real calendar dates, and earliest <= latest.
 *  2. WHEN the patient has a native RME, EVERY date the document represents
 *     must be STRICTLY earlier than it — i.e. `latest < earliest native RME`
 *     (an equal date is refused, because equal is exactly the overlap case).
 *     WHEN the patient has NO native RME, there is simply no such bound. That
 *     is a normal, expected migration case and NOT an error: most patients
 *     carried over from the old system have no native RME at all. The absence
 *     of a native reference is recorded as `NO_NATIVE_REFERENCE` evidence, and
 *     a native encounter is NEVER manufactured to create a boundary.
 *  3. latest date < today                       (an archive is historical;
 *     today and any future date are refused).
 *  4. patient birth date <= earliest date       (only checked when the patient
 *     actually has a birth date — the column is nullable by design and this
 *     service never invents one). A legacy date equal to the birth date is
 *     accepted; earlier is not.
 *
 * LEGACY-RME-DATE-TZ-1 — the "today" boundary is evaluated by ClinicalClock,
 * the single canonical clinical calendar authority (Asia/Makassar), NOT by the
 * application/PHP/OS timezone. That distinction is load-bearing: between 16:00
 * and 24:00 UTC the clinic is already living the next calendar day, so a
 * UTC-anchored "today" refused documents that were genuinely historical and
 * made the same document produce different answers depending only on the hour
 * it was submitted.
 *
 * Only rule 3 consults a clock at all. Rules 2 and 4 compare stored calendar
 * DATES against each other and are timezone-invariant by construction — the
 * dates a human read off a document are never shifted into another zone.
 *
 * The same clock instance backs upload validation and publish revalidation
 * (LegacyRmePublishService::assertDateStillValid re-enters evaluate()), so the
 * two stages can never disagree about where the day boundary is. Real time may
 * still legitimately advance between them; that is a clock moving forward, not
 * two clocks disagreeing.
 */
class LegacyRmeDateRuleService
{
    public const CODE_LEGACY_DATE_NOT_BEFORE_NATIVE_RME = 'LEGACY_DATE_NOT_BEFORE_NATIVE_RME';

    public const CODE_LEGACY_DATE_IN_FUTURE = 'LEGACY_DATE_IN_FUTURE';

    public const CODE_LEGACY_DATE_BEFORE_PATIENT_BIRTH = 'LEGACY_DATE_BEFORE_PATIENT_BIRTH';

    public const CODE_LEGACY_DATE_INVALID = 'LEGACY_DATE_INVALID';

    public const CODE_LEGACY_DATE_RANGE_INVALID = 'LEGACY_DATE_RANGE_INVALID';

    /** @var list<string> */
    public const CODES = [
        self::CODE_LEGACY_DATE_NOT_BEFORE_NATIVE_RME,
        self::CODE_LEGACY_DATE_IN_FUTURE,
        self::CODE_LEGACY_DATE_BEFORE_PATIENT_BIRTH,
        self::CODE_LEGACY_DATE_INVALID,
        self::CODE_LEGACY_DATE_RANGE_INVALID,
    ];

    /**
     * Which reference the decision was made against. These are EVIDENCE
     * markers, never failures — `NO_NATIVE_REFERENCE` is a valid outcome.
     */
    public const REFERENCE_MODE_BEFORE_NATIVE_RME = 'BEFORE_NATIVE_RME';

    public const REFERENCE_MODE_NO_NATIVE_REFERENCE = 'NO_NATIVE_REFERENCE';

    /** @var list<string> */
    public const REFERENCE_MODES = [
        self::REFERENCE_MODE_BEFORE_NATIVE_RME,
        self::REFERENCE_MODE_NO_NATIVE_REFERENCE,
    ];

    /** Field name used when the rule failure is surfaced as a validation error. */
    public const FIELD = 'selected_rme_date';

    /** Field carrying the latest clinical date the document represents. */
    public const FIELD_LATEST = 'latest_rme_date';

    public function __construct(
        private readonly PatientEarliestNativeRmeDateResolver $earliestNativeRmeDate,
        private readonly ClinicalClock $clock,
    ) {}

    /**
     * Evaluate every rule for a patient + the clinical date range the operator
     * read on the document.
     *
     * `$latestDate` is optional: omitting it means the document represents a
     * single clinical date, so the range collapses to `[selected, selected]`.
     * The earliest native RME date is always recomputed server-side here; a
     * caller-supplied snapshot is never trusted as an input to the decision.
     */
    public function evaluate(
        Patient $patient,
        CarbonImmutable|string|null $selectedDate,
        CarbonImmutable|string|null $latestDate = null,
    ): LegacyRmeDateRuleResult {
        $patientId = (int) $patient->getKey();
        $selected = $this->normalize($selectedDate);

        if ($selected === null) {
            return LegacyRmeDateRuleResult::fail(
                self::CODE_LEGACY_DATE_INVALID,
                'Tanggal RME lama tidak valid. Pilih tanggal sesuai yang tertera pada dokumen.',
                ['patient_id' => $patientId],
            );
        }

        // A blank latest date is a single-date document, not a missing input.
        // A NON-blank but unparseable one is a real error and must not silently
        // collapse into the earliest date, which would drop the safety bound.
        $latestProvided = $latestDate !== null && $latestDate !== '';
        $latest = $latestProvided ? $this->normalize($latestDate) : $selected;

        if ($latest === null) {
            return LegacyRmeDateRuleResult::fail(
                self::CODE_LEGACY_DATE_INVALID,
                'Tanggal RME terakhir pada dokumen tidak valid. Pilih tanggal sesuai yang tertera pada dokumen.',
                ['patient_id' => $patientId, 'selected_rme_date' => $selected->toDateString()],
            );
        }

        $earliestNative = $this->earliestNativeRmeDate->resolve($patientId);
        $today = $this->today();
        $birthDate = $this->normalize($patient->date_of_birth);

        $context = [
            'patient_id' => $patientId,
            'selected_rme_date' => $selected->toDateString(),
            'latest_rme_date' => $latest->toDateString(),
            'earliest_native_rme_date' => $earliestNative?->toDateString(),
            'reference_mode' => $earliestNative !== null
                ? self::REFERENCE_MODE_BEFORE_NATIVE_RME
                : self::REFERENCE_MODE_NO_NATIVE_REFERENCE,
            'today' => $today->toDateString(),
            'clinical_timezone' => $this->timezone(),
        ];

        // The representative date is the OLDEST date on the document, so a
        // range that runs backwards is an operator input error.
        if ($selected->greaterThan($latest)) {
            return LegacyRmeDateRuleResult::fail(
                self::CODE_LEGACY_DATE_RANGE_INVALID,
                sprintf(
                    'Tanggal RME paling awal (%s) tidak boleh lebih baru dari tanggal RME paling akhir (%s).',
                    $selected->format('d-m-Y'),
                    $latest->format('d-m-Y'),
                ),
                $context,
            );
        }

        // A patient with NO native RME has no upper bound to compare against.
        // That is valid — legacy migration is exactly the case where the
        // patient's whole history predates this system.
        if ($earliestNative !== null && $this->requireStrictlyBeforeNative() && ! $latest->lessThan($earliestNative)) {
            return LegacyRmeDateRuleResult::fail(
                self::CODE_LEGACY_DATE_NOT_BEFORE_NATIVE_RME,
                sprintf(
                    'Seluruh tanggal RME pada dokumen harus lebih awal dari tanggal RME pertama di sistem (%s). Tanggal RME paling akhir pada dokumen adalah %s.',
                    $earliestNative->format('d-m-Y'),
                    $latest->format('d-m-Y'),
                ),
                $context,
            );
        }

        if ($this->requireStrictlyBeforeToday() && ! $latest->lessThan($today)) {
            return LegacyRmeDateRuleResult::fail(
                self::CODE_LEGACY_DATE_IN_FUTURE,
                sprintf(
                    'Tanggal RME pada dokumen (%s) harus lebih awal dari hari ini (%s).',
                    $latest->format('d-m-Y'),
                    $today->format('d-m-Y'),
                ),
                $context,
            );
        }

        $birthDateViolated = $birthDate !== null && ($this->allowSameDayAsBirthDate()
            ? $selected->lessThan($birthDate)
            : ! $selected->greaterThan($birthDate));

        if ($birthDateViolated) {
            return LegacyRmeDateRuleResult::fail(
                self::CODE_LEGACY_DATE_BEFORE_PATIENT_BIRTH,
                sprintf(
                    'Tanggal RME lama (%s) tidak boleh mendahului tanggal lahir pasien (%s).',
                    $selected->format('d-m-Y'),
                    $birthDate->format('d-m-Y'),
                ),
                $context + ['patient_birth_date' => $birthDate->toDateString()],
            );
        }

        return LegacyRmeDateRuleResult::pass($context);
    }

    /**
     * Evaluate and throw a ValidationException on failure — the repository's
     * standard way of surfacing a domain rule at an input boundary.
     *
     * A range failure is attached to the latest-date field so the operator is
     * pointed at the input that actually has to change.
     *
     * @throws ValidationException
     */
    public function assert(
        Patient $patient,
        CarbonImmutable|string|null $selectedDate,
        string $field = self::FIELD,
        CarbonImmutable|string|null $latestDate = null,
    ): LegacyRmeDateRuleResult {
        $result = $this->evaluate($patient, $selectedDate, $latestDate);

        if ($result->failed()) {
            throw ValidationException::withMessages([
                $this->fieldFor($result, $field) => $result->message,
            ]);
        }

        return $result;
    }

    /**
     * Which input a failure belongs to.
     *
     * The range-wide rules are about the LATEST date, so they are attached to
     * that field — but only when the document actually declares a distinct
     * one. For a single-date document there is no second input to correct, and
     * pointing at an empty field the operator never filled in would just be
     * confusing, so the message stays on the representative date.
     */
    private function fieldFor(LegacyRmeDateRuleResult $result, string $defaultField): string
    {
        $isRangeRule = in_array((string) $result->code, [
            self::CODE_LEGACY_DATE_RANGE_INVALID,
            self::CODE_LEGACY_DATE_NOT_BEFORE_NATIVE_RME,
            self::CODE_LEGACY_DATE_IN_FUTURE,
        ], true);

        $selected = $result->context['selected_rme_date'] ?? null;
        $latest = $result->context['latest_rme_date'] ?? null;

        return ($isRangeRule && $latest !== null && $latest !== $selected)
            ? self::FIELD_LATEST
            : $defaultField;
    }

    /**
     * The server-computed cutoff to persist alongside a staged import. Never
     * accept this value from the client.
     */
    public function snapshotCutoff(Patient $patient): ?string
    {
        return $this->earliestNativeRmeDate->resolveAsDateString((int) $patient->getKey());
    }

    /**
     * Today's CLINICAL calendar date.
     *
     * Delegated to ClinicalClock — this service must never re-derive the
     * clinical day. The previous inline `config('app.timezone', 'UTC')`
     * fallback is deliberately gone: it silently resolved to UTC in production
     * and moved this boundary by eight hours.
     */
    public function today(): CarbonImmutable
    {
        return $this->clock->today();
    }

    public function timezone(): string
    {
        return $this->clock->timezone();
    }

    private function normalize(\DateTimeInterface|string|null $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            $date = $value instanceof \DateTimeInterface
                ? CarbonImmutable::instance($value)
                : CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }

        // Compare calendar dates only — never partial-day timestamps.
        return CarbonImmutable::parse($date->toDateString())->startOfDay();
    }

    private function requireStrictlyBeforeNative(): bool
    {
        return (bool) config('legacy_rme.dates.require_strictly_before_native', true);
    }

    private function requireStrictlyBeforeToday(): bool
    {
        return (bool) config('legacy_rme.dates.require_strictly_before_today', true);
    }

    private function allowSameDayAsBirthDate(): bool
    {
        return (bool) config('legacy_rme.dates.allow_same_day_as_birth_date', true);
    }
}
