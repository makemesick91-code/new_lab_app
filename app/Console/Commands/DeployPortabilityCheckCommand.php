<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * STATELESS-1 — thin alias so pre-merge/CI/VPS-post-deploy scripts have a
 * "deploy:*" entry point without duplicating readiness logic.
 */
class DeployPortabilityCheckCommand extends Command
{
    protected $signature = 'deploy:portability-check
        {--write-test : Additionally write/read/delete a small healthcheck file}
        {--json : Output JSON}
        {--strict : Exit non-zero on any warning}
        {--fail-on-warning : Alias for --strict}';

    protected $description = 'Alias for runtime:stateless-readiness-check, for use in deploy pipelines.';

    public function handle(): int
    {
        return $this->call('runtime:stateless-readiness-check', [
            '--write-test' => $this->option('write-test'),
            '--json' => $this->option('json'),
            '--strict' => $this->option('strict'),
            '--fail-on-warning' => $this->option('fail-on-warning'),
        ]);
    }
}
