<?php

namespace App\Console\Commands;

use App\Support\Android\AndroidReleaseArtifactVerifier;
use Illuminate\Console\Command;

/**
 * REVISION-DOCTOR-ANDROID-DIRECT-APK-SIGNING-DISTRIBUTION-1.
 *
 * The gate an authorised Admin/IT installer runs before touching a tablet, and
 * the publisher runs before upload. Same command, both ends of the channel.
 *
 * It reads only public material — the APK and a certificate fingerprint — so
 * anyone who may install can also verify. Requiring the signing key to verify a
 * release would make verification something only the signer could perform,
 * which defeats the purpose.
 *
 * Exit code is the contract: non-zero means DO NOT INSTALL.
 */
class AndroidVerifyReleaseCommand extends Command
{
    protected $signature = 'android:verify-release
        {apk : Path to the signed release APK}
        {manifest : Path to the matching .release.json manifest}
        {--json : Output the report as JSON}';

    protected $description = 'Verify a signed release APK against its release manifest (SHA-256, signer certificate, package, version, approval). Exits non-zero if it must not be installed.';

    public function handle(AndroidReleaseArtifactVerifier $verifier): int
    {
        $report = $verifier->verify(
            (string) $this->argument('apk'),
            (string) $this->argument('manifest'),
        );

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $report['status'] === 'PASS' ? 0 : 1;
        }

        $this->info('Android release verification — direct admin-managed APK');
        $this->newLine();

        $this->table(
            ['Check', 'Status', 'Detail'],
            array_map(
                fn (array $c): array => [$c['id'], $c['status'], $c['detail']],
                $report['checks'],
            ),
        );

        $this->newLine();

        if ($report['status'] === 'PASS') {
            $this->line('VERDICT: PASS — this artifact matches its manifest and may be installed.');

            return 0;
        }

        // Stated as an instruction, not a status line. Whoever runs this is
        // standing next to a tablet with a cable in their hand.
        $this->line('VERDICT: FAIL — DO NOT INSTALL. Failed: '.implode(', ', $report['failures']));
        $this->line('Do NOT uninstall the existing app to force it: uninstalling erases app data,');
        $this->line('destroys the Keystore device identity, and costs the device its enrolment.');

        return 1;
    }
}
