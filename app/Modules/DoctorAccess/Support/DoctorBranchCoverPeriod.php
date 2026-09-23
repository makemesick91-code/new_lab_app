<?php

declare(strict_types=1);

namespace App\Modules\DoctorAccess\Support;

use App\Modules\DoctorAccess\Models\DoctorBranchCover;
use App\Support\Clinical\ClinicalClock;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1 — the cover window, interpreted
 * once and correctly.
 *
 * THE TIMEZONE RULE, AND WHY IT NEEDS ITS OWN CLASS.
 *
 * An operator types "2026-09-12 08:00" meaning eight in the morning at the
 * clinic. Three different answers are available to a careless reader:
 * `date_default_timezone_get()` (the OS zone of whichever machine is running),
 * `config('app.timezone')` (hard-coded UTC), and a request header (attacker
 * controlled). All three are wrong. The clinic's canonical calendar timezone is
 * {@see ClinicalClock}, and it is the ONLY zone this class interprets input in.
 *
 * THE STORAGE RULE, WHICH IS A SEPARATE TRAP. Eloquent renders a Carbon into a
 * timestamp column using the timezone that Carbon is carrying, NOT the
 * application timezone: `Model::fromDateTime()` preserves the incoming zone and
 * then formats. Handing it a WITA instance would therefore persist the WITA
 * wall clock into a column every reader parses as UTC, silently shifting every
 * cover by the offset. So the period is interpreted in the clinical zone and
 * immediately normalised to UTC, and {@see self::starts()} / {@see self::ends()}
 * — the values that get written — are always UTC. The clinical wall clock is
 * reconstructed only for human-readable messages.
 *
 * THE INTERVAL IS HALF-OPEN, [starts_at, ends_at). A cover that ends at 17:00
 * does not cover 17:00, so a back-to-back handover is legal and two adjacent
 * covers are never both current for a single instant. Every predicate in this
 * sprint — the SQL in the repository, {@see DoctorBranchCover::coversInstant()},
 * {@see DoctorBranchCover::overlapsPeriod()} and this class — expresses that one
 * rule, and they are pinned equal by test.
 *
 * NOTHING HERE CALLS now(). The instant to judge against is always an argument,
 * which is what lets the approval service, the surface that explains its
 * refusal, and the tests all reach the same answer.
 */
final class DoctorBranchCoverPeriod
{
    private function __construct(
        private readonly CarbonImmutable $startsAtUtc,
        private readonly CarbonImmutable $endsAtUtc,
    ) {}

    /**
     * Interpret two operator-entered local datetimes in the CLINICAL timezone.
     *
     * The FormRequest has already checked that these are dates and that the end
     * follows the start, but it compared them as strings in PHP's default zone,
     * which is a usability filter and not the boundary. This is the boundary.
     *
     * @param  string  $startsAtInput  a local datetime as the operator typed it
     * @param  string  $endsAtInput  a local datetime as the operator typed it
     *
     * @throws ValidationException when either value cannot be read as a datetime
     */
    public static function fromOperatorInput(
        ClinicalClock $clock,
        string $startsAtInput,
        string $endsAtInput,
    ): self {
        return new self(
            self::parseInClinicalZone($clock, $startsAtInput, 'starts_at'),
            self::parseInClinicalZone($clock, $endsAtInput, 'ends_at'),
        );
    }

    /**
     * The period a filed cover already carries.
     *
     * Read back from the columns, which are UTC instants, so there is no zone
     * to interpret — only a row that must still be readable. A cover whose
     * bounds are missing is refused rather than treated generously: an
     * unreadable period must never widen a doctor's authority.
     *
     * @throws ValidationException when either bound is absent
     */
    public static function fromCover(DoctorBranchCover $cover): self
    {
        $startsAt = $cover->starts_at;
        $endsAt = $cover->ends_at;

        if (! $startsAt instanceof DateTimeInterface || ! $endsAt instanceof DateTimeInterface) {
            throw ValidationException::withMessages([
                'cover' => 'Periode cover tidak dapat dibaca. Ajukan cover baru dengan periode yang jelas.',
            ]);
        }

        return new self(
            CarbonImmutable::instance($startsAt)->utc(),
            CarbonImmutable::instance($endsAt)->utc(),
        );
    }

    /** The UTC instant the cover becomes effective. Inclusive. */
    public function starts(): CarbonImmutable
    {
        return $this->startsAtUtc;
    }

    /** The UTC instant the cover stops being effective. EXCLUSIVE. */
    public function ends(): CarbonImmutable
    {
        return $this->endsAtUtc;
    }

    /**
     * Every bound this sprint places on a cover window, evaluated against one
     * supplied instant.
     *
     * CALLED TWICE ON PURPOSE — once when the cover is filed and again inside
     * the approval transaction. The second call is not redundant: it re-reads
     * `config('doctor_access.cover.*')`, so lowering the maximum duration
     * invalidates requests that are already sitting in the queue, and it
     * re-compares `ends_at` against the clock, so a window that finished while
     * the request waited can never be approved into effect.
     *
     * @param  CarbonInterface  $at  the instant to judge the window against
     *
     * @throws ValidationException with the offending field as the message key
     */
    public function assertUsableAt(CarbonInterface $at, ClinicalClock $clock): void
    {
        if (! $this->startsAtUtc->lessThan($this->endsAtUtc)) {
            throw ValidationException::withMessages([
                'ends_at' => 'Waktu selesai harus setelah waktu mulai.',
            ]);
        }

        if (! $this->endsAtUtc->greaterThan($at)) {
            throw ValidationException::withMessages([
                'ends_at' => 'Periode cover sudah berakhir. Ajukan periode baru.',
            ]);
        }

        $minMinutes = self::minMinutes();
        $maxDays = self::maxDays();

        if ($this->startsAtUtc->copy()->addMinutes($minMinutes)->greaterThan($this->endsAtUtc)) {
            throw ValidationException::withMessages([
                'ends_at' => 'Periode cover minimal '.$minMinutes.' menit.',
            ]);
        }

        if ($this->startsAtUtc->copy()->addDays($maxDays)->lessThan($this->endsAtUtc)) {
            throw ValidationException::withMessages([
                'ends_at' => 'Periode cover maksimal '.$maxDays.' hari. '
                    .'Untuk perpindahan yang lebih lama, gunakan alur perpindahan cabang tetap.',
            ]);
        }

        // Reading the clock is what proves the timezone is configured and
        // resolvable. Doing it here — after the cheap arithmetic — means an
        // operator with a malformed window is told about the window rather than
        // about a configuration fault they cannot act on.
        $clock->timezone();
    }

    /**
     * The window on the clinic's wall clock, for a message a human reads.
     *
     * Returns null rather than a guess when the timezone cannot be resolved: a
     * refusal that renders without its timestamps is better than a refusal that
     * cannot render at all.
     */
    public function describeInClinicalZone(ClinicalClock $clock): ?string
    {
        $start = self::formatInClinicalZone($this->startsAtUtc, $clock);
        $end = self::formatInClinicalZone($this->endsAtUtc, $clock);

        return $start === null || $end === null ? null : $start.' – '.$end;
    }

    /**
     * One instant on the clinic's wall clock, or null when the timezone cannot
     * be resolved. Static so a caller holding a bare `ends_at` — the transfer
     * guard naming the cover that blocks it — can use the same formatting.
     */
    public static function formatInClinicalZone(mixed $instant, ClinicalClock $clock): ?string
    {
        if (! $instant instanceof DateTimeInterface) {
            return null;
        }

        try {
            return CarbonImmutable::instance($instant)
                ->setTimezone($clock->timezone())
                ->format('d M Y H:i T');
        } catch (Throwable) {
            return null;
        }
    }

    /** The configured ceiling, clamped to a sane positive integer. */
    public static function maxDays(): int
    {
        return max(1, (int) config('doctor_access.cover.max_days', 90));
    }

    /** The configured floor, clamped to a sane positive integer. */
    public static function minMinutes(): int
    {
        return max(1, (int) config('doctor_access.cover.min_minutes', 30));
    }

    /**
     * @throws ValidationException
     */
    private static function parseInClinicalZone(
        ClinicalClock $clock,
        string $input,
        string $field,
    ): CarbonImmutable {
        try {
            // The zone argument is what makes "08:00" mean eight o'clock AT THE
            // CLINIC. ->utc() then normalises the instant for storage; it does
            // not move the moment in time, only the zone it is expressed in.
            return CarbonImmutable::parse(trim($input), $clock->timezoneObject())->utc();
        } catch (Throwable) {
            throw ValidationException::withMessages([
                $field => 'Format tanggal dan waktu tidak dapat dibaca.',
            ]);
        }
    }
}
