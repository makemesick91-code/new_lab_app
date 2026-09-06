<?php

namespace App\Console\Commands;

use App\Support\Android\Phase4aPilotPreparationScanner;
use Illuminate\Console\Command;

/**
 * PHASE4A-DOCTOR-ANDROID-PILOT-PREPARATION-1 — the gate an operator runs before
 * the activation sprint is allowed to start.
 *
 * Read-only, and safe on production: it reads configuration and the recorded
 * release manifest, holds no credential, opens no vault, reaches no network and
 * cannot enable anything. It is deliberately separate from
 * `android:release-readiness`, which answers whether a release could be made
 * safely. This one answers a different question — whether a pilot could be
 * STARTED safely — and the two must be able to disagree.
 *
 * Every run prints the activation boundary, in every output mode. The most
 * damaging thing this command could do is let a reader infer that a pilot has
 * begun, so the lines saying it has not are not optional.
 */
class AndroidPhase4aPilotReadinessCommand extends Command
{
    protected $signature = 'android:phase4a-pilot-readiness
        {--json : Output the report as JSON}
        {--strict : Exit non-zero when any check is not PASS}';

    protected $description = 'Audit Phase 4A doctor Android pilot preparation: pilot authority, the non-destructive device model, pilot-scoped enforcement, the release artifact, rollback and the activation boundary. Read-only, no secrets.';

    public function handle(Phase4aPilotPreparationScanner $scanner): int
    {
        $report = $scanner->scan();

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->render($report);
        }

        if ($report['status'] === 'FAIL') {
            return 1;
        }

        if ($this->option('strict') && $report['status'] !== 'GO') {
            return 1;
        }

        return 0;
    }

    /**
     * @param  array<string,mixed>  $report
     */
    private function render(array $report): void
    {
        $this->info('Phase 4A doctor Android pilot preparation — PHASE4A-DOCTOR-ANDROID-PILOT-PREPARATION-1');
        $this->newLine();

        $this->table(
            ['Check', 'Status', 'Detail'],
            array_map(
                fn (array $check): array => [$check['id'], $check['status'], $check['detail']],
                $report['checks'],
            ),
        );

        $summary = $report['summary'];

        $this->newLine();
        $this->line("Decision: {$report['status']}  ({$summary['passed']}/{$summary['total']} PASS, {$summary['watch']} WATCH, {$summary['failed']} FAIL)");

        // Only ever true when the decision is GO; the scanner derives it from
        // the verdict rather than asserting it, so this line cannot disagree
        // with the table above it.
        $this->line('PHASE4A_PILOT_PREPARATION='.($summary['phase4a_pilot_preparation'] ? 'GO' : 'NOT READY'));
        $this->line('PREPARATION_STATE='.$summary['preparation_state']);

        // Printed together because the whole point of this sprint is that these
        // three read false. A pilot model that quietly required a wiped device
        // is what made the recorded Phase 4A impossible to run.
        $this->line('PILOT_MODEL='.$summary['pilot_model']);
        $this->line('FACTORY_RESET_REQUIRED='.$this->bool($summary['factory_reset_required']));
        $this->line('DEVICE_OWNER_REQUIRED='.$this->bool($summary['device_owner_required']));
        $this->line('FULL_KIOSK_REQUIRED='.$this->bool($summary['full_kiosk_required']));

        // And these two together, because they are the pair a reader is most
        // likely to conflate. A mechanism that EXISTS is not a mechanism that is
        // ARMED, and the flag being armed while the scope covers nobody protects
        // no one at all.
        $this->line('ENFORCEMENT_SCOPE_MODE='.$summary['enforcement_scope_mode']);
        $this->line('ENFORCEMENT_SCOPE_USABLE='.$this->bool($summary['enforcement_scope_usable']));
        $this->line('ENFORCEMENT_FLAG_ARMED='.$this->bool($summary['enforcement_flag_armed']));

        $this->newLine();
        $this->line('-- Activation boundary: everything below is what this sprint did NOT do --');
        $this->line('APK_DISTRIBUTED='.$this->bool($summary['apk_distributed']));
        $this->line('APK_INSTALLED='.$this->bool($summary['apk_installed']));
        $this->line('TABLET_TOUCHED='.$this->bool($summary['tablet_touched']));
        $this->line('ADB_USED='.$this->bool($summary['adb_used']));
        $this->line('DEVICE_ENROLLED='.$this->bool($summary['device_enrolled']));
        $this->line('PILOT_ACTIVATED='.$this->bool($summary['pilot_activated']));
        $this->line('PILOT_BROWSER_DENIAL_ACTIVE='.$this->bool($summary['pilot_browser_denial_active']));
        $this->line('GLOBAL_ENFORCEMENT_ACTIVE='.$this->bool($summary['global_enforcement_active']));
    }

    /**
     * A missing boundary value prints `unknown`, never `false`.
     *
     * An absent assertion and a negative one are different facts, and printing
     * `false` for "the config no longer records this" would be the gate
     * inventing the reassurance it exists to verify.
     */
    private function bool(mixed $value): string
    {
        if ($value === null) {
            return 'unknown';
        }

        return $value ? 'true' : 'false';
    }
}
