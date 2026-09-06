<?php

namespace App\Console\Commands;

use App\Support\Android\AndroidReleaseGovernanceScanner;
use Illuminate\Console\Command;

/**
 * FEATURE-DOCTOR-TRUSTED-ANDROID-DEVICE-LOCK-1 Phase 3.5 — Android production
 * release readiness.
 *
 * Read-only. It reads config, files and the git index; it never signs, never
 * builds, never reaches the network, and holds no credential. Pull-request CI
 * can run it safely because there is nothing here for a fork to steal.
 *
 * It answers three things a human otherwise has to reconstruct by hand:
 *   1. Are the five decisions Phase 3 left open actually recorded?
 *   2. Could this tree produce an unsafe release (debug-signed, committed key,
 *      unusable wrapper)?
 *   3. Is Doctor device enforcement still off?
 *
 * What it deliberately does NOT do is claim a production key exists or that a
 * real device has been validated. Both are false as of Phase 3.5 and the
 * summary says so in every output mode.
 */
class AndroidReleaseReadinessCommand extends Command
{
    protected $signature = 'android:release-readiness
        {--json : Output the report as JSON}
        {--strict : Exit non-zero when any check is not PASS}
        {--manifest= : Path to a release metadata JSON file to validate against the recorded policy}';

    protected $description = 'Audit Android production release governance: signing custody, distribution, versioning, wrapper and the standing enforcement-off contract. Read-only, no secrets.';

    public function handle(AndroidReleaseGovernanceScanner $scanner): int
    {
        $report = $scanner->scan();

        if ($this->option('manifest')) {
            $report['release_manifest'] = $this->validateManifest($scanner, (string) $this->option('manifest'));
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->render($report);
        }

        // A failing manifest fails the command. The release runbook tells the
        // operator to run exactly this as a gate before publishing, so letting
        // a bad manifest exit 0 because the repository posture happened to be
        // GO would be the false-green this whole phase exists to prevent.
        if (($report['release_manifest']['status'] ?? 'PASS') === 'FAIL') {
            return 1;
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
     * @return array<string,mixed>
     */
    private function validateManifest(AndroidReleaseGovernanceScanner $scanner, string $path): array
    {
        if (! is_file($path)) {
            return ['status' => 'FAIL', 'detail' => 'Manifest file not found.', 'missing' => []];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded)) {
            return ['status' => 'FAIL', 'detail' => 'Manifest is not valid JSON.', 'missing' => []];
        }

        $result = $scanner->verifyReleaseManifest($decoded);

        return $result + ['detail' => $result['status'] === 'PASS'
            ? 'Release manifest carries every required provenance field.'
            : 'Release manifest is missing required provenance fields.'];
    }

    /**
     * @param  array<string,mixed>  $report
     */
    private function render(array $report): void
    {
        $this->info('Android production release readiness — FEATURE-DOCTOR-TRUSTED-ANDROID-DEVICE-LOCK-1 Phase 3.5');
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

        // Stated on every run, in every mode. The most damaging thing this
        // command could do is let a reader infer readiness it has not proven.
        $this->line('PRODUCTION_SIGNING_KEY_PROVISIONED='.($summary['production_signing_key_provisioned'] ? 'true' : 'false'));

        // PRODUCTION-ANDROID-SIGNING-KEY-PROVISIONING-1 printed these two
        // immediately under the line above, for the same reason the hardware
        // preflight is printed under the pilot validation.
        //
        // The line above now reads `true` for the first time, and the natural
        // next thought is "so we can install a signed build". We cannot. The
        // RECORDED fingerprint is evidence that a key exists; the PINNED one
        // is what arms `android:verify-release`, and while it is false that
        // command authenticates nothing and fails closed. An installer acting
        // on the provisioned line alone would install an artifact no control
        // has verified.
        $this->line('PRODUCTION_CERTIFICATE_RECORDED='.($summary['production_certificate_recorded'] ? 'true' : 'false'));
        $this->line('PRODUCTION_CERTIFICATE_PINNED='.($summary['production_certificate_pinned'] ? 'true' : 'false'));

        $this->line('ANDROID_REAL_DEVICE_VALIDATION='.($summary['real_device_validation'] ? 'true' : 'false'));
        // Printed immediately under the line it is most likely to be confused
        // with. The hardware preflight is a narrow gate: the key the app
        // generates lives in secure hardware. It is NOT the real-device pilot
        // validation above, and a reader who conflates them reads a signed
        // APK, an enrolled device and a run pilot into a keystore measurement.
        $this->line('ANDROID_REAL_DEVICE_HARDWARE_PREFLIGHT='.($summary['real_device_hardware_preflight'] ? 'PASS' : 'NOT PROVEN'));
        $this->line('DEVICE_ENFORCEMENT_ACTIVE='.($summary['device_enforcement_active'] ? 'true' : 'false'));

        if (isset($report['release_manifest'])) {
            $this->newLine();
            $this->line("Release manifest: {$report['release_manifest']['status']} — {$report['release_manifest']['detail']}");

            if ($report['release_manifest']['missing'] !== []) {
                $this->line('Missing: '.implode(', ', $report['release_manifest']['missing']));
            }
        }
    }
}
