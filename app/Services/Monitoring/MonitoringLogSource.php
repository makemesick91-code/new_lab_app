<?php

namespace App\Services\Monitoring;

use Carbon\CarbonInterface;

/**
 * One monitorable log source resolved from the effective Laravel logging configuration.
 *
 * A source is a single concrete file the application may have written events to, plus
 * the slice of wall-clock time that file is responsible for. A `single` channel owns one
 * file responsible for all of history; a `daily` channel owns one file per calendar day,
 * each responsible only for that day.
 *
 * The time slice is what makes full-window coverage decidable: the monitor can only claim
 * it observed the whole lookback window when every slice intersecting that window was
 * actually read.
 */
final class MonitoringLogSource
{
    /** The driver writes to a file this monitor knows how to read. */
    public const SUPPORT_SUPPORTED = 'supported';

    /**
     * The driver writes somewhere this monitor cannot read (syslog, slack, stderr, a
     * custom monolog handler). Its events are invisible here, so a monitored stack that
     * contains one can never claim full coverage.
     */
    public const SUPPORT_UNSUPPORTED = 'unsupported';

    /**
     * The driver provably discards everything written to it, so its absence from the
     * scan costs no coverage. Only Laravel's `null` driver qualifies.
     */
    public const SUPPORT_IGNORABLE = 'ignorable';

    public function __construct(
        public readonly string $channel,
        public readonly string $driver,
        public readonly ?string $path,
        public readonly string $support,
        /**
         * Start of the slice this file is responsible for. Null means unbounded in the
         * past — a `single` file carries every event ever logged to the channel.
         */
        public readonly ?CarbonInterface $coversFrom = null,
        /** True when this is the day-file the logger is currently appending to. */
        public readonly bool $isCurrentDay = false,
    ) {}

    public function isSupported(): bool
    {
        return $this->support === self::SUPPORT_SUPPORTED;
    }

    /**
     * The earliest instant this source must have been read back to for the lookback
     * window to be covered by it.
     *
     * For a `single` file that is the window cutoff itself. For a `daily` file it is the
     * later of the cutoff and the start of that file's own day — a day-file cannot be
     * expected to contain anything from before its day began.
     */
    public function requiredCoverageFrom(CarbonInterface $cutoff): CarbonInterface
    {
        if ($this->coversFrom === null) {
            return $cutoff;
        }

        return $this->coversFrom->greaterThan($cutoff) ? $this->coversFrom : $cutoff;
    }
}
