<?php

namespace App\Services\Monitoring;

use Carbon\CarbonInterface;
use Monolog\Handler\NullHandler;

/**
 * MONITORING-LOG-SOURCE-RESILIENCE-1 — where the monitor is allowed to look.
 *
 * Monitoring used to read a hardcoded `storage/logs/laravel.log`. That path is only
 * correct by coincidence: it is where the shipped `single` channel happens to write, and
 * production happens to resolve `stack -> [single]`. The moment the configured channel
 * becomes `daily`, or the stack gains a member, the application writes its errors
 * somewhere else and the monitor keeps reading a file that has stopped moving. The log
 * section would then report OK — not because the application is healthy, but because the
 * monitor was looking at the wrong file. That is a false green, and it is the specific
 * failure this resolver exists to make impossible.
 *
 * The authority for "which files carry this application's log events" is the effective
 * Laravel logging configuration, read through `config()` so it is identical whether or
 * not `config:cache` has been run. Environment variables are inputs to that config, never
 * the runtime authority — reading `env()` here would silently disagree with a cached
 * config in exactly the deployment that matters.
 *
 * Resolution is deliberately conservative. A driver this monitor cannot read is reported
 * as unsupported rather than skipped, so the caller can refuse to claim coverage it does
 * not have.
 */
class MonitoringLogSourceResolver
{
    /** Drivers that write to a file this monitor knows how to read. */
    private const SUPPORTED_FILE_DRIVERS = ['single', 'daily'];

    /**
     * Drivers that provably discard every record, so their absence from the scan costs
     * no coverage. Only Laravel's `null` driver qualifies — `stderr`, `syslog` and
     * friends really do carry events, they are just not readable from here.
     */
    private const IGNORABLE_DRIVERS = ['null'];

    /** Guard against a pathological stack nesting; Laravel allows stacks of stacks. */
    private const MAX_STACK_DEPTH = 5;

    /**
     * Ceiling on rotated files expanded for one window.
     *
     * The lookback duration is caller-supplied and effectively unbounded (`--since=365d`
     * parses), and every extra day is another file read at up to the scan budget. Capping
     * the set keeps a long window from turning one snapshot into hundreds of megabytes of
     * IO. The cap is reported, not hidden: a window that needs more days than this is
     * marked as not fully covered rather than silently answered from a partial set.
     */
    private const MAX_DAILY_SOURCES = 31;

    /**
     * Resolve the set of log files that must be inspected to cover [$cutoff, $now].
     *
     * @return array{
     *   default_channel:string,
     *   monitored_channels:list<string>,
     *   sources:list<MonitoringLogSource>,
     *   unsupported_channels:list<string>,
     *   resolution_status:string
     * }
     */
    public function resolve(CarbonInterface $cutoff, CarbonInterface $now): array
    {
        $default = (string) config('logging.default', '');

        if ($default === '') {
            return [
                'default_channel' => '',
                'monitored_channels' => [],
                'sources' => [],
                'unsupported_channels' => [],
                // No configured default channel at all. The monitor has no idea where the
                // application logs, so it must not pretend to have checked.
                'resolution_status' => 'unresolved',
            ];
        }

        $channels = $this->expandChannel($default, 0, []);

        $sources = [];
        $unsupported = [];

        foreach ($channels as $channel) {
            $config = (array) config('logging.channels.'.$channel, []);
            $driver = (string) ($config['driver'] ?? '');

            if ($this->discardsEverything($driver, $config)) {
                continue;
            }

            if (! in_array($driver, self::SUPPORTED_FILE_DRIVERS, true)) {
                // Reported, never dropped. A stack member writing to syslog or Slack
                // carries error events this monitor cannot see; omitting it silently is
                // exactly how a partial scan gets presented as a full one.
                $unsupported[] = $channel;
                $sources[] = new MonitoringLogSource(
                    channel: $channel,
                    driver: $driver === '' ? 'undefined' : $driver,
                    path: null,
                    support: MonitoringLogSource::SUPPORT_UNSUPPORTED,
                );

                continue;
            }

            $path = $config['path'] ?? null;

            if (! is_string($path) || trim($path) === '') {
                $unsupported[] = $channel;
                $sources[] = new MonitoringLogSource(
                    channel: $channel,
                    driver: $driver,
                    path: null,
                    support: MonitoringLogSource::SUPPORT_UNSUPPORTED,
                );

                continue;
            }

            if ($driver === 'single') {
                $sources[] = new MonitoringLogSource(
                    channel: $channel,
                    driver: 'single',
                    path: $path,
                    support: MonitoringLogSource::SUPPORT_SUPPORTED,
                    // A single file is never rotated, so it is responsible for the whole
                    // of history and therefore for the whole of any lookback window.
                    coversFrom: null,
                );

                continue;
            }

            foreach ($this->dailySources($channel, $path, $cutoff, $now) as $source) {
                $sources[] = $source;
            }
        }

        $hasReadable = false;

        foreach ($sources as $source) {
            if ($source->isSupported()) {
                $hasReadable = true;

                break;
            }
        }

        return [
            'default_channel' => $default,
            'monitored_channels' => $channels,
            'sources' => $sources,
            'unsupported_channels' => array_values(array_unique($unsupported)),
            // True when the window spanned more rotated days than the cap allows, so the
            // oldest part of it was never expanded into a source at all.
            'source_set_truncated' => $this->dailySpanExceedsCap($sources, $cutoff, $now),
            // 'unresolved' means the configuration named no file this monitor can read at
            // all. That is not a healthy system with a quiet log; it is a monitor with
            // nothing to observe, and the caller must fail closed on it.
            'resolution_status' => $hasReadable ? 'resolved' : 'unresolved',
        ];
    }

    /**
     * Whether the rotated-file cap actually bit for this window.
     *
     * Only meaningful when a daily channel is in play; a `single` channel expands to one
     * file regardless of how long the window is.
     *
     * @param  list<MonitoringLogSource>  $sources
     */
    private function dailySpanExceedsCap(array $sources, CarbonInterface $cutoff, CarbonInterface $now): bool
    {
        foreach ($sources as $source) {
            if ($source->driver === 'daily') {
                $timezone = (string) config('app.timezone', 'UTC');
                $days = $cutoff->copy()->setTimezone($timezone)->startOfDay()
                    ->diffInDays($now->copy()->setTimezone($timezone)->startOfDay()) + 1;

                return $days > self::MAX_DAILY_SOURCES;
            }
        }

        return false;
    }

    /**
     * Whether a channel provably keeps nothing, so skipping it costs no coverage.
     *
     * Laravel's shipped `null` channel is not `driver => null` — it is a `monolog` channel
     * wired to Monolog's NullHandler. Matching on the channel's *name* would be wrong in
     * both directions: a channel called something else can discard just as thoroughly, and
     * a channel called `null` could be reconfigured to write a real file. The handler class
     * is the only honest signal, and everything else that is unreadable from here — syslog,
     * stderr, Slack — really does carry events and must cost coverage.
     *
     * @param  array<string, mixed>  $config
     */
    private function discardsEverything(string $driver, array $config): bool
    {
        if (in_array($driver, self::IGNORABLE_DRIVERS, true)) {
            return true;
        }

        if ($driver !== 'monolog') {
            return false;
        }

        $handler = $config['handler'] ?? null;

        return is_string($handler) && is_a($handler, NullHandler::class, allow_string: true);
    }

    /**
     * Flatten a channel name into its concrete leaf channels, following `stack` members.
     *
     * @param  list<string>  $seen
     * @return list<string>
     */
    private function expandChannel(string $channel, int $depth, array $seen): array
    {
        // A stack that lists itself would otherwise recurse forever. Depth is capped for
        // the same reason: resolution must terminate on any configuration, however odd.
        if ($depth >= self::MAX_STACK_DEPTH || in_array($channel, $seen, true)) {
            return [];
        }

        $config = (array) config('logging.channels.'.$channel, []);

        if (($config['driver'] ?? null) !== 'stack') {
            return [$channel];
        }

        $seen[] = $channel;
        $members = $config['channels'] ?? [];
        $resolved = [];

        foreach ((array) $members as $member) {
            if (! is_string($member)) {
                continue;
            }

            $member = trim($member);

            if ($member === '') {
                continue;
            }

            foreach ($this->expandChannel($member, $depth + 1, $seen) as $leaf) {
                if (! in_array($leaf, $resolved, true)) {
                    $resolved[] = $leaf;
                }
            }
        }

        return $resolved;
    }

    /**
     * Expand a `daily` channel into the day-files intersecting the lookback window.
     *
     * A 24 hour window read at 01:00 spans two calendar days, so today's file alone
     * covers barely an hour of it. Scanning only the current day is how a monitor reports
     * a clean bill of health minutes after midnight while yesterday's file still holds a
     * live error inside the window.
     *
     * @return list<MonitoringLogSource>
     */
    private function dailySources(string $channel, string $path, CarbonInterface $cutoff, CarbonInterface $now): array
    {
        // Monolog stamps rotated filenames with the PHP default timezone, which Laravel
        // sets from config('app.timezone'). Resolving the day boundaries in any other
        // zone would look for filenames that do not exist near midnight.
        $timezone = (string) config('app.timezone', 'UTC');

        $day = $cutoff->copy()->setTimezone($timezone)->startOfDay();
        $last = $now->copy()->setTimezone($timezone)->startOfDay();
        $today = $last->format('Y-m-d');

        // Keep the most recent days when the window asks for more than the cap: the newest
        // files are the ones a freshness verdict actually turns on. The dropped days are
        // what makes the set incomplete, and the caller is told.
        $earliestAllowed = $last->copy()->subDays(self::MAX_DAILY_SOURCES - 1);

        if ($day->lessThan($earliestAllowed)) {
            $day = $earliestAllowed;
        }

        $sources = [];
        // Bounded independently of the loop condition so a malformed window can never spin.
        $guard = 0;

        while ($day->lessThanOrEqualTo($last) && $guard++ <= self::MAX_DAILY_SOURCES) {
            $date = $day->format('Y-m-d');

            $sources[] = new MonitoringLogSource(
                channel: $channel,
                driver: 'daily',
                path: $this->dailyPathFor($path, $date),
                support: MonitoringLogSource::SUPPORT_SUPPORTED,
                coversFrom: $day->copy(),
                isCurrentDay: $date === $today,
            );

            $day = $day->copy()->addDay();
        }

        return $sources;
    }

    /**
     * Apply Monolog's rotating filename convention: `laravel.log` -> `laravel-Y-m-d.log`.
     *
     * This mirrors RotatingFileHandler's default `{filename}-{date}` format. It is derived
     * from the configured path rather than assumed, so a project that relocates or renames
     * its log still resolves correctly.
     */
    private function dailyPathFor(string $path, string $date): string
    {
        $directory = dirname($path);
        $basename = basename($path);
        $extension = pathinfo($basename, PATHINFO_EXTENSION);
        $stem = $extension === '' ? $basename : substr($basename, 0, -(strlen($extension) + 1));

        $rotated = $stem.'-'.$date.($extension === '' ? '' : '.'.$extension);

        return $directory === '' || $directory === '.'
            ? $rotated
            : rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$rotated;
    }
}
