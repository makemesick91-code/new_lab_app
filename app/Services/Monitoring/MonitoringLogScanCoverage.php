<?php

namespace App\Services\Monitoring;

/**
 * What a single log-source read physically examined.
 *
 * This is the monitor's coverage authority, and it is deliberately built from nothing
 * but byte offsets. A log source is only "covered" when the read began at byte 0, i.e.
 * when every byte the file holds was actually put in front of the analyzer.
 *
 * Why this type exists at all, rather than a boolean computed inline:
 *
 * Coverage used to be settled by the oldest event timestamp found inside the scanned
 * tail — if that timestamp already sat before the lookback cutoff, the skipped prefix
 * was assumed to be older still and the window was declared fully covered. That
 * reasoning silently depended on the file being strictly chronological, and it handed
 * the coverage decision to the log's own contents. A single line beginning
 * `[2019-01-01 00:00:00]` anywhere inside the scanned tail was enough to certify bytes
 * the monitor never opened, and a genuine in-window ERROR sitting in the unread prefix
 * was then reported as "No fresh error events within lookback window."
 *
 * So the rule is now structural: an event cannot testify about bytes nobody read.
 * Note what this constructor does NOT accept — there is no timestamp, no event, no
 * parsed line. Content cannot reach this decision, which is what makes the anchor
 * unreproducible rather than merely patched.
 *
 * Event timestamps keep their own, separate job: deciding how old an event that WAS
 * read actually is. That is a question about an observation. Coverage is a question
 * about the read itself, and the two are not interchangeable.
 */
final class MonitoringLogScanCoverage
{
    private function __construct(
        /** Size of the source at the moment the read was planned. */
        public readonly int $fileBytes,
        /** Byte offset the read started from. Zero means the whole file was examined. */
        public readonly int $scanStartOffset,
        /** Bytes actually handed to the analyzer. */
        public readonly int $bytesScanned,
    ) {}

    public static function fromRead(int $fileBytes, int $scanStartOffset, int $bytesScanned): self
    {
        return new self(
            max(0, $fileBytes),
            max(0, $scanStartOffset),
            max(0, $bytesScanned),
        );
    }

    /**
     * A source with nothing in it is fully examined by definition: there are no bytes
     * that could be hiding an event.
     */
    public static function empty(): self
    {
        return new self(0, 0, 0);
    }

    /**
     * True only when the read started at the beginning of the file.
     *
     * Anything else leaves a prefix unexamined, and an unexamined prefix may hold an
     * event inside the lookback window. There is no byte-level fact that can rule that
     * out, so the monitor must not claim it has.
     */
    public function isComplete(): bool
    {
        return $this->scanStartOffset === 0;
    }

    public function isTruncated(): bool
    {
        return ! $this->isComplete();
    }

    /** Bytes the scan budget forced the monitor to skip. */
    public function skippedBytes(): int
    {
        return $this->scanStartOffset;
    }
}
