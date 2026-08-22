<?php

namespace App\Services\Monitoring;

/**
 * Reads free space on the filesystem backing a path.
 *
 * This exists so the one genuinely non-deterministic input to the pilot
 * performance snapshot — the amount of free disk on the machine that happens to
 * be running — can be substituted in a test. `overall_status` is the worst of
 * every section, so an unsubstituted disk reading silently decides the aggregate:
 * the same assertion passes on a spacious CI runner and fails on a nearly full
 * laptop. Injecting this probe lets a test state the disk it is reasoning about
 * and assert the aggregate as a literal.
 *
 * Production never substitutes it. The service defaults to this class, which is a
 * direct pass-through to disk_free_space(), so the deployed behaviour — including
 * a low-disk WATCH and a very-low-disk FIX — is unchanged.
 */
class PilotPerformanceSnapshotDiskProbe
{
    /**
     * Free bytes on the filesystem backing $path, or null when it cannot be read.
     *
     * disk_free_space() returns false on failure; null is returned instead so the
     * caller has a single "unknown" value to classify (an unknown disk is WATCH,
     * never OK).
     */
    public function freeBytes(string $path): ?float
    {
        $freeBytes = @disk_free_space($path);

        return $freeBytes === false ? null : (float) $freeBytes;
    }
}
