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

    /*
     * There was a requiredCoverageFrom(CarbonInterface $cutoff) here, returning the
     * earliest instant this source had to be read back to. It existed for one caller,
     * which compared it against the oldest event timestamp found in the scanned tail and
     * declared the source covered when the tail appeared to reach far enough back.
     *
     * That comparison is gone, and this method with it. It was not wrong about time — it
     * was answering the wrong question. Whether a scan reached far enough back is settled
     * by where the read started, not by which timestamps the read happened to contain;
     * deciding it from content let a single ancient line certify bytes the monitor never
     * opened. MonitoringLogScanCoverage now answers it from byte offsets alone, and takes
     * no timestamp at all so the comparison cannot be reintroduced by accident.
     *
     * `coversFrom` below survives because it still answers a real, structural question:
     * which day a rotating file belongs to. That is used to tell "this day-file was never
     * written" apart from "this day-file went missing" — an observation about the file
     * set, not a claim about unread bytes.
     */
}
