<?php

namespace App\Support\Cicd;

/**
 * CICD-CRITICAL-GATE-FILE-GET-CONTENTS-WARN-1 — Critical Gate warning contract.
 *
 * The NSF-R011 Critical Test Gate used to conclude `success` while reporting
 * `Tests: 2222 warnings, 9 passed` — 128 of its 129 test files marked WARN. The
 * gate was truthful about failures (a failing test still fails it) but it had
 * no opinion at all about warnings, so 99.6% of its own output was noise and a
 * genuinely NEW warning would have been indistinguishable from the baseline.
 *
 * This class turns that into a declared, machine-checked contract: the gate
 * publishes how many warnings it expects, and anything above that is reported
 * as UNEXPLAINED and fails the gate. It replaces no existing signal — the test
 * exit status keeps strict precedence — it only adds the one that was missing.
 *
 * It is deliberately a plain, side-effect-free reader so the parsing rules are
 * unit-testable rather than buried in workflow shell.
 *
 * Resource-state discipline (the defect class this sprint exists to close):
 * an absent file, an unreadable file, a failed read and a legitimately empty
 * file are FOUR different states and are never collapsed into one another or
 * into an empty string. Every non-readable state fails closed — a Critical Gate
 * whose evidence cannot be read is never reported as clean.
 */
final class CriticalGateWarningContract
{
    /** The evidence log does not exist. */
    public const LOG_MISSING = 'LOG_MISSING';

    /** The evidence log exists but the process may not read it. */
    public const LOG_UNREADABLE = 'LOG_UNREADABLE';

    /** The read was attempted and returned failure — NOT the same as empty. */
    public const LOG_READ_FAILED = 'LOG_READ_FAILED';

    /** The read succeeded and the file is legitimately zero bytes. */
    public const LOG_EMPTY = 'LOG_EMPTY';

    /** The read succeeded and returned content. */
    public const LOG_READ = 'LOG_READ';

    public const DECISION_GO = 'GO';

    public const DECISION_NO_GO = 'NO_GO';

    /**
     * Categories Pest/PHPUnit can print in its `Tests:` summary line.
     *
     * @var list<string>
     */
    private const SUMMARY_CATEGORIES = [
        'passed', 'failed', 'errors', 'warnings', 'skipped',
        'risky', 'incomplete', 'deprecated', 'notices', 'todos',
    ];

    /**
     * Read the evidence log, keeping every failure mode distinguishable.
     *
     * @return array{state: string, contents: ?string, bytes: int}
     */
    public function readLog(string $logPath): array
    {
        if (! is_file($logPath)) {
            return ['state' => self::LOG_MISSING, 'contents' => null, 'bytes' => 0];
        }

        if (! is_readable($logPath)) {
            return ['state' => self::LOG_UNREADABLE, 'contents' => null, 'bytes' => 0];
        }

        /*
         * The existence and readability checks above are what make the read
         * itself expected to succeed, so a failure here is a genuine anomaly
         * and is reported as one. `false` is never folded into `''`: an
         * unreadable stream and an empty file mean opposite things.
         */
        $contents = file_get_contents($logPath);

        if ($contents === false) {
            return ['state' => self::LOG_READ_FAILED, 'contents' => null, 'bytes' => 0];
        }

        if ($contents === '') {
            return ['state' => self::LOG_EMPTY, 'contents' => '', 'bytes' => 0];
        }

        return ['state' => self::LOG_READ, 'contents' => $contents, 'bytes' => strlen($contents)];
    }

    /**
     * Extract the LAST `Tests:` summary line from a Pest/PHPUnit run.
     *
     * The last one is authoritative: a run can print the line more than once
     * (the workflow echoes it back into the step summary), and only the final
     * emission reflects the completed run.
     *
     * @return array{found: bool, line: ?string, counts: array<string, int>, total: int}
     */
    public function parseSummary(string $log): array
    {
        $matches = [];

        if (preg_match_all('/^[^\S\n]*Tests:[^\n]*/m', $log, $matches) === 0) {
            return ['found' => false, 'line' => null, 'counts' => [], 'total' => 0];
        }

        $line = trim((string) end($matches[0]));

        $counts = [];
        $pairs = [];
        preg_match_all('/(\d+)\s+([a-z]+)/', $line, $pairs, PREG_SET_ORDER);

        foreach ($pairs as $pair) {
            $category = $pair[2];

            if (! in_array($category, self::SUMMARY_CATEGORIES, true)) {
                // `(9328 assertions)` and similar trailing detail are not outcomes.
                continue;
            }

            $counts[$category] = (int) $pair[1];
        }

        return [
            'found' => true,
            'line' => $line,
            'counts' => $counts,
            'total' => array_sum($counts),
        ];
    }

    /**
     * Evaluate the Critical Gate warning contract for one evidence log.
     *
     * @return array{
     *     decision: string,
     *     log_state: string,
     *     log_path: string,
     *     summary_found: bool,
     *     summary_line: ?string,
     *     expected_warning_count: int,
     *     observed_warning_count: ?int,
     *     unexplained_warning_count: ?int,
     *     observed_failure_count: ?int,
     *     tests_reported: ?int,
     *     reasons: list<string>,
     *     remediation: list<string>
     * }
     */
    public function evaluate(string $logPath, int $expectedWarnings = 0): array
    {
        $expectedWarnings = max(0, $expectedWarnings);

        $result = [
            'decision' => self::DECISION_NO_GO,
            'log_state' => self::LOG_MISSING,
            'log_path' => $logPath,
            'summary_found' => false,
            'summary_line' => null,
            'expected_warning_count' => $expectedWarnings,
            'observed_warning_count' => null,
            'unexplained_warning_count' => null,
            'observed_failure_count' => null,
            'tests_reported' => null,
            'reasons' => [],
            'remediation' => [],
        ];

        $read = $this->readLog($logPath);
        $result['log_state'] = $read['state'];

        if ($read['state'] !== self::LOG_READ) {
            $result['reasons'][] = match ($read['state']) {
                self::LOG_MISSING => 'Critical Gate evidence log does not exist; the gate cannot be certified from absent evidence.',
                self::LOG_UNREADABLE => 'Critical Gate evidence log exists but is not readable by this process.',
                self::LOG_READ_FAILED => 'Critical Gate evidence log read failed after passing existence and readability checks.',
                self::LOG_EMPTY => 'Critical Gate evidence log is empty; no test run was recorded.',
                default => 'Critical Gate evidence log is in an unknown state.',
            };
            $result['remediation'][] = 'Confirm the test step wrote its evidence log before this assertion ran.';

            return $result;
        }

        $summary = $this->parseSummary((string) $read['contents']);
        $result['summary_found'] = $summary['found'];
        $result['summary_line'] = $summary['line'];

        if (! $summary['found']) {
            $result['reasons'][] = 'No test summary line was found in the Critical Gate evidence log.';
            $result['remediation'][] = 'A run that produced no summary did not complete; inspect the log for a fatal error.';

            return $result;
        }

        $observedWarnings = (int) ($summary['counts']['warnings'] ?? 0);
        $observedFailures = (int) ($summary['counts']['failed'] ?? 0)
            + (int) ($summary['counts']['errors'] ?? 0);

        $result['observed_warning_count'] = $observedWarnings;
        $result['observed_failure_count'] = $observedFailures;
        $result['tests_reported'] = $summary['total'];
        $result['unexplained_warning_count'] = max(0, $observedWarnings - $expectedWarnings);

        if ($summary['total'] === 0) {
            $result['reasons'][] = 'The Critical Gate summary reports zero tests; a gate that ran nothing certifies nothing.';
            $result['remediation'][] = 'Inspect the log for a fatal error or an over-narrow filter.';
        }

        /*
         * Failures are already enforced by the test step's exit status, which
         * keeps strict precedence over this contract. Repeating the check here
         * means the contract can never be the component that reports a run
         * containing failures as clean.
         */
        if ($observedFailures > 0) {
            $result['reasons'][] = sprintf(
                'The Critical Gate summary reports %d failing test(s)/error(s).',
                $observedFailures
            );
            $result['remediation'][] = 'Fix the failing tests; the warning contract never overrides a failure.';
        }

        if ($result['unexplained_warning_count'] > 0) {
            $result['reasons'][] = sprintf(
                'The Critical Gate reported %d warning(s) against a declared baseline of %d — %d unexplained.',
                $observedWarnings,
                $expectedWarnings,
                $result['unexplained_warning_count']
            );
            $result['remediation'][] = 'Identify the emitting component and the resource it reads, then represent that state explicitly at its causal boundary.';
            $result['remediation'][] = 'Do not raise the declared baseline to absorb a new warning and do not suppress the output.';
        }

        if ($result['reasons'] === []) {
            $result['decision'] = self::DECISION_GO;
        }

        return $result;
    }
}
