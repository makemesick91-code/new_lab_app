<?php

namespace App\Console\Commands;

use App\Modules\DoctorAccess\Services\DoctorGlobalEnforcementReadinessService;
use App\Modules\DoctorAccess\Support\DoctorGlobalEnforcementPrerequisite as Prerequisite;
use Illuminate\Console\Command;

/**
 * DOCTOR-ACCESS-GLOBAL-DEVICE-ENFORCEMENT-READINESS-1 — the Half-B gate an
 * operator runs before anybody proposes an activation window.
 *
 * `android:phase4a-pilot-readiness` asks whether the bounded pilot is prepared.
 * `doctor:estate-resilience` asks whether the hardware survives a loss.
 * `doctor:rollout-readiness` asks who is provisioned. This asks the question
 * none of them asks: are the five declared Half-B prerequisites MEASURED, and
 * does any recorded signature stand against what was measured?
 *
 * Read-only and safe on production. It writes nothing, arms nothing, signs
 * nothing, and cannot activate global enforcement. The rollback rehearsal it
 * runs moves this process's own configuration for the length of one call and
 * restores it before returning — it touches no environment file, runs no
 * `config:cache`, and leaves the deployment's posture exactly as it found it.
 *
 * It prints user ids and doctor names so an operator can recognise a person,
 * and deliberately prints no email, no identity number, no credential material
 * and no key.
 */
class DoctorHalfBReadinessCommand extends Command
{
    protected $signature = 'doctor:half-b-readiness
        {--json : Output the report as JSON}
        {--strict : Exit non-zero unless the machinery is READY}
        {--activation-preflight : Exit non-zero unless the WORLD is ready too — every prerequisite measured PASS}';

    protected $description = 'Measure the five global (Half B) doctor enforcement prerequisites and cross-check each against its recorded attestation. Read-only; arms nothing.';

    public function handle(DoctorGlobalEnforcementReadinessService $readiness): int
    {
        $report = $readiness->build();

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->render($report);
        }

        /*
         * A CONTRADICTION IS ALWAYS FATAL, --strict or not.
         *
         * Every other condition here describes a rollout in progress and is
         * allowed to exit 0 so this command is safe to put in a deploy chain —
         * a gate that reddens for the whole of a staged rollout is a gate that
         * gets removed from the chain. A signature standing against a
         * measurement is not a rollout in progress. It is a record of
         * something untrue about a clinical-scale action, and it fails
         * unconditionally.
         */
        if ($report['contradicting_prerequisites'] !== []) {
            return 1;
        }

        if ($this->option('strict') && $report['verdict'] !== DoctorGlobalEnforcementReadinessService::VERDICT_READY) {
            return 1;
        }

        /*
         * A SEPARATE EXIT CODE FOR A STRICTLY HARDER QUESTION, mirroring
         * `doctor:estate-resilience --activation-preflight`. `--strict` asks
         * whether the instruments are honest; this asks whether the fleet may
         * actually be enforced. The two are different questions and this
         * programme has been bitten by one flag answering both.
         */
        if ($this->option('activation-preflight')
            && $report['activation_prerequisites'] !== Prerequisite::PASS) {
            return 2;
        }

        return 0;
    }

    /**
     * @param  array<string,mixed>  $report
     */
    private function render(array $report): void
    {
        $this->info('Half-B (global doctor enforcement) readiness — DOCTOR-ACCESS-GLOBAL-DEVICE-ENFORCEMENT-READINESS-1');
        $this->newLine();

        $this->table(
            ['Prerequisite', 'Measured', 'Attested', 'Contradiction', 'Blocks activation'],
            array_map(static fn (array $row): array => [
                $row['prerequisite'],
                $row['measured'],
                $row['attested'] ? 'true' : ($row['signature_recorded'] ? 'false' : '— (no slot)'),
                $row['contradiction'] ? 'YES' : 'no',
                $row['blocks_activation'] ? 'YES' : 'no',
            ], array_values($report['prerequisites'])),
        );

        $this->newLine();

        foreach (array_values($report['prerequisites']) as $row) {
            $this->line('<options=bold>'.$row['prerequisite'].'</> — '.$row['evidence']);
        }

        if ($report['findings'] !== []) {
            $this->newLine();
            $this->warn('Findings:');

            foreach ($report['findings'] as $finding) {
                $this->line('  • ['.$finding['finding'].'] '.$finding['detail']);
            }
        }

        $this->newLine();

        $runtime = $report['runtime'];

        $this->line('Governance phase        : '.$runtime['governance_phase']);
        $this->line('Enforcement scope       : '.$runtime['enforcement_scope_mode']
            .' ['.implode(',', $runtime['enforcement_cohort']).']');
        $this->line('global_permitted        : '.var_export($runtime['global_permitted'], true));
        $this->line('Enforcement flag armed  : '.var_export($runtime['enforcement_flag_armed'], true));
        $this->line('Global enforcement LIVE : '.var_export($runtime['global_enforcement_active_live'], true));

        $this->newLine();

        /*
         * The two verdicts are printed together, always, with the sentence
         * that stops them being read as one. A reader who sees only the first
         * line is the reader this programme has to protect.
         */
        $this->line('<options=bold>HALF_B_READINESS (machinery)   : '.$report['verdict'].'</>');
        $this->line('<options=bold>ACTIVATION_PREREQUISITES (world): '.$report['activation_prerequisites'].'</>');
        $this->newLine();
        $this->line((string) $report['verdict_semantics']);
        $this->newLine();
        $this->line('authorizes_activation: '.var_export($report['authorizes_activation'], true));
    }
}
