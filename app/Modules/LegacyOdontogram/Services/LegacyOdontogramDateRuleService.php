<?php

declare(strict_types=1);

namespace App\Modules\LegacyOdontogram\Services;

use App\Modules\LegacyOdontogram\Support\LegacyOdontogramDateRuleResult;
use App\Modules\Patient\Models\Patient;
use App\Support\Clinical\ClinicalClock;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * FIX-04b — the legacy odontogram date rules.
 *
 * One reusable domain rule set for every stage of the archive workflow: upload,
 * review, publish and any later revalidation. No other layer may re-derive
 * these bounds — the publish step re-enters `evaluate()` rather than trusting
 * what the upload decided, so a chart cannot become valid by sitting in staging
 * while the patient's native history changes underneath it.
 *
 * THE DATE IS CHOSEN BY A HUMAN, FROM THE DOCUMENT. It is never the upload
 * time, never `created_at`, never the file's mtime and never PDF metadata. Those
 * describe when a piece of paper was scanned, which says nothing about when the
 * patient was charted.
 *
 * THE RULES, evaluated in this order, each with a STABLE CODE:
 *
 *  1. PATIENT_HAS_NO_NATIVE_ODONTOGRAM — the patient must already have a native
 *     odontogram. In regular mode there is otherwise no boundary to file behind,
 *     and inventing one (or waving the rule through) is how a legacy chart ends
 *     up interleaved with real examinations. A bulk migration for
 *     never-examined patients is a separate, explicit capability; this is not
 *     it, and the refusal is deliberate rather than an oversight.
 *
 *  2. LEGACY_DATE_INVALID — the value must be a real calendar date.
 *
 *  3. LEGACY_ODONTOGRAM_DATE_NOT_BEFORE_NATIVE — the date must be STRICTLY
 *     earlier than the earliest native odontogram date. Equal is REFUSED,
 *     because equal is exactly the overlap case: a chart dated the same day as
 *     a real examination is either that examination or a contradiction of it,
 *     and neither belongs in the archive.
 *
 *  4. LEGACY_DATE_IN_FUTURE — the date must be strictly before TODAY. Today
 *     itself is refused: an archive is historical by definition, and a chart
 *     drawn today is a native odontogram, not an archive.
 *
 *  5. LEGACY_DATE_BEFORE_PATIENT_BIRTH — the patient's birth date must be <=
 *     the chosen date. EQUAL to the birth date is ACCEPTED. `date_of_birth` is
 *     nullable by design, and when it is null this rule is SKIPPED — a missing
 *     birth date is never invented, and never silently treated as the epoch.
 *
 * An existing legacy row — staged, published or VOID — is never a comparison
 * point for any of these. Only a native examination bounds an archive.
 *
 * TIMEZONE. Only rule 4 consults a clock, and it does so through ClinicalClock
 * (Asia/Makassar), the single canonical clinical calendar authority — never
 * `now()`, never the PHP/OS timezone. That distinction is load-bearing: between
 * 16:00 and 24:00 UTC the clinic is already living the next calendar day, so a
 * UTC-anchored "today" refuses documents that are genuinely historical and makes
 * the same document produce different answers depending only on the hour it was
 * submitted. Rules 1, 3 and 5 compare stored calendar DATES against each other
 * and are timezone-invariant by construction.
 */
class LegacyOdontogramDateRuleService
{
    public const CODE_PATIENT_HAS_NO_NATIVE_ODONTOGRAM = 'PATIENT_HAS_NO_NATIVE_ODONTOGRAM';

    public const CODE_LEGACY_DATE_NOT_BEFORE_NATIVE_ODONTOGRAM = 'LEGACY_ODONTOGRAM_DATE_NOT_BEFORE_NATIVE';

    public const CODE_LEGACY_DATE_IN_FUTURE = 'LEGACY_ODONTOGRAM_DATE_IN_FUTURE';

    public const CODE_LEGACY_DATE_BEFORE_PATIENT_BIRTH = 'LEGACY_ODONTOGRAM_DATE_BEFORE_PATIENT_BIRTH';

    public const CODE_LEGACY_DATE_INVALID = 'LEGACY_ODONTOGRAM_DATE_INVALID';

    /** @var list<string> */
    public const CODES = [
        self::CODE_PATIENT_HAS_NO_NATIVE_ODONTOGRAM,
        self::CODE_LEGACY_DATE_NOT_BEFORE_NATIVE_ODONTOGRAM,
        self::CODE_LEGACY_DATE_IN_FUTURE,
        self::CODE_LEGACY_DATE_BEFORE_PATIENT_BIRTH,
        self::CODE_LEGACY_DATE_INVALID,
    ];

    /** Field name used when a rule failure is surfaced as a validation error. */
    public const FIELD = 'selected_odontogram_date';

    public function __construct(
        private readonly PatientEarliestNativeOdontogramDateResolver $earliestNativeOdontogramDate,
        private readonly ClinicalClock $clock,
    ) {}

    /**
     * Evaluate every rule for a patient plus the clinical date the operator read
     * on the document.
     *
     * The earliest native odontogram date is always recomputed server-side here;
     * a caller-supplied snapshot is never an input to the decision, only
     * something the intake persists afterwards as evidence.
     */
    public function evaluate(Patient $patient, \DateTimeInterface|string|null $selectedDate): LegacyOdontogramDateRuleResult
    {
        $patientId = (int) $patient->getKey();
        $selected = $this->normalize($selectedDate);

        if ($selected === null) {
            return LegacyOdontogramDateRuleResult::fail(
                self::CODE_LEGACY_DATE_INVALID,
                'Tanggal odontogram lama tidak valid. Pilih tanggal sesuai yang tertera pada dokumen.',
                ['patient_id' => $patientId],
            );
        }

        $earliestNative = $this->earliestNativeOdontogramDate->resolve($patientId);
        $today = $this->today();
        $birthDate = $this->normalize($patient->date_of_birth);

        $context = [
            'patient_id' => $patientId,
            'selected_odontogram_date' => $selected->toDateString(),
            'earliest_native_odontogram_date' => $earliestNative?->toDateString(),
            'today' => $today->toDateString(),
            'clinical_timezone' => $this->timezone(),
        ];

        if ($earliestNative === null && $this->requireNativeReference()) {
            return LegacyOdontogramDateRuleResult::fail(
                self::CODE_PATIENT_HAS_NO_NATIVE_ODONTOGRAM,
                'Pasien ini belum memiliki odontogram di sistem, sehingga arsip odontogram lama belum dapat diarsipkan. Lakukan pemeriksaan odontogram terlebih dahulu.',
                $context,
            );
        }

        // Strictly earlier. `! lessThan` refuses an EQUAL date on purpose.
        if ($earliestNative !== null
            && $this->requireStrictlyBeforeNative()
            && ! $selected->lessThan($earliestNative)) {
            return LegacyOdontogramDateRuleResult::fail(
                self::CODE_LEGACY_DATE_NOT_BEFORE_NATIVE_ODONTOGRAM,
                sprintf(
                    'Tanggal odontogram lama (%s) harus lebih awal dari odontogram pertama di sistem (%s).',
                    $selected->format('d-m-Y'),
                    $earliestNative->format('d-m-Y'),
                ),
                $context,
            );
        }

        if ($this->requireStrictlyBeforeToday() && ! $selected->lessThan($today)) {
            return LegacyOdontogramDateRuleResult::fail(
                self::CODE_LEGACY_DATE_IN_FUTURE,
                sprintf(
                    'Tanggal odontogram lama (%s) harus lebih awal dari hari ini (%s).',
                    $selected->format('d-m-Y'),
                    $today->format('d-m-Y'),
                ),
                $context,
            );
        }

        // A null birth date SKIPS this rule entirely. The column is nullable by
        // design and this service never invents a value to compare against.
        $birthDateViolated = $birthDate !== null && ($this->allowSameDayAsBirthDate()
            ? $selected->lessThan($birthDate)
            : ! $selected->greaterThan($birthDate));

        if ($birthDateViolated) {
            return LegacyOdontogramDateRuleResult::fail(
                self::CODE_LEGACY_DATE_BEFORE_PATIENT_BIRTH,
                sprintf(
                    'Tanggal odontogram lama (%s) tidak boleh mendahului tanggal lahir pasien (%s).',
                    $selected->format('d-m-Y'),
                    $birthDate->format('d-m-Y'),
                ),
                $context + ['patient_birth_date' => $birthDate->toDateString()],
            );
        }

        return LegacyOdontogramDateRuleResult::pass($context);
    }

    /**
     * Evaluate and throw a ValidationException on failure — the standard way of
     * surfacing a domain rule at an input boundary.
     *
     * @throws ValidationException
     */
    public function assert(Patient $patient, \DateTimeInterface|string|null $selectedDate, string $field = self::FIELD): LegacyOdontogramDateRuleResult
    {
        $result = $this->evaluate($patient, $selectedDate);

        if ($result->failed()) {
            throw ValidationException::withMessages([
                $field => $result->message,
            ]);
        }

        return $result;
    }

    /**
     * The server-computed cutoff to persist alongside a staged import. Never
     * accept this value from the client.
     */
    public function snapshotCutoff(Patient $patient): ?string
    {
        return $this->earliestNativeOdontogramDate->resolveAsDateString((int) $patient->getKey());
    }

    /**
     * Today's CLINICAL calendar date.
     *
     * Delegated to ClinicalClock; this service must never re-derive the clinical
     * day, and must never fall back to the application timezone — that silently
     * resolves to UTC in production and moves this boundary by eight hours.
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

    private function requireNativeReference(): bool
    {
        return (bool) config('legacy_odontogram.dates.require_native_odontogram_reference', true);
    }

    private function requireStrictlyBeforeNative(): bool
    {
        return (bool) config('legacy_odontogram.dates.require_strictly_before_native', true);
    }

    private function requireStrictlyBeforeToday(): bool
    {
        return (bool) config('legacy_odontogram.dates.require_strictly_before_today', true);
    }

    private function allowSameDayAsBirthDate(): bool
    {
        return (bool) config('legacy_odontogram.dates.allow_same_day_as_birth_date', true);
    }
}
