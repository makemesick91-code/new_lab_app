<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Devflow\CanonicalBaseRefResolver;
use App\Support\Devflow\DevflowScanner;
use Illuminate\Console\Command;

/**
 * DEVFLOW-FIX-BASE-REF-1 — inspect the canonical base authority.
 *
 * Read-only diagnostic. Answers, for the repository it is run in:
 *   which branch is the discovery authority,
 *   which EXACT commit is the comparison authority,
 *   where that authority came from,
 *   whether the local base ref is stale (informational only),
 *   and whether the resolver configuration still forbids every silent fallback.
 *
 * Never mutates a ref, never writes a file, never deploys.
 */
final class DevflowBaseRefCheckCommand extends Command
{
    protected $signature = 'devflow:base-ref-check
        {--base-sha= : Authoritative exact base commit SHA to verify instead of resolving the remote}
        {--base-branch= : Base branch to resolve through the canonical remote}
        {--json : Output JSON}
        {--strict : Return non-zero when the base cannot be resolved or the posture has issues}';

    protected $description = 'Report the canonical DEVFLOW base authority (branch, exact SHA, source) and verify the fail-closed posture.';

    public function handle(CanonicalBaseRefResolver $resolver, DevflowScanner $scanner): int
    {
        $base = $resolver->resolve(
            $this->stringOption('base-sha'),
            $this->stringOption('base-branch'),
        );

        $posture = $scanner->baseResolutionPosture();
        $mismatch = $resolver->worktreeMismatch();

        $payload = [
            'sprint' => 'DEVFLOW-FIX-BASE-REF-1',
            'repository_root' => $resolver->repositoryRoot(),
            'worktree_mismatch' => $mismatch,
            'base_authority' => $base->toArray(),
            'posture' => $posture,
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line('DEVFLOW canonical base authority');
            $this->line('  repository root : '.($payload['repository_root'] ?? 'UNKNOWN'));
            foreach ($base->toKeyValueLines() as $line) {
                $this->line('  '.$line);
            }
            foreach ($base->diagnostics as $diagnostic) {
                $this->line('  note: '.$diagnostic);
            }
            if ($mismatch !== null) {
                $this->error('  worktree mismatch: '.$mismatch);
            }
            if (! $base->resolved) {
                $this->error('  '.($base->failureReason ?? 'canonical base unresolved'));
            }
            foreach ($posture['issues'] as $issue) {
                $this->error('  posture: '.$issue);
            }
            if ($base->resolved && $posture['ok'] && $mismatch === null) {
                $this->info('  ✓ canonical base resolved; fail-closed posture intact.');
            }
        }

        $healthy = $base->resolved && $posture['ok'] && $mismatch === null;

        if ($this->option('strict') && ! $healthy) {
            return self::FAILURE;
        }

        // Without --strict a posture problem is still an error, but an
        // unresolvable base (e.g. deliberately offline) only fails under strict.
        return ($posture['ok'] && $mismatch === null) ? self::SUCCESS : self::FAILURE;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
