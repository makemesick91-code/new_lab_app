<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Services;

use App\Modules\LegacyRme\Support\LegacyRmeDateRuleResult;
use App\Modules\Patient\Models\Patient;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * LEGACY-RME-PDF-1A — the legacy RME date rules.
 *
 * One reusable domain rule set for every stage of the legacy archive workflow:
 * draft creation, upload, review, publish and any later revalidation. No other
 * layer may re-derive these bounds.
 *
 * THE RULES (evaluated in this order):
 *  1. The patient must have a native RME to compare against. A patient with no
 *     native RME is refused in regular import mode — a dedicated migration mode
 *     for such patients is out of scope and must never be a hidden exception.
 *  2. selected date < earliest native RME date  (STRICTLY before; an equal date
 *     is refused, because the legacy archive must not overlap a real encounter).
 *  3. selected date < today                     (an archive is historical;
 *     today and any future date are refused).
 *  4. patient birth date <= selected date       (only checked when the patient
 *     actually has a birth date — the column is nullable by design and this
 *     service never invents one). A legacy date equal to the birth date is
 *     accepted; earlier is not.
 *
 * The "today" boundary is evaluated in the configured clinical timezone, which
 * defaults to the application timezone — the same wall clock the RME workflow
 * uses when it stamps trx_clinic_visits.visit_date, so the legacy date and the
 * native cutoff are always compared in one frame.
 */
class LegacyRmeDateRuleService
{
    public const CODE_PATIENT_HAS_NO_NATIVE_RME = 'PATIENT_HAS_NO_NATIVE_RME';

    public const CODE_LEGACY_DATE_NOT_BEFORE_NATIVE_RME = 'LEGACY_DATE_NOT_BEFORE_NATIVE_RME';

    public const CODE_LEGACY_DATE_IN_FUTURE = 'LEGACY_DATE_IN_FUTURE';

    public const CODE_LEGACY_DATE_BEFORE_PATIENT_BIRTH = 'LEGACY_DATE_BEFORE_PATIENT_BIRTH';

    public const CODE_LEGACY_DATE_INVALID = 'LEGACY_DATE_INVALID';

    /** @var list<string> */
    public const CODES = [
        self::CODE_PATIENT_HAS_NO_NATIVE_RME,
        self::CODE_LEGACY_DATE_NOT_BEFORE_NATIVE_RME,
        self::CODE_LEGACY_DATE_IN_FUTURE,
        self::CODE_LEGACY_DATE_BEFORE_PATIENT_BIRTH,
        self::CODE_LEGACY_DATE_INVALID,
    ];

    /** Field name used when the rule failure is surfaced as a validation error. */
    public const FIELD = 'selected_rme_date';

    public function __construct(
        private readonly PatientEarliestNativeRmeDateResolver $earliestNativeRmeDate,
    ) {}

    /**
     * Evaluate every rule for a patient + a manually chosen legacy date.
     *
     * The earliest native RME date is always recomputed server-side here; a
     * caller-supplied snapshot is never trusted as an input to the decision.
     */
    public function evaluate(Patient $patient, CarbonImmutable|string|null $selectedDate): LegacyRmeDateRuleResult
    {
        $patientId = (int) $patient->getKey();
        $selected = $this->normalize($selectedDate);

        if ($selected === null) {
            return LegacyRmeDateRuleResult::fail(
                self::CODE_LEGACY_DATE_INVALID,
                'Tanggal RME lama tidak valid. Pilih tanggal sesuai yang tertera pada dokumen.',
                ['patient_id' => $patientId],
            );
        }

        $earliestNative = $this->earliestNativeRmeDate->resolve($patientId);
        $today = $this->today();
        $birthDate = $this->normalize($patient->date_of_birth);

        $context = [
            'patient_id' => $patientId,
            'selected_rme_date' => $selected->toDateString(),
            'earliest_native_rme_date' => $earliestNative?->toDateString(),
            'today' => $today->toDateString(),
            'clinical_timezone' => $this->timezone(),
        ];

        if ($this->requiresNativeReference() && $earliestNative === null) {
            return LegacyRmeDateRuleResult::fail(
                self::CODE_PATIENT_HAS_NO_NATIVE_RME,
                'Pasien ini belum memiliki rekam medis yang dibuat melalui sistem, sehingga tidak ada tanggal RME pembanding. Impor arsip RME lama belum dapat dilakukan.',
                $context,
            );
        }

        if ($earliestNative !== null && $this->requireStrictlyBeforeNative() && ! $selected->lessThan($earliestNative)) {
            return LegacyRmeDateRuleResult::fail(
                self::CODE_LEGACY_DATE_NOT_BEFORE_NATIVE_RME,
                sprintf(
                    'Tanggal RME lama (%s) harus lebih awal dari tanggal RME pertama di sistem (%s).',
                    $selected->format('d-m-Y'),
                    $earliestNative->format('d-m-Y'),
                ),
                $context,
            );
        }

        if ($this->requireStrictlyBeforeToday() && ! $selected->lessThan($today)) {
            return LegacyRmeDateRuleResult::fail(
                self::CODE_LEGACY_DATE_IN_FUTURE,
                sprintf(
                    'Tanggal RME lama (%s) harus lebih awal dari hari ini (%s).',
                    $selected->format('d-m-Y'),
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
     * @throws ValidationException
     */
    public function assert(Patient $patient, CarbonImmutable|string|null $selectedDate, string $field = self::FIELD): LegacyRmeDateRuleResult
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
        return $this->earliestNativeRmeDate->resolveAsDateString((int) $patient->getKey());
    }

    public function today(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone())->startOfDay();
    }

    public function timezone(): string
    {
        $timezone = config('legacy_rme.dates.clinical_timezone');

        return is_string($timezone) && $timezone !== ''
            ? $timezone
            : (string) config('app.timezone', 'UTC');
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

    private function requiresNativeReference(): bool
    {
        return (bool) config('legacy_rme.dates.require_native_reference', true);
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
