<?php

declare(strict_types=1);

namespace App\Support\Queue;

use ReflectionClass;
use Symfony\Component\Finder\Finder;
use Throwable;

/**
 * ENT5-Q009/Q010 — the dedicated-queue producer ↔ consumer contract.
 *
 * A queue this application dispatches to, that no worker consumes, is the
 * quietest failure mode the system has: the job row sits at attempts=0 with a
 * NULL reservation, nothing lands in failed_jobs, no exception is logged, and
 * the domain record stays at QUEUED forever. It looks exactly like "still
 * working" to every operator and every dashboard.
 *
 * That has now happened twice. LEGACY-RME-PDF-ROLL-2 hit it on
 * `legacy-rme-documents` and answered with a queue_contract check inside the
 * Legacy-RME readiness service — correct, but scoped to one module. Legacy
 * Odontogram then shipped its own dedicated queue, inherited none of that
 * protection, and stalled the pilot's first upload the same way.
 *
 * So this scanner is deliberately module-agnostic. It answers three questions
 * about the repository as a whole:
 *
 *   PRODUCED ⊆ ALLOWED           every queue the app can dispatch to is a
 *                                declared ENT-5 queue name;
 *   PRODUCED(active) ⊆ CONSUMED  every actively-produced queue appears in the
 *                                tracked production worker's --queue list;
 *   CONSUMED ⊆ ALLOWED           the worker never consumes an undeclared queue.
 *
 * Two properties make it hard to fool:
 *
 * 1. Queue names are RESOLVED AT RUNTIME from the config key that actually
 *    decides them, not grepped as literals. Every dedicated queue here is
 *    env-overridable, so a literal scan would report a name production does
 *    not use.
 *
 * 2. The registry is checked for COMPLETENESS against the source tree. Any file
 *    under the scanned paths that routes work to a queue must be covered by a
 *    registry entry. A future module cannot add a dedicated queue and stay
 *    invisible: it either registers (and is then parity-checked) or it fails
 *    this check outright.
 *
 * Read-only throughout: it dispatches nothing, mutates no config, and touches
 * no queue backend.
 */
final class QueueProducerConsumerContractScanner
{
    /**
     * @return array<string, mixed>
     */
    public function posture(): array
    {
        $config = $this->config();

        $produced = $this->producedQueues();
        $allowed = $this->allowedQueueNames();
        $tracked = $this->trackedWorkerUnit();
        $installed = $this->installedWorkerUnit();

        $issues = [];

        foreach ($produced as $entry) {
            if ($entry['error'] !== null) {
                $issues[] = $entry['error'];
            }
        }

        $registry = $this->registryCompleteness();
        foreach ($registry['issues'] as $issue) {
            $issues[] = $issue;
        }

        $producedNames = $this->namesOf($produced);
        $activeNames = $this->namesOf(array_filter($produced, static fn (array $e): bool => $e['consumer_required']));

        $undeclared = $this->missingFrom($producedNames, $allowed);
        foreach ($undeclared as $queue) {
            $issues[] = sprintf(
                'produced queue "%s" is not declared in the ENT-5 allowed queue names',
                $queue
            );
        }

        if (! $tracked['readable']) {
            $issues[] = sprintf('the tracked worker unit could not be read: %s', $tracked['path']);
        } elseif ($tracked['queues'] === null) {
            $issues[] = sprintf('no ExecStart --queue list could be parsed from %s', $tracked['path']);
        } else {
            $unconsumed = $this->missingFrom($activeNames, $tracked['queues']);
            foreach ($unconsumed as $queue) {
                $issues[] = sprintf(
                    'produced queue "%s" has no consumer: it is absent from the worker unit --queue list',
                    $queue
                );
            }

            $consumedUndeclared = $this->missingFrom($tracked['queues'], $allowed);
            foreach ($consumedUndeclared as $queue) {
                $issues[] = sprintf(
                    'the worker consumes queue "%s", which is not a declared ENT-5 queue name',
                    $queue
                );
            }
        }

        // The POST-ENT runtime-hardening list names the queues the worker is
        // approved to take. It is a second copy of the same fact, so it is held
        // to the unit rather than left to drift into a decorative list.
        $approvedMirror = $this->approvedQueueMirror($tracked['queues']);
        foreach ($approvedMirror['issues'] as $issue) {
            $issues[] = $issue;
        }

        $directives = $this->directiveSections($tracked);

        // Drift between the tracked unit and the one systemd actually runs is a
        // production-only signal and never a repository defect: the deploy is
        // forbidden from installing or starting a worker (ENT-5), so between a
        // unit-changing deploy and the operator's activation step the two
        // legitimately differ. It is reported as a warning so the gate stays
        // truthful without deadlocking the deploy that must precede activation.
        $warnings = [];
        if ($installed['readable'] && $installed['queues'] !== null && $tracked['queues'] !== null) {
            $staleQueues = $this->missingFrom($activeNames, $installed['queues']);
            foreach ($staleQueues as $queue) {
                $warnings[] = sprintf(
                    'the INSTALLED worker unit does not consume "%s" yet — run the worker activation runbook',
                    $queue
                );
            }
        }

        return [
            'ok' => $issues === [],
            'issues' => $issues,
            'warnings' => $warnings,
            'produced' => $produced,
            'produced_queues' => $producedNames,
            'produced_queues_requiring_consumer' => $activeNames,
            'allowed_queue_names' => $allowed,
            'worker_unit' => [
                'tracked_path' => $tracked['path'],
                'tracked_readable' => $tracked['readable'],
                'tracked_queues' => $tracked['queues'],
                'installed_path' => $installed['path'],
                'installed_readable' => $installed['readable'],
                'installed_queues' => $installed['queues'],
                'installed_drift' => $warnings !== [],
            ],
            'registry_completeness' => $registry,
            'approved_queue_mirror' => $approvedMirror,
            'directive_sections' => $directives,
            'contract_doc' => (string) ($config['contract_doc'] ?? ''),
        ];
    }

    /**
     * Every queue this application can dispatch to, resolved the way the
     * runtime resolves it.
     *
     * @return list<array<string, mixed>>
     */
    public function producedQueues(): array
    {
        $entries = (array) ($this->config()['produced_queues'] ?? []);
        $resolved = [];

        foreach ($entries as $index => $entry) {
            if (! is_array($entry)) {
                $resolved[] = [
                    'id' => (string) $index,
                    'queue' => null,
                    'jobs' => [],
                    'consumer_required' => true,
                    'consumer_gate' => null,
                    'error' => sprintf('produced_queues entry #%s is malformed', (string) $index),
                ];

                continue;
            }

            $id = (string) ($entry['id'] ?? $index);
            $queue = $this->resolveQueueName($entry);
            $jobs = array_values(array_map('strval', (array) ($entry['jobs'] ?? [])));

            $error = null;
            if ($queue === null) {
                $error = sprintf('produced queue entry "%s" resolves to an empty queue name', $id);
            }
            if ($jobs === []) {
                $error = sprintf('produced queue entry "%s" declares no producing job class', $id);
            }
            foreach ($jobs as $job) {
                if (! class_exists($job)) {
                    $error = sprintf('produced queue entry "%s" names a class that does not exist: %s', $id, $job);
                }
            }

            [$required, $gate] = $this->consumerRequirement($entry);

            $resolved[] = [
                'id' => $id,
                'queue' => $queue,
                'jobs' => $jobs,
                'consumer_required' => $required,
                'consumer_gate' => $gate,
                'error' => $error,
            ];
        }

        return $resolved;
    }

    /**
     * Whether a consumer is required for this entry right now.
     *
     * An entry may name a config flag that gates its producer. While that flag
     * is falsy the job cannot be dispatched, so demanding a worker for it would
     * be noise. The suspension is DYNAMIC, never a static exemption list: the
     * moment the flag is enabled the consumer requirement returns on its own.
     *
     * @param  array<string, mixed>  $entry
     * @return array{0: bool, 1: string|null}
     */
    private function consumerRequirement(array $entry): array
    {
        $gate = $entry['consumer_required_when'] ?? null;

        if (! is_string($gate) || $gate === '') {
            return [true, null];
        }

        return [(bool) config($gate, false), $gate];
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function resolveQueueName(array $entry): ?string
    {
        $key = $entry['config_key'] ?? null;
        $fallback = $entry['fallback'] ?? null;

        if (is_string($key) && $key !== '') {
            $value = config($key, $fallback);

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        foreach ([$entry['literal'] ?? null, $fallback] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }

    /**
     * Does the registry account for every queue-routing site in the source?
     *
     * This is the check that makes the contract generic rather than a list of
     * the queues someone remembered. A new module that routes work to its own
     * queue must appear in the registry; if it does not, this fails and the
     * parity check above never gets the chance to silently pass it.
     *
     * @return array{ok: bool, issues: list<string>, covered: list<string>, uncovered: list<string>, scanned: int}
     */
    public function registryCompleteness(): array
    {
        $scan = (array) ($this->config()['queue_assignment_scan'] ?? []);
        $paths = array_values(array_map('strval', (array) ($scan['paths'] ?? [])));
        $patterns = array_values(array_filter(array_map(
            'strval',
            (array) ($scan['patterns'] ?? [])
        ), static fn (string $p): bool => $p !== ''));

        if ($paths === [] || $patterns === []) {
            return [
                'ok' => false,
                'issues' => ['queue_assignment_scan must declare both paths and patterns'],
                'covered' => [],
                'uncovered' => [],
                'scanned' => 0,
            ];
        }

        $registered = $this->registeredProducerFiles();
        $covered = [];
        $uncovered = [];

        $scan = $this->routingFiles($paths, $patterns);
        $scanned = count($scan['hits']);

        foreach ($scan['hits'] as $file) {
            $relative = $this->relativePath($file);

            if (in_array($file, $registered, true)) {
                $covered[] = $relative;
            } else {
                $uncovered[] = $relative;
            }
        }

        sort($covered);
        sort($uncovered);

        $issues = [];
        foreach ($uncovered as $file) {
            $issues[] = sprintf(
                '%s routes work to a queue but no produced_queues entry declares it — register it so its consumer is verified',
                $file
            );
        }

        foreach ($scan['unreadable'] as $file) {
            $issues[] = sprintf(
                '%s could not be read, so it cannot be shown not to route work to an unconsumed queue',
                $this->relativePath($file)
            );
        }

        return [
            'ok' => $issues === [],
            'issues' => $issues,
            'covered' => $covered,
            'uncovered' => $uncovered,
            'scanned' => $scanned,
        ];
    }

    /**
     * Absolute file paths of every job class named in the registry.
     *
     * @return list<string>
     */
    private function registeredProducerFiles(): array
    {
        $files = [];

        foreach ((array) ($this->config()['produced_queues'] ?? []) as $entry) {
            foreach ((array) ($entry['jobs'] ?? []) as $job) {
                $job = (string) $job;
                if ($job === '' || ! class_exists($job)) {
                    continue;
                }

                try {
                    $file = (new ReflectionClass($job))->getFileName();
                } catch (Throwable) {
                    continue;
                }

                if (is_string($file) && $file !== '' && ! in_array($file, $files, true)) {
                    $files[] = $file;
                }
            }
        }

        return $files;
    }

    /**
     * The POST-ENT approved-queue list held to the unit it claims to describe.
     *
     * @param  list<string>|null  $consumed
     * @return array{ok: bool, issues: list<string>, approved: list<string>, consumed: list<string>}
     */
    private function approvedQueueMirror(?array $consumed): array
    {
        $approved = array_values(array_map(
            'strval',
            (array) config('enterprise_foundation_runtime_hardening.queue_worker.approved_queues', [])
        ));

        if ($consumed === null) {
            return ['ok' => true, 'issues' => [], 'approved' => $approved, 'consumed' => []];
        }

        $issues = [];

        foreach ($this->missingFrom($approved, $consumed) as $queue) {
            $issues[] = sprintf(
                'queue "%s" is approved for the worker but the worker unit does not consume it',
                $queue
            );
        }

        foreach ($this->missingFrom($consumed, $approved) as $queue) {
            $issues[] = sprintf(
                'the worker unit consumes "%s" but it is missing from the approved worker queue list',
                $queue
            );
        }

        return ['ok' => $issues === [], 'issues' => $issues, 'approved' => $approved, 'consumed' => $consumed];
    }

    /**
     * Unit directives that systemd only honours in one particular section.
     *
     * Production proved this is not pedantry. The unit carried
     * StartLimitIntervalSec under [Service]; systemd logged "Unknown key name
     * ... in section 'Service', ignoring" and applied its 10s default instead
     * of the 0 the file asked for. The file said one thing and the running
     * service did another, and nothing noticed.
     *
     * @param  array{path: string, readable: bool, contents: string|null, queues: list<string>|null}  $unit
     * @return array{ok: bool, issues: list<string>, checked: array<string, string>}
     */
    private function directiveSections(array $unit): array
    {
        $expected = (array) ($this->config()['unit_directive_sections'] ?? []);
        $issues = [];
        $checked = [];

        if ($expected === [] || $unit['contents'] === null) {
            return ['ok' => true, 'issues' => [], 'checked' => []];
        }

        $sections = $this->sectionOfEachDirective($unit['contents']);

        foreach ($expected as $directive => $requiredSection) {
            $directive = (string) $directive;
            $requiredSection = (string) $requiredSection;
            $actual = $sections[strtolower($directive)] ?? null;

            if ($actual === null) {
                // Absent is fine — this asks where a directive lives, not that
                // it must exist.
                continue;
            }

            $checked[$directive] = $actual;

            if (strcasecmp($actual, $requiredSection) !== 0) {
                $issues[] = sprintf(
                    '%s is declared in [%s] but systemd only honours it in [%s]; it is being silently ignored',
                    $directive,
                    $actual,
                    $requiredSection
                );
            }
        }

        return ['ok' => $issues === [], 'issues' => $issues, 'checked' => $checked];
    }

    /**
     * @return array<string, string> lower-cased directive => section name
     */
    private function sectionOfEachDirective(string $contents): array
    {
        $section = '';
        $found = [];

        foreach (preg_split('/\r?\n/', $contents) ?: [] as $line) {
            $trimmed = trim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '#') || str_starts_with($trimmed, ';')) {
                continue;
            }

            if (preg_match('/^\[([^\]]+)\]$/', $trimmed, $m) === 1) {
                $section = $m[1];

                continue;
            }

            if (preg_match('/^([A-Za-z][A-Za-z0-9_]*)\s*=/', $trimmed, $m) === 1) {
                $found[strtolower($m[1])] = $section;
            }
        }

        return $found;
    }

    /**
     * @return array{path: string, readable: bool, contents: string|null, queues: list<string>|null}
     */
    private function trackedWorkerUnit(): array
    {
        $path = (string) ($this->config()['worker_unit_file'] ?? '');

        if ($path === '') {
            return $this->readUnit('', $path);
        }

        // Repository-relative by convention; an absolute path is honoured as
        // given rather than being appended to the base path and vanishing.
        return $this->readUnit(str_starts_with($path, '/') ? $path : base_path($path), $path);
    }

    /**
     * @return array{path: string, readable: bool, contents: string|null, queues: list<string>|null}
     */
    private function installedWorkerUnit(): array
    {
        $path = (string) ($this->config()['installed_worker_unit_file'] ?? '');

        return $this->readUnit($path, $path);
    }

    /**
     * @return array{path: string, readable: bool, contents: string|null, queues: list<string>|null}
     */
    private function readUnit(string $absolute, string $label): array
    {
        if ($absolute === '' || ! is_file($absolute) || ! is_readable($absolute)) {
            return ['path' => $label, 'readable' => false, 'contents' => null, 'queues' => null];
        }

        $contents = file_get_contents($absolute);

        if ($contents === false) {
            return ['path' => $label, 'readable' => false, 'contents' => null, 'queues' => null];
        }

        return [
            'path' => $label,
            'readable' => true,
            'contents' => $contents,
            'queues' => $this->execStartQueues($contents),
        ];
    }

    /**
     * The queues the unit actually tells the worker to consume.
     *
     * Anchored on the ExecStart directive, never on the file text. A unit that
     * merely MENTIONS a queue in a comment does not consume it, and a check
     * that accepted the mention would certify a pipeline that is still stalled.
     * systemd's own rules are followed: comment lines are dropped, and a
     * trailing backslash continues the directive onto the next line.
     *
     * @return list<string>|null null when the unit declares no ExecStart queue list at all
     */
    public function execStartQueues(string $contents): ?array
    {
        $logical = [];
        $buffer = '';

        foreach (preg_split('/\r?\n/', $contents) ?: [] as $line) {
            // A comment is only a comment when it opens a logical line; inside a
            // continuation systemd treats the text as part of the value.
            if ($buffer === '' && preg_match('/^\s*[#;]/', $line) === 1) {
                continue;
            }

            $trimmed = rtrim($line);

            if (str_ends_with($trimmed, '\\')) {
                $buffer .= rtrim(substr($trimmed, 0, -1)).' ';

                continue;
            }

            $logical[] = $buffer.$trimmed;
            $buffer = '';
        }

        if ($buffer !== '') {
            $logical[] = $buffer;
        }

        $queues = null;

        foreach ($logical as $entry) {
            if (preg_match('/^\s*ExecStart\s*=\s*(.*)$/', $entry, $m) !== 1) {
                continue;
            }

            if (preg_match_all('/--queue(?:=|\s+)([^\s\\\\]+)/', $m[1], $found) < 1) {
                continue;
            }

            foreach ($found[1] as $list) {
                foreach (explode(',', $list) as $name) {
                    $name = trim($name);

                    if ($name === '') {
                        continue;
                    }

                    $queues ??= [];

                    if (! in_array($name, $queues, true)) {
                        $queues[] = $name;
                    }
                }
            }
        }

        return $queues;
    }

    /**
     * @return list<string>
     */
    private function allowedQueueNames(): array
    {
        return array_values(array_map(
            'strval',
            (array) config('queue_governance.ent5_retry_failed_job.allowed_queue_names', [])
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return list<string>
     */
    private function namesOf(array $entries): array
    {
        $names = [];

        foreach ($entries as $entry) {
            $queue = $entry['queue'] ?? null;

            if (is_string($queue) && $queue !== '' && ! in_array($queue, $names, true)) {
                $names[] = $queue;
            }
        }

        sort($names);

        return $names;
    }

    /**
     * @param  list<string>  $needles
     * @param  list<string>  $haystack
     * @return list<string>
     */
    private function missingFrom(array $needles, array $haystack): array
    {
        return array_values(array_filter(
            $needles,
            static fn (string $needle): bool => ! in_array($needle, $haystack, true)
        ));
    }

    /**
     * Source files that route work to a named queue.
     *
     * Memoised for the life of the process. This walks and reads every PHP file
     * under the scanned paths — roughly 1300 files and 6.8 MB — and ten sibling
     * foundation governance services each call the ENT-5 readiness collector
     * while building one summary. Re-reading the tree ten times per summary is
     * pure waste: source on disk cannot change inside a single request, CLI
     * command or test process, which is exactly the lifetime of this cache.
     *
     * @param  list<string>  $paths
     * @param  list<string>  $patterns
     * @return array{hits: list<string>, unreadable: list<string>}
     */
    private function routingFiles(array $paths, array $patterns): array
    {
        static $memo = [];

        $key = md5(implode('|', $paths).'::'.implode('|', $patterns));

        if (isset($memo[$key])) {
            return $memo[$key];
        }

        $hits = [];
        $unreadable = [];

        foreach ($this->phpFiles($paths) as $file) {
            if (! is_readable($file)) {
                $unreadable[] = $file;

                continue;
            }

            $source = file_get_contents($file);

            if ($source === false) {
                // A file that cannot be read cannot be shown NOT to route work
                // to a queue, and this is the one check whose job is to notice
                // an unregistered producer. Treating it as "routes nothing"
                // would be a fail-open in exactly the wrong place.
                $unreadable[] = $file;

                continue;
            }

            foreach ($patterns as $pattern) {
                if (str_contains($source, $pattern)) {
                    $hits[] = $file;
                    break;
                }
            }
        }

        return $memo[$key] = ['hits' => $hits, 'unreadable' => $unreadable];
    }

    /**
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function phpFiles(array $paths): array
    {
        $existing = array_values(array_filter(
            array_map(
                // Repository-relative by convention; an absolute path is
                // honoured as given rather than being appended to the base path
                // and silently scanning nothing.
                static fn (string $path): string => str_starts_with($path, '/') ? $path : base_path($path),
                $paths
            ),
            'is_dir'
        ));

        if ($existing === []) {
            return [];
        }

        $files = [];

        foreach (Finder::create()->files()->in($existing)->name('*.php') as $file) {
            $real = $file->getRealPath();

            if (is_string($real) && $real !== '') {
                $files[] = $real;
            }
        }

        return $files;
    }

    private function relativePath(string $absolute): string
    {
        return str_replace(base_path().'/', '', $absolute);
    }

    /**
     * @return array<string, mixed>
     */
    private function config(): array
    {
        return (array) config('queue_governance.ent5_retry_failed_job.producer_consumer_contract', []);
    }
}
