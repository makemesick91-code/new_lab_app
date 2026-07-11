<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesSprintContext;
use App\Support\Devflow\SprintReleaseChecker;
use Illuminate\Console\Command;

/**
 * DEVFLOW-1 — pre-release GO/WATCH/NO-GO gate. NEVER creates a tag or deploys.
 */
final class SprintReleaseCheckCommand extends Command
{
    use ResolvesSprintContext;

    protected $signature = 'sprint:release-check
        {--manifest= : Path to the sprint manifest}
        {--ci-passed= : Explicitly assert CI status (true|false) as evidence input}
        {--ci-evidence= : Path to a JSON file with {"ci_passed":bool}}
        {--json : Output JSON}
        {--strict : Return non-zero on WATCH as well as NO-GO}';

    protected $description = 'Verify a sprint is ready to merge/tag/deploy. Read-only — creates nothing.';

    public function handle(): int
    {
        $manifest = $this->loadManifest();
        if ($manifest === null) {
            $this->error('No readable manifest at '.$this->manifestPath());

            return self::FAILURE;
        }

        $ciEvidence = $this->resolveCiEvidence();

        $checker = new SprintReleaseChecker(base_path(), $this->gitInspector());
        $result = $checker->check($manifest, $ciEvidence);

        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line('Release-check decision: '.$result['decision']);
            foreach ($result['checks'] as $check) {
                $mark = $check['status'] === 'passed' ? '✓' : ($check['status'] === 'warning' ? '!' : '✗');
                $line = "  {$mark} {$check['id']}: {$check['message']}";
                $check['status'] === 'failed' ? $this->error($line) : ($check['status'] === 'warning' ? $this->warn($line) : $this->line($line));
            }
            $this->newLine();
            $this->line('This gate creates NO tag and deploys nothing. Run scripts/sprint-release.sh --dry-run next.');
        }

        if ($result['decision'] === 'NO-GO') {
            return self::FAILURE;
        }
        if ($result['decision'] === 'WATCH' && $this->option('strict')) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @return array{ci_passed:bool,ci_source:string}|null
     */
    private function resolveCiEvidence(): ?array
    {
        $flag = $this->option('ci-passed');
        if (is_string($flag) && $flag !== '') {
            return ['ci_passed' => filter_var($flag, FILTER_VALIDATE_BOOLEAN), 'ci_source' => 'flag'];
        }

        $path = $this->option('ci-evidence');
        if (is_string($path) && $path !== '' && is_file($this->toAbsolute($path))) {
            $data = json_decode((string) file_get_contents($this->toAbsolute($path)), true);
            if (is_array($data)) {
                return ['ci_passed' => (bool) ($data['ci_passed'] ?? false), 'ci_source' => $path];
            }
        }

        return null;
    }
}
