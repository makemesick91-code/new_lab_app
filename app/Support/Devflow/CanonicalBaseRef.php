<?php

declare(strict_types=1);

namespace App\Support\Devflow;

/**
 * DEVFLOW-FIX-BASE-REF-1 — immutable result of a canonical base resolution.
 *
 * Once constructed this object is the pinned comparison authority for the
 * whole command invocation: a remote branch that advances afterwards can no
 * longer change the outcome of the run that already resolved it.
 *
 * `branch` is the DISCOVERY authority (which branch we asked about).
 * `sha` is the COMPARISON authority (what every diff must actually use).
 */
final class CanonicalBaseRef
{
    public const SOURCE_EXPLICIT_SHA = 'explicit_sha';

    public const SOURCE_REMOTE_TRACKING_REF = 'remote_tracking_ref';

    public const SOURCE_GITHUB_PR_EVENT = 'github_pr_event';

    public const SOURCE_UNAVAILABLE = 'unavailable';

    /**
     * @param  string|null  $sha  exact 40/64-hex commit id, or null when unresolved
     * @param  string|null  $mergeBaseSha  merge-base(sha, HEAD) when computable
     * @param  string|null  $localSha  what the LOCAL branch ref points at (diagnostic only)
     * @param  list<string>  $diagnostics  human-readable, non-sensitive notes
     */
    public function __construct(
        public readonly bool $resolved,
        public readonly string $source,
        public readonly ?string $branch,
        public readonly ?string $sha,
        public readonly ?string $headSha = null,
        public readonly ?string $mergeBaseSha = null,
        public readonly ?string $remote = null,
        public readonly ?string $localSha = null,
        public readonly bool $localStale = false,
        public readonly ?string $failureCode = null,
        public readonly ?string $failureReason = null,
        public readonly array $diagnostics = [],
    ) {}

    /**
     * Fail-closed constructor. There is deliberately no "fall back to local"
     * path: an unresolvable canonical base is an error, never a silent
     * downgrade to whatever the local checkout happens to point at.
     *
     * @param  list<string>  $diagnostics
     */
    public static function unavailable(
        string $failureCode,
        string $failureReason,
        ?string $branch = null,
        ?string $remote = null,
        ?string $localSha = null,
        ?string $headSha = null,
        array $diagnostics = [],
    ): self {
        return new self(
            resolved: false,
            source: self::SOURCE_UNAVAILABLE,
            branch: $branch,
            sha: null,
            headSha: $headSha,
            mergeBaseSha: null,
            remote: $remote,
            localSha: $localSha,
            localStale: false,
            failureCode: $failureCode,
            failureReason: $failureReason,
            diagnostics: $diagnostics,
        );
    }

    /**
     * The ref a "changes introduced since the branch point" diff must use.
     * Falls back to the pinned base sha when no merge base exists (unrelated
     * histories) — never to a branch name.
     */
    public function comparisonRef(): ?string
    {
        return $this->mergeBaseSha ?? $this->sha;
    }

    /**
     * Machine-readable authority metadata for governance evidence.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'resolved' => $this->resolved,
            'base_source' => $this->source,
            'base_branch' => $this->branch,
            'base_sha' => $this->sha,
            'head_sha' => $this->headSha,
            'merge_base_sha' => $this->mergeBaseSha,
            'remote' => $this->remote,
            'local_base_sha' => $this->localSha,
            'local_base_stale' => $this->localStale,
            'failure_code' => $this->failureCode,
            'failure_reason' => $this->failureReason,
            'diagnostics' => $this->diagnostics,
        ];
    }

    /**
     * `KEY=value` lines for command output. Stable contract — governance
     * evidence and the runbook both key off these exact names.
     *
     * @return list<string>
     */
    public function toKeyValueLines(): array
    {
        $lines = [
            'BASE_SOURCE='.$this->source,
            'BASE_BRANCH='.($this->branch ?? 'NONE'),
            'BASE_SHA='.($this->sha ?? 'UNRESOLVED'),
            'HEAD_SHA='.($this->headSha ?? 'UNKNOWN'),
        ];

        if ($this->mergeBaseSha !== null) {
            $lines[] = 'MERGE_BASE_SHA='.$this->mergeBaseSha;
        }
        if ($this->localSha !== null) {
            $lines[] = 'LOCAL_BASE_SHA='.$this->localSha;
            $lines[] = 'LOCAL_BASE_STALE='.($this->localStale ? 'YES' : 'NO');
        }
        if (! $this->resolved) {
            $lines[] = 'BASE_FAILURE='.($this->failureCode ?? 'UNKNOWN');
        }

        return $lines;
    }
}
