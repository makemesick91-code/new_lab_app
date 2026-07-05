<?php

namespace App\Console\Commands;

use App\Services\Architecture\FoundationGovernanceSummaryService;
use Illuminate\Console\Command;

class ArchitectureFoundationGovernanceSummaryCommand extends Command
{
    private const UNSAFE_EXIT = 10;

    protected $signature = 'architecture:foundation-governance-summary
        {--json : Output JSON report}
        {--output= : Write report under storage/app/architecture only}
        {--profile=local : Release safety/evidence profile to evaluate: local|ci|vps}';

    protected $description = 'Read-only combined NSF + DMO + DQ foundation governance summary.';

    public function handle(FoundationGovernanceSummaryService $service): int
    {
        $report = $service->collect((string) $this->option('profile'));

        $outputPath = $this->resolveOutputPath();
        if ($this->option('output') && $outputPath === null) {
            return self::UNSAFE_EXIT;
        }

        $payload = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($this->option('json')) {
            $this->line($payload);
        } else {
            $this->renderTextReport($report);
        }

        if ($outputPath !== null) {
            $this->writeOutputFile($outputPath, (string) $payload);
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function renderTextReport(array $report): void
    {
        $s = $report['summary'];
        $combinedReason = (string) ($s['combined_reason'] ?? $report['combined']['reason'] ?? '');

        $this->info('Foundation Governance Summary (FG-1)');
        $this->line('Generated: '.$report['generated_at']);
        $this->newLine();

        $this->line(sprintf(
            'NSF: %s (effective: %s) — %d warning(s), %d blocking',
            $s['nsf_decision'],
            $s['nsf_effective_decision'],
            $s['nsf_warnings'],
            $s['nsf_blocking_warnings'],
        ));
        $this->renderWatchCauses($report['watch_causes']['nsf'] ?? []);

        $this->newLine();
        $this->line(sprintf(
            'DMO: %s (effective: %s) — %d warning(s), %d blocking',
            $s['dmo_decision'],
            $s['dmo_effective_decision'],
            $s['dmo_warnings'],
            $s['dmo_blocking_warnings'],
        ));
        $this->renderWatchCauses($report['watch_causes']['dmo'] ?? []);

        $this->newLine();
        $dqChain = $report['dq_chain'] ?? [];
        $this->line('DQ: '.($dqChain['decision'] ?? $s['dq_decision'] ?? 'UNKNOWN'));
        foreach ($dqChain['items'] ?? [] as $item) {
            $this->line(sprintf('  - %s: %s', $item['id'], $item['decision']));
        }
        $this->renderWatchCauses($report['watch_causes']['dq'] ?? []);

        $this->newLine();
        $this->line(sprintf(
            'Combined: %s — %s',
            $s['combined_decision'],
            $combinedReason !== '' ? $combinedReason : 'see watch causes above',
        ));

        $this->newLine();
        $roadmap = $report['roadmap'] ?? [];
        $this->line(sprintf(
            'ROADMAP: %s (effective: %s) — track: %s',
            $roadmap['decision'] ?? 'UNKNOWN',
            $roadmap['effective_decision'] ?? 'UNKNOWN',
            $roadmap['active_track'] ?? 'n/a',
        ));
        $this->line(sprintf('  - next recommended sprint: %s', $roadmap['next_recommended_sprint'] ?? 'n/a'));
        $this->line(sprintf('  - total planned sprints: %d', $roadmap['total_planned_sprints'] ?? 0));
        $this->line(sprintf('  - RC-1 locked after expansion: %s', ($roadmap['rc_locked_after_expansion'] ?? false) ? 'yes' : 'no'));

        $this->newLine();
        $featureFlags = $report['feature_flags'] ?? [];
        $this->line(sprintf(
            'FEATURE_FLAGS: %s — %d flag(s) registered, %d risky-enabled',
            $featureFlags['decision'] ?? 'UNKNOWN',
            $featureFlags['total_flags'] ?? 0,
            count($featureFlags['risky_enabled_flags'] ?? []),
        ));

        $this->newLine();
        $cacheGovernance = $report['cache_governance'] ?? [];
        $this->line(sprintf(
            'CACHE_GOVERNANCE: %s — allowed: %d, denied: %d, redis runtime: %s',
            $cacheGovernance['decision'] ?? 'UNKNOWN',
            $cacheGovernance['allowed_categories_count'] ?? 0,
            $cacheGovernance['denied_categories_count'] ?? 0,
            ($cacheGovernance['redis_runtime_enabled'] ?? false) ? 'enabled' : 'disabled',
        ));

        $this->newLine();
        $queueGovernance = $report['queue_governance'] ?? [];
        $this->line(sprintf(
            'QUEUE_GOVERNANCE: %s — worker: %s, external dispatch flag: %s',
            $queueGovernance['decision'] ?? 'UNKNOWN',
            ($queueGovernance['long_running_worker_enabled'] ?? false) ? 'enabled' : 'disabled',
            ($queueGovernance['external_dispatch_enabled_flag'] ?? false) ? 'enabled' : 'disabled',
        ));

        $this->newLine();
        $idempotency = $report['idempotency'] ?? [];
        $this->line(sprintf('IDEMPOTENCY: %s — %d record(s)', $idempotency['decision'] ?? 'UNKNOWN', $idempotency['total_records'] ?? 0));

        $this->newLine();
        $outbox = $report['outbox'] ?? [];
        $this->line(sprintf('OUTBOX: %s — %d record(s)', $outbox['decision'] ?? 'UNKNOWN', $outbox['total_records'] ?? 0));

        $this->newLine();
        $releaseSafety = $report['release_safety'] ?? [];
        $this->line(sprintf('RELEASE_SAFETY: %s (profile: %s)', $releaseSafety['decision'] ?? 'UNKNOWN', $releaseSafety['profile'] ?? 'local'));

        $this->newLine();
        $releaseEvidence = $report['release_evidence'] ?? [];
        $this->line(sprintf('RELEASE_EVIDENCE: %s (profile: %s)', $releaseEvidence['decision'] ?? 'UNKNOWN', $releaseEvidence['profile'] ?? 'local'));

        $this->newLine();
        $backupVerification = $report['backup_verification'] ?? [];
        $this->line(sprintf('BACKUP_VERIFICATION: %s', $backupVerification['decision'] ?? 'NOT_APPLICABLE'));
        if (! empty($backupVerification['note'])) {
            $this->line('  - '.$backupVerification['note']);
        }

        $this->newLine();
        $automatedSmoke = $report['automated_smoke'] ?? [];
        $this->line(sprintf(
            'AUTOMATED_SMOKE: %s (mode: %s)',
            $automatedSmoke['decision'] ?? 'UNKNOWN',
            $automatedSmoke['mode'] ?? 'command_readiness_only',
        ));
        if (! empty($automatedSmoke['note'])) {
            $this->line('  - '.$automatedSmoke['note']);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $causes
     */
    private function renderWatchCauses(array $causes): void
    {
        if ($causes === []) {
            return;
        }

        foreach ($causes as $cause) {
            $blocking = ($cause['blocking'] ?? false) ? 'BLOCKER' : 'non-blocking';
            $this->line(sprintf(
                '  - %s [%s/%s]: %s',
                $cause['rule_id'] ?? 'UNKNOWN',
                $cause['classification'] ?? 'unknown',
                $blocking,
                $cause['message'] ?? '',
            ));
        }
    }

    private function resolveOutputPath(): ?string
    {
        $raw = $this->option('output');

        if ($raw === null || $raw === '') {
            return null;
        }

        $architectureRoot = realpath(storage_path('app/architecture')) ?: storage_path('app/architecture');

        if (! is_dir($architectureRoot)) {
            mkdir($architectureRoot, 0775, true);
            $architectureRoot = realpath($architectureRoot) ?: $architectureRoot;
        }

        $candidate = (string) $raw;

        if (! str_starts_with($candidate, '/')) {
            $candidate = ltrim($candidate, '/');
            $prefix = 'storage/app/architecture/';
            if (str_starts_with($candidate, $prefix)) {
                $candidate = substr($candidate, strlen($prefix));
            }
            $candidate = $architectureRoot.'/'.ltrim($candidate, '/');
        }

        $realCandidate = realpath(dirname($candidate));

        if ($realCandidate === false) {
            $parent = dirname($candidate);
            if (! is_dir($parent)) {
                mkdir($parent, 0755, true);
            }
            $realCandidate = realpath($parent);
        }

        $normalizedRoot = rtrim($architectureRoot, DIRECTORY_SEPARATOR);
        $normalizedCandidate = rtrim((string) $realCandidate, DIRECTORY_SEPARATOR);

        if ($normalizedCandidate !== $normalizedRoot && ! str_starts_with($normalizedCandidate.'/', $normalizedRoot.'/')) {
            $this->error('Output must be under storage/app/architecture.');

            return null;
        }

        return $candidate;
    }

    private function writeOutputFile(string $path, string $payload): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($path, $payload);
        $this->info('Wrote: '.$path);
    }
}
