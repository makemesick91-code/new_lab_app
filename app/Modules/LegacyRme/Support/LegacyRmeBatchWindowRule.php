<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Support;

use App\Support\Clinical\ClinicalClock;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The planned window a routine legacy RME migration batch is bounded by.
 *
 * WHY THIS IS A SHARED DOMAIN RULE AND NOT FORM VALIDATION
 *
 * A routine batch is time-bounded: an approval that never expires is one
 * nobody revisits. That invariant used to live only in
 * `StoreLegacyRmeMigrationWaveRequest`, which made the HTTP form the sole
 * guard — and `legacy-rme:wave-admin register` reaches
 * `LegacyRmeWaveGovernanceService::createWave()` without ever passing through
 * a FormRequest. The CLI was therefore a strictly weaker entry point, and a
 * batch opened over SSH could not satisfy a rule the runbook mandates.
 *
 * That is the same reasoning the wave FormRequest already records for the
 * branch set: putting a governance decision in the request layer means "a
 * second caller (the CLI) would bypass it entirely". The dates were the case
 * that had been left behind. This class is where the rule now lives, so both
 * callers are bound by one implementation rather than two that can drift.
 *
 * CALENDAR SEMANTICS
 *
 * Dates are calendar days on the clinic's canonical calendar, never server
 * UTC instants. A window is inclusive of its final planned day: a batch
 * approved "through the 20th" is open on the 20th, which is exactly how
 * `LegacyRmeSteadyStateOpsService::checkBatchWindow()` compares it later.
 *
 * WHAT THIS CLASS DELIBERATELY DOES NOT DO
 *
 * It does not compare the window against today. A batch may legitimately be
 * registered ahead of its start date, and a lapsed window is a readiness
 * finding (`batch_window`), not a reason to refuse the write. Nor does it
 * classify a batch as "routine" versus "engineering": the engineering rollout
 * programme is closed, so every newly registered batch is a routine batch,
 * and inventing a second classification would only create a way around the
 * rule.
 *
 * Historical batches are untouched. This is a creation-time rule, so waves
 * registered before it existed keep their null dates and stay readable and
 * auditable. Nothing is backfilled.
 */
final class LegacyRmeBatchWindowRule
{
    public const FIELD_START = 'planned_start_date';

    public const FIELD_END = 'planned_end_date';

    /**
     * The canonical wire format. Kept strict on purpose: `Carbon::parse()`
     * accepts things like "next tuesday" and "20-08-2026", and a governance
     * window that silently reinterprets what an operator typed is worse than
     * one that refuses it.
     */
    private const FORMAT = 'Y-m-d';

    public function __construct(private readonly ClinicalClock $clock) {}

    /**
     * Normalise and assert a planned batch window.
     *
     * Returns the canonical `Y-m-d` strings so callers persist exactly what
     * was validated rather than the raw input.
     *
     * @return array{planned_start_date: string|null, planned_end_date: string|null}
     *
     * @throws ValidationException
     */
    public function normalize(?string $start, ?string $end, ?bool $required = null): array
    {
        $required ??= self::requiredByPolicy();

        $startDate = $this->parse($start, self::FIELD_START);
        $endDate = $this->parse($end, self::FIELD_END);

        if ($required && $startDate === null) {
            throw ValidationException::withMessages([
                self::FIELD_START => 'Tanggal mulai batch wajib diisi. Batch rutin harus dibatasi waktu.',
            ]);
        }

        if ($required && $endDate === null) {
            throw ValidationException::withMessages([
                self::FIELD_END => 'Tanggal berakhir batch wajib diisi. Persetujuan tanpa tanggal berakhir tidak pernah kedaluwarsa.',
            ]);
        }

        // Ordering is checked whenever both ends are present, including when
        // the window is optional — a reversed window is malformed either way.
        if ($startDate !== null && $endDate !== null && $endDate->lt($startDate)) {
            throw ValidationException::withMessages([
                self::FIELD_END => 'Tanggal berakhir batch tidak boleh lebih awal dari tanggal mulai.',
            ]);
        }

        return [
            self::FIELD_START => $startDate?->format(self::FORMAT),
            self::FIELD_END => $endDate?->format(self::FORMAT),
        ];
    }

    /**
     * Whether a newly registered batch must declare a bounded window.
     *
     * Config-driven and defaulting to true so the invariant is explicit and
     * auditable rather than buried in code, and so a deployment that has a
     * documented reason to differ has to say so out loud.
     */
    public static function requiredByPolicy(): bool
    {
        return (bool) config('legacy_rme_operations.routine_batch_window.required', true);
    }

    /**
     * @throws ValidationException
     */
    private function parse(?string $value, string $field): ?CarbonImmutable
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        try {
            $parsed = CarbonImmutable::createFromFormat(self::FORMAT, $value, $this->clock->timezoneObject());
        } catch (Throwable) {
            $parsed = false;
        }

        // createFromFormat is lenient about overflow — "2026-02-31" rolls into
        // March rather than failing — so the round trip is what actually
        // rejects a date that does not exist on the calendar.
        if (! $parsed instanceof CarbonImmutable || $parsed->format(self::FORMAT) !== $value) {
            throw ValidationException::withMessages([
                $field => 'Tanggal batch harus berupa tanggal kalender yang sah dengan format YYYY-MM-DD.',
            ]);
        }

        return $parsed->startOfDay();
    }
}
