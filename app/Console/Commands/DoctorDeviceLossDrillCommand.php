<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\DoctorAccess\Services\DoctorGlobalEnforcementReadinessService;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Support\Carbon;

/**
 * REVISION-DOCTOR-PWA-WEBAUTHN-ONLY-ACCESS-1 Stage 3 — the missing producer
 * for `device_loss_runbook_rehearsed`.
 *
 * THE GAP THIS CLOSES. `DoctorGlobalEnforcementReadinessService` has validated
 * a device-loss rehearsal artifact since Half B, and nothing could write one.
 * A gate whose evidence has no producer can only ever report UNVERIFIED, which
 * is how a prerequisite quietly becomes decorative.
 *
 * WHAT THIS COMMAND CANNOT DO, ON PURPOSE.
 *
 * It cannot establish that a rehearsal happened. No command can: "a clinic
 * practised losing a tablet" is a fact about an afternoon, not a query. What it
 * does is make RECORDING one a deliberate, attributable act with a timestamp,
 * an operator name and an outcome — and make the alternatives awkward rather
 * than easy:
 *
 *   - `--create-template` writes a placeholder whose drill id carries the
 *     TEMPLATE marker, and the validator reports that UNVERIFIED. A template
 *     that validates is a template that gets signed; this one cannot be.
 *   - Recording `--outcome=passed` requires `--performed-by` and
 *     `--confirm-performed`, so nobody records a pass by reflex or by cron.
 *   - A FAILED rehearsal is recordable and is real evidence. A drill that ran
 *     and went badly is worth more than one nobody ran, and refusing to record
 *     it would push operators toward recording nothing.
 *
 * The artifact is written to the local disk at the path the validator reads,
 * so producer and consumer cannot drift to different files.
 */
class DoctorDeviceLossDrillCommand extends Command
{
    protected $signature = 'doctor:device-loss-drill
        {--show : Validate and display the current evidence without writing}
        {--create-template : Write a TEMPLATE placeholder, which deliberately does NOT satisfy the gate}
        {--record : Record a rehearsal that actually took place}
        {--drill-id= : Identifier for this rehearsal, e.g. SPN4-2026-09-18}
        {--runbook= : The runbook followed, e.g. docs/runbooks/doctor-device-loss-rehearsal.md}
        {--outcome= : passed|failed — the honest result}
        {--clinician-regained-access= : yes|no — did the clinician get back to patients}
        {--performed-by= : Who ran it. Required to record a pass}
        {--notes= : What happened, in one or two sentences}
        {--confirm-performed : Explicit attestation that this rehearsal actually took place}';

    protected $description = 'Record or inspect a doctor device-loss rehearsal (produces the evidence the Half-B gate validates).';

    public function __construct(
        private readonly FilesystemFactory $filesystem,
        private readonly DoctorGlobalEnforcementReadinessService $readiness,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $config = (array) config('doctor_global_enforcement_readiness.device_loss_rehearsal', []);
        $path = (string) ($config['evidence_path'] ?? '');

        if ($path === '') {
            $this->error('No evidence path is configured for the device-loss rehearsal.');

            return self::FAILURE;
        }

        if ($this->option('create-template')) {
            return $this->writeTemplate($config, $path);
        }

        if ($this->option('record')) {
            return $this->record($config, $path);
        }

        return $this->show($path);
    }

    private function show(string $path): int
    {
        $disk = $this->filesystem->disk('local');

        $this->line('Device-loss rehearsal evidence');
        $this->line('EVIDENCE_PATH='.$path);
        $this->line('EXISTS='.($disk->exists($path) ? 'yes' : 'no'));

        if ($disk->exists($path)) {
            $decoded = json_decode((string) $disk->get($path), true);

            if (is_array($decoded)) {
                foreach (['drill_id', 'environment', 'performed_at', 'outcome', 'clinician_regained_access', 'performed_by'] as $key) {
                    $this->line(strtoupper($key).'='.$this->scalar($decoded[$key] ?? '-'));
                }
            }
        }

        // The single source of truth for whether this satisfies the gate is the
        // READINESS ENGINE, not this command re-deciding it. Re-implementing the
        // verdict here is how a producer starts disagreeing with its consumer.
        // Keyed by prerequisite name, with a `prerequisite` field — not a list
        // with a `name` field. Reading it the other way returns nothing and
        // reports "unknown", which looks like a verdict and is an empty lookup.
        $report = $this->readiness->build();
        $row = (array) (($report['prerequisites'] ?? [])['device_loss_runbook_rehearsed'] ?? []);

        $this->newLine();
        $this->line('GATE_MEASURED='.$this->scalar($row['measured'] ?? 'unknown'));
        $this->line('GATE_BLOCKS_ACTIVATION='.$this->scalar($row['blocks_activation'] ?? '-'));
        $this->line('GATE_EVIDENCE='.$this->scalar($row['evidence'] ?? '-'));

        return self::SUCCESS;
    }

    private function writeTemplate(array $config, string $path): int
    {
        $marker = (string) ($config['template_marker'] ?? 'TEMPLATE');

        $payload = [
            'schema_version' => (int) ($config['schema_version'] ?? 1),
            'drill_id' => $marker.'-replace-me',
            'environment' => (string) app()->environment(),
            'performed_at' => Carbon::now()->toIso8601String(),
            'runbook' => 'docs/runbooks/doctor-device-loss-rehearsal.md',
            'outcome' => 'not_performed',
            'clinician_regained_access' => false,
            'performed_by' => null,
            'notes' => 'Placeholder. Replace by running the rehearsal and recording it with --record.',
        ];

        $this->filesystem->disk('local')->put($path, (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->warn('Template written to '.$path.'.');
        $this->warn('It carries the '.$marker.' marker, so the gate reports UNVERIFIED — by design.');
        $this->warn('A template that satisfied the gate would be a template somebody signs.');

        return self::SUCCESS;
    }

    private function record(array $config, string $path): int
    {
        $marker = (string) ($config['template_marker'] ?? 'TEMPLATE');
        $passing = (string) ($config['passing_outcome'] ?? 'passed');

        $drillId = trim((string) $this->option('drill-id'));
        $runbook = trim((string) $this->option('runbook'));
        $outcome = trim((string) $this->option('outcome'));
        $regained = trim((string) $this->option('clinician-regained-access'));
        $performedBy = trim((string) $this->option('performed-by'));
        $notes = trim((string) $this->option('notes'));

        foreach (['drill-id' => $drillId, 'runbook' => $runbook, 'outcome' => $outcome, 'clinician-regained-access' => $regained] as $name => $value) {
            if ($value === '') {
                $this->error("--{$name} is required to record a rehearsal.");

                return self::FAILURE;
            }
        }

        if (! in_array($outcome, [$passing, 'failed'], true)) {
            $this->error("--outcome must be '{$passing}' or 'failed'. A rehearsal that ran and failed is evidence too.");

            return self::FAILURE;
        }

        if (! in_array($regained, ['yes', 'no'], true)) {
            $this->error('--clinician-regained-access must be yes or no.');

            return self::FAILURE;
        }

        // The template marker must never reach a real record, even by accident.
        if ($marker !== '' && str_contains($drillId, $marker)) {
            $this->error("--drill-id must not contain '{$marker}'. That marker is what makes a placeholder refuse to count.");

            return self::FAILURE;
        }

        // A recorded PASS is the only value that can move the gate, so it is the
        // only one that demands an attributable human attestation.
        if ($outcome === $passing) {
            if ($performedBy === '') {
                $this->error('--performed-by is required to record a passing rehearsal.');

                return self::FAILURE;
            }

            if (! $this->option('confirm-performed')) {
                $this->error('--confirm-performed is required to record a passing rehearsal.');
                $this->line('This records that the drill ACTUALLY TOOK PLACE. Nothing here can verify that,');
                $this->line('which is exactly why it has to be asserted deliberately by the person who ran it.');

                return self::FAILURE;
            }
        }

        $payload = [
            'schema_version' => (int) ($config['schema_version'] ?? 1),
            'drill_id' => $drillId,
            'environment' => (string) app()->environment(),
            'performed_at' => Carbon::now()->toIso8601String(),
            'runbook' => $runbook,
            'outcome' => $outcome,
            'clinician_regained_access' => $regained === 'yes',
            'performed_by' => $performedBy !== '' ? $performedBy : null,
            'notes' => $notes !== '' ? $notes : null,
        ];

        $this->filesystem->disk('local')->put($path, (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->info('Rehearsal recorded at '.$path.'.');

        return $this->show($path);
    }

    private function scalar(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => '-',
            is_scalar($value) => (string) $value,
            default => json_encode($value) ?: '-',
        };
    }
}
