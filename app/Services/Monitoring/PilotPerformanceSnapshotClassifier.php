<?php

namespace App\Services\Monitoring;

class PilotPerformanceSnapshotClassifier
{
    public const STATUS_OK = 'OK';

    public const STATUS_WATCH = 'WATCH';

    public const STATUS_INVESTIGATE = 'INVESTIGATE';

    public const STATUS_FIX = 'FIX';

    /** @var array<string, int> */
    private const SEVERITY = [
        self::STATUS_OK => 0,
        self::STATUS_WATCH => 1,
        self::STATUS_INVESTIGATE => 2,
        self::STATUS_FIX => 3,
    ];

    public static function worst(string ...$statuses): string
    {
        $worst = self::STATUS_OK;

        foreach ($statuses as $status) {
            if ((self::SEVERITY[$status] ?? 0) > (self::SEVERITY[$worst] ?? 0)) {
                $worst = $status;
            }
        }

        return $worst;
    }

    public static function exitCodeForStatus(string $status): int
    {
        return match ($status) {
            self::STATUS_OK => 0,
            self::STATUS_WATCH => 1,
            self::STATUS_INVESTIGATE => 2,
            self::STATUS_FIX => 3,
            default => 10,
        };
    }

    public static function classifySqlRuntimeMs(?float $runtimeMs): string
    {
        if ($runtimeMs === null) {
            return self::STATUS_OK;
        }

        if ($runtimeMs < 100) {
            return self::STATUS_OK;
        }

        if ($runtimeMs < 500) {
            return self::STATUS_WATCH;
        }

        if ($runtimeMs <= 1000) {
            return self::STATUS_INVESTIGATE;
        }

        return self::STATUS_FIX;
    }

    public static function classifyHttpTimingMs(?float $avgMs, ?float $p95Ms = null): string
    {
        $timingStatus = self::STATUS_OK;

        if ($avgMs !== null) {
            if ($avgMs > 1000 || ($p95Ms !== null && $p95Ms > 1000)) {
                $timingStatus = self::STATUS_FIX;
            } elseif ($avgMs > 500 || ($p95Ms !== null && $p95Ms > 500)) {
                $timingStatus = self::STATUS_INVESTIGATE;
            } elseif ($avgMs > 300) {
                $timingStatus = self::STATUS_WATCH;
            } elseif ($avgMs > 100) {
                $timingStatus = self::STATUS_WATCH;
            }
        }

        return $timingStatus;
    }

    /**
     * @param  array{code?:int|null,error?:string|null,avg_ms?:float|null}  $check
     */
    public static function classifyHttpCheck(array $check): string
    {
        $code = $check['code'] ?? null;
        $error = $check['error'] ?? null;
        $avgMs = $check['avg_ms'] ?? null;

        if ($error !== null) {
            return self::STATUS_INVESTIGATE;
        }

        if ($code === null) {
            return self::STATUS_WATCH;
        }

        if ($code >= 500) {
            return self::STATUS_FIX;
        }

        if ($code >= 400) {
            return self::STATUS_INVESTIGATE;
        }

        $expected = in_array($code, [200, 302], true);

        $status = $expected ? self::STATUS_OK : self::STATUS_WATCH;

        return self::worst($status, self::classifyHttpTimingMs($avgMs));
    }

    public static function classifyDiskFreeGb(?float $freeGb): string
    {
        if ($freeGb === null) {
            return self::STATUS_WATCH;
        }

        if ($freeGb < 10) {
            return self::STATUS_FIX;
        }

        if ($freeGb < 20) {
            return self::STATUS_WATCH;
        }

        return self::STATUS_OK;
    }

    public static function classifyPaymentCount(int $count, string $sqlStatus = self::STATUS_OK, string $httpStatus = self::STATUS_OK): string
    {
        if ($count < 10_000) {
            return self::STATUS_OK;
        }

        if ($count < 1_000_000) {
            return self::STATUS_WATCH;
        }

        return self::worst(self::STATUS_WATCH, $sqlStatus, $httpStatus);
    }

    public static function classifyDebugMode(bool $debug, string $environment): string
    {
        if ($debug && $environment === 'pilot') {
            return self::STATUS_WATCH;
        }

        return self::STATUS_OK;
    }

    public static function classifyMaintenanceMode(bool $maintenance): string
    {
        return $maintenance ? self::STATUS_INVESTIGATE : self::STATUS_OK;
    }

    public static function classifyLongRunningQueries(int $over30s, int $over5m): string
    {
        if ($over5m > 0) {
            return self::STATUS_FIX;
        }

        if ($over30s > 0) {
            return self::STATUS_WATCH;
        }

        return self::STATUS_OK;
    }

    /**
     * @return array{status:string, reason:string}
     */
    public static function classifyFreshLogErrors(
        int $freshCount,
        int $criticalFreshCount,
        string $timestampParseStatus,
        int $orphanUnparseableCount,
        int $historicalCount,
        int $historicalStackTraceCount = 0,
        int $undatedErrorLikeCount = 0,
        bool $windowFullyCovered = true,
    ): array {
        // Freshness is undetermined when the log could not be aged reliably: the
        // timestamps failed to parse outright, an error event carried an unparseable
        // header timestamp, or enough orphan lines survived that grouping is unreliable.
        $freshnessUndetermined = $timestampParseStatus === 'failed'
            || $undatedErrorLikeCount > 0
            || ($orphanUnparseableCount > 20 && $timestampParseStatus !== 'ok');

        /*
         * MONITORING-UNDATED-SEVERITY-ESCALATION-1 — unageable ERROR evidence carries
         * weight, not merely presence.
         *
         * `undated_error_like_count` and `fresh_error_like_count` are produced by the
         * same event-grouping pass in the analyzer and carry the same unit: one
         * error-like log event. A run of N identical ERROR events counts N in the fresh
         * bucket when its headers parse and N in the undated bucket when they do not.
         * The two differ in whether the monitor can say *when* the error happened —
         * never in whether an error happened at all.
         *
         * Rule 113 R2 and rule 115 R9 already settled what to do with that: an
         * unageable ERROR may never read as OK, because absence of evidence is not
         * evidence of absence. Both statements describe a floor, and the floor they
         * describe is exactly the fresh ladder's first rung — `undated > 0` forcing
         * WATCH is `freshCount >= 1` forcing WATCH. The remaining rungs were simply
         * never extended, so undated evidence contributed one unit of severity and
         * then stopped: 1 and 1000 returned the same WATCH, and the identical burst of
         * 150 events reported FIX with parseable dates and WATCH without them. Two
         * levels of operational confidence bought by corruption in a date field.
         *
         * Since the monitor cannot rule out that an unageable event falls inside the
         * window, the fail-closed reading is that it does, and the canonical in-window
         * ladder is the one that applies. The two buckets are therefore summed onto it.
         *
         * Summing only ever raises: the total is >= the parsed fresh count, so the
         * parsed count still sets the floor and rule 113 R4 ("unparseable noise
         * escalates, it never masks") holds by construction. It is also monotonic in
         * both arguments — more adverse evidence can never buy a calmer verdict.
         *
         * Two alternatives were rejected on evidence. Classifying the buckets
         * independently and taking the worst understates mixed evidence — 15 fresh
         * plus 15 undated is up to 30 in-window events reported as WATCH, which is R4's
         * failure mode relocated to another boundary. Giving undated evidence its own,
         * higher thresholds would discount evidence the monitor has no basis to
         * discount, which is the false confidence this reasoning exists to remove.
         *
         * Deliberately not done: `criticalFreshCount` stays fresh-only. The analyzer
         * tests CRITICAL_PATTERN only on events it could age, so there is no measured
         * critical count for the undated bucket, and inventing one would assert
         * severity the monitor never observed. Volume escalation covers the case.
         */
        $errorEvidenceCount = $freshCount + $undatedErrorLikeCount;

        if ($errorEvidenceCount === 0) {
            // No countable error evidence of either kind. `undatedErrorLikeCount` is
            // necessarily zero here, so freshness can only be undetermined because of a
            // parse failure or surviving orphan lines.
            //
            // Fail closed. With nothing countable AND no trustworthy way to age the log,
            // "no fresh errors" is not a finding — it is an absence of evidence, and it
            // may never be reported as OK.
            if ($freshnessUndetermined) {
                return [
                    'status' => self::STATUS_WATCH,
                    'reason' => 'Unable to determine freshness from log timestamps.',
                ];
            }

            // The scan is bounded by a byte budget, not by the requested window. If it
            // never reached back past the cutoff then "no fresh errors" describes only the
            // slice of the window that fit inside that budget — which is not the question
            // that was asked, and must not be answered as though it were.
            if (! $windowFullyCovered) {
                return [
                    'status' => self::STATUS_WATCH,
                    'reason' => 'Log scan did not reach the start of the lookback window; freshness is unknown for the unscanned portion.',
                ];
            }

            if ($historicalCount > 0) {
                $reason = 'No fresh error events within lookback window; historical entries are informational only.';

                if ($historicalStackTraceCount > 0) {
                    $reason = 'No fresh error events within lookback window. Historical stack trace continuation lines were grouped under historical events.';
                }

                return [
                    'status' => self::STATUS_OK,
                    'reason' => $reason,
                ];
            }

            return [
                'status' => self::STATUS_OK,
                'reason' => 'No fresh error events within lookback window.',
            ];
        }

        $status = match (true) {
            $errorEvidenceCount > 100 => self::STATUS_FIX,
            $errorEvidenceCount > 20 => self::STATUS_INVESTIGATE,
            default => self::STATUS_WATCH,
        };

        if ($criticalFreshCount >= 10) {
            $status = self::worst($status, self::STATUS_FIX);
        } elseif ($criticalFreshCount >= 3) {
            $status = self::worst($status, self::STATUS_INVESTIGATE);
        }

        // Undetermined freshness escalates, it never suppresses. The parsed fresh count
        // still drives severity, so a genuine FIX-level burst can no longer be masked
        // down to WATCH by unparseable noise elsewhere in the scanned tail.
        if ($freshnessUndetermined) {
            $status = self::worst($status, self::STATUS_WATCH);
        }

        // An operator has to be able to act on the verdict, so the reason names what
        // drove it. When untrusted timestamps contributed, say so — the remedy for a
        // corrupt date field is not the remedy for a genuine error burst. The
        // fresh-only wording is preserved verbatim so an unaffected verdict reads
        // exactly as it always has.
        $reason = $undatedErrorLikeCount > 0
            ? match ($status) {
                self::STATUS_FIX => 'Error events exceed FIX threshold within lookback window; some carry unparseable timestamps and could not be aged.',
                self::STATUS_INVESTIGATE => 'Error events exceed INVESTIGATE threshold within lookback window; some carry unparseable timestamps and could not be aged.',
                default => 'Error events carry unparseable timestamps and could not be aged; freshness is unknown.',
            }
        : match ($status) {
            self::STATUS_FIX => 'Fresh error events exceed FIX threshold within lookback window.',
            self::STATUS_INVESTIGATE => 'Fresh error events exceed INVESTIGATE threshold within lookback window.',
            default => 'Fresh error events detected within lookback window.',
        };

        return [
            'status' => $status,
            'reason' => $reason,
        ];
    }
}
