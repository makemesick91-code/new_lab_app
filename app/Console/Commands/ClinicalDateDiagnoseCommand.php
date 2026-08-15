<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Clinical\ClinicalClock;
use App\Support\Clinical\ClinicalTimezone;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * LEGACY-RME-DATE-TZ-1 — read-only clinical calendar diagnostic.
 *
 * Answers one question on any deployment, including production:
 *
 *     "Which clinical calendar date does this instant fall on?"
 *
 * WHAT IT NEVER DOES. It writes no row, touches no clinical record, changes no
 * configuration, and — critically — never mutates the system clock or installs
 * a global test-now. A `--instant` is a pure calculation input passed straight
 * through ClinicalClock::toClinicalDate(); it exists only inside this process
 * and disappears when the command exits. That is what makes it safe to run on
 * the production host to prove the WITA midnight boundary without inventing a
 * clinical document or waiting until 16:00 UTC.
 *
 * WHY THIS EXISTS. The boundary defect is invisible from a config dump: the
 * value looks plausible either way. Only evaluating a known instant proves
 * which calendar the deployment is actually living on.
 *
 *   php artisan clinical:date-diagnose
 *   php artisan clinical:date-diagnose --instant=2026-08-13T15:59:59Z
 *   php artisan clinical:date-diagnose --instant=2026-08-13T16:00:00Z --json
 *
 * `--strict` additionally asserts the deployment is on the canonical clinical
 * timezone, which is what a release gate wants.
 */
class ClinicalDateDiagnoseCommand extends Command
{
    protected $signature = 'clinical:date-diagnose
        {--instant=* : One or more absolute instants to evaluate (ISO-8601; an unqualified value is read as UTC)}
        {--json : Emit the machine-readable report}
        {--strict : Fail unless the clinical timezone is the canonical value}';

    protected $description = 'Report the clinical calendar date for the current or a supplied instant (read-only)';

    public function handle(ClinicalClock $clock): int
    {
        $posture = $clock->inspect();

        $report = [
            'clinical_timezone' => $posture['effective'],
            'clinical_timezone_configured' => $posture['configured'],
            'clinical_timezone_valid' => $posture['valid'],
            'clinical_timezone_canonical' => $posture['canonical'],
            'expected_clinical_timezone' => $posture['expected'],
            // The application's technical instant frame, reported for contrast.
            // UTC here beside a WITA clinical timezone is the correct posture.
            'technical_timezone' => (string) config('app.timezone'),
            'process_default_timezone' => $posture['process_default'],
            'database_writes' => 0,
            'system_clock_mutated' => false,
            'evaluated' => [],
        ];

        if (! $posture['valid']) {
            $report['error'] = $posture['message'];

            $this->emit($report);
            $this->components->error((string) $posture['message']);

            return self::FAILURE;
        }

        $instants = $this->resolveInstants();

        foreach ($instants as $instant) {
            try {
                $utc = $instant === null
                    ? CarbonImmutable::now('UTC')
                    : CarbonImmutable::parse($instant, 'UTC')->utc();

                $report['evaluated'][] = [
                    'input' => $instant ?? '(now)',
                    'instant_utc' => $utc->toIso8601String(),
                    'clinical_datetime' => $utc->setTimezone((string) $posture['effective'])->toIso8601String(),
                    'clinical_date' => $clock->toClinicalDateString($utc),
                ];
            } catch (\Throwable $e) {
                // An unreadable instant is an error, never silently "today".
                $report['evaluated'][] = [
                    'input' => $instant ?? '(now)',
                    'error' => 'Unparseable instant: '.class_basename($e),
                ];
                $report['error'] = 'One or more instants could not be parsed.';
            }
        }

        $this->emit($report);

        if (isset($report['error'])) {
            return self::FAILURE;
        }

        if ($this->option('strict') && ! $posture['canonical']) {
            $this->components->error(sprintf(
                'Clinical timezone is "%s"; the canonical value is "%s".',
                (string) $posture['effective'],
                ClinicalTimezone::DEFAULT,
            ));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @return list<string|null>
     */
    private function resolveInstants(): array
    {
        /** @var list<string> $supplied */
        $supplied = array_values(array_filter(
            (array) $this->option('instant'),
            static fn (mixed $v): bool => is_string($v) && trim($v) !== '',
        ));

        return $supplied === [] ? [null] : $supplied;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function emit(array $report): void
    {
        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return;
        }

        $this->newLine();
        $this->line('<options=bold>Clinical calendar diagnostic</> (read-only)');
        $this->newLine();

        $this->line(sprintf('  Clinical timezone   : %s', (string) ($report['clinical_timezone'] ?? '(unresolved)')));
        $this->line(sprintf('  Canonical           : %s', $report['clinical_timezone_canonical'] ? 'yes' : 'NO'));
        $this->line(sprintf('  Technical timezone  : %s  (instants; unchanged by design)', (string) $report['technical_timezone']));
        $this->line(sprintf('  Process default     : %s', (string) $report['process_default_timezone']));
        $this->newLine();

        $rows = [];

        foreach ((array) $report['evaluated'] as $row) {
            $rows[] = [
                (string) $row['input'],
                (string) ($row['instant_utc'] ?? '-'),
                (string) ($row['clinical_date'] ?? ($row['error'] ?? '-')),
            ];
        }

        $this->table(['input', 'instant (UTC)', 'clinical date'], $rows);
        $this->line('  Database writes: 0 — this command never persists anything.');
        $this->newLine();
    }
}
