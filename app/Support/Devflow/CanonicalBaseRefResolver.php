<?php

declare(strict_types=1);

namespace App\Support\Devflow;

use Illuminate\Support\Facades\Process;

/**
 * DEVFLOW-FIX-BASE-REF-1 — the single canonical base authority for DEVFLOW.
 *
 * Root problem this exists to kill: governance tooling used to diff against a
 * bare branch NAME, which git resolves to the LOCAL `refs/heads/<branch>`.
 * A local base ref that is stale, ahead, or diverged silently changed the
 * scope audit, the manifest check and the security-review diff — producing
 * false regressions and false "pre-existing failure" conclusions.
 *
 * Authority order (no other order is permitted):
 *   1. an explicit, verified, exact commit SHA (CI event / operator input)
 *   2. the canonical remote-tracking ref `<remote>/<branch>` after a fetch
 *   3. FAIL CLOSED
 *
 * There is deliberately NO fallback to `refs/heads/<branch>`, `main`,
 * `master`, `HEAD~1`, or the latest tag: that fallback is exactly how the
 * defect stayed invisible. A network failure produces an error, never a
 * governance PASS computed against stale local data.
 *
 * Read-only: it runs `fetch`/`rev-parse`/`cat-file`/`merge-base` and never
 * mutates the working tree, the index, or any local branch.
 */
final class CanonicalBaseRefResolver
{
    public const FAILURE_NO_BRANCH = 'BASE_BRANCH_NOT_CONFIGURED';

    public const FAILURE_INVALID_BRANCH = 'BASE_BRANCH_INVALID';

    public const FAILURE_INVALID_SHA = 'BASE_SHA_INVALID';

    public const FAILURE_OBJECT_MISSING = 'BASE_OBJECT_MISSING';

    public const FAILURE_NOT_A_COMMIT = 'BASE_OBJECT_NOT_A_COMMIT';

    public const FAILURE_AUTHORITY_UNAVAILABLE = 'BASE_AUTHORITY_UNAVAILABLE';

    public const FAILURE_REMOTE_AMBIGUOUS = 'BASE_REMOTE_AMBIGUOUS';

    public const FAILURE_WRONG_REPOSITORY = 'BASE_WRONG_REPOSITORY';

    /** Pinned for the lifetime of this instance (one command invocation). */
    private ?CanonicalBaseRef $pinned = null;

    public function __construct(private readonly string $basePath) {}

    /**
     * Resolve — and pin — the canonical base for this invocation.
     *
     * The first successful call wins: a later remote movement cannot change
     * an already-pinned run. A new command invocation resolves afresh.
     *
     * @param  string|null  $explicitSha  authoritative exact SHA (CI event, operator input)
     * @param  string|null  $baseBranch  overrides the configured canonical base branch
     */
    public function resolve(?string $explicitSha = null, ?string $baseBranch = null): CanonicalBaseRef
    {
        if ($this->pinned !== null) {
            return $this->pinned;
        }

        return $this->pinned = $this->doResolve($explicitSha, $baseBranch);
    }

    /** Drop the pin so a fresh authority is resolved on the next call. */
    public function forget(): void
    {
        $this->pinned = null;
    }

    /**
     * The repository root git itself reports for this base path, or null when
     * the path is not inside a work tree. Used to prove a governance tool is
     * analysing the checkout it was pointed at and not another one.
     */
    public function repositoryRoot(): ?string
    {
        $out = $this->git(['rev-parse', '--show-toplevel']);

        if ($out === null || trim($out) === '') {
            return null;
        }

        return realpath(trim($out)) ?: trim($out);
    }

    /**
     * Wrong-worktree guard. Returns null when the inspected repository root
     * matches the base path this resolver was constructed with, otherwise a
     * human-readable mismatch description.
     *
     * A mismatch means the caller is about to compare the WRONG checkout —
     * the failure mode that made an unrelated primary checkout leak into
     * security-review and Graphify evidence.
     */
    public function worktreeMismatch(): ?string
    {
        $expected = realpath($this->basePath) ?: $this->basePath;
        $actual = $this->repositoryRoot();

        if ($actual === null) {
            return "not inside a git work tree: {$expected}";
        }

        if ($actual !== $expected) {
            return "repository root mismatch: expected {$expected}, git reports {$actual}";
        }

        return null;
    }

    private function doResolve(?string $explicitSha, ?string $baseBranch): CanonicalBaseRef
    {
        $config = (array) config('devflow.base_resolution', []);
        $remote = $this->canonicalRemote($config);
        $branch = $this->resolveBranchName($baseBranch);
        $headSha = $this->revParse('HEAD');
        $diagnostics = [];

        // A tool pointed at the wrong checkout must never silently compare it.
        if (($mismatch = $this->worktreeMismatch()) !== null) {
            return CanonicalBaseRef::unavailable(
                self::FAILURE_WRONG_REPOSITORY,
                $mismatch.' — refusing to compare a different checkout.',
                branch: $branch,
                remote: $remote,
                headSha: $headSha,
            );
        }

        // ---- Authority 1: explicit exact SHA -------------------------------
        $explicitSha = $this->firstNonEmpty([$explicitSha, $this->explicitShaFromEnvironment($config)]);

        if ($explicitSha !== null) {
            return $this->resolveFromExplicitSha($explicitSha, $branch, $remote, $headSha);
        }

        // ---- Authority 2: canonical remote-tracking ref ---------------------
        if ($branch === null || $branch === '') {
            return CanonicalBaseRef::unavailable(
                self::FAILURE_NO_BRANCH,
                'No canonical base branch is configured (devflow.manifest.required_base_branch).',
                remote: $remote,
                headSha: $headSha,
            );
        }

        if (! $this->isSafeBranchName($branch)) {
            return CanonicalBaseRef::unavailable(
                self::FAILURE_INVALID_BRANCH,
                "Canonical base branch '{$branch}' is not a safe ref name.",
                branch: $branch,
                remote: $remote,
                headSha: $headSha,
            );
        }

        if ($remote === null) {
            return CanonicalBaseRef::unavailable(
                self::FAILURE_REMOTE_AMBIGUOUS,
                'Canonical remote could not be determined unambiguously. Configure devflow.base_resolution.remote.',
                branch: $branch,
                headSha: $headSha,
            );
        }

        // The local branch ref is read for DIAGNOSTICS ONLY. It is never an
        // authority and never a fallback.
        $localSha = $this->revParse('refs/heads/'.$branch);

        if ((bool) ($config['fetch_enabled'] ?? true)) {
            $fetched = $this->fetchRemoteBranch($remote, $branch, $config);
            if (! $fetched) {
                $diagnostics[] = "fetch of {$remote}/{$branch} failed; using the already-present remote-tracking ref only if it exists";
            }
        } else {
            $diagnostics[] = 'fetch disabled by configuration; relying on the existing remote-tracking ref';
        }

        $remoteRef = 'refs/remotes/'.$remote.'/'.$branch;
        $remoteSha = $this->revParse($remoteRef);

        if ($remoteSha === null) {
            return CanonicalBaseRef::unavailable(
                self::FAILURE_AUTHORITY_UNAVAILABLE,
                "Canonical base '{$remote}/{$branch}' could not be resolved (fetch failed or the remote-tracking ref is absent). "
                    .'Refusing to fall back to the local branch ref — run `git fetch '.$remote.' '.$branch.'` and retry, '
                    .'or supply an authoritative exact base SHA.',
                branch: $branch,
                remote: $remote,
                localSha: $localSha,
                headSha: $headSha,
                diagnostics: $diagnostics,
            );
        }

        if (! $this->isCommit($remoteSha)) {
            return CanonicalBaseRef::unavailable(
                self::FAILURE_NOT_A_COMMIT,
                "Canonical base '{$remote}/{$branch}' does not resolve to a commit object.",
                branch: $branch,
                remote: $remote,
                localSha: $localSha,
                headSha: $headSha,
                diagnostics: $diagnostics,
            );
        }

        $stale = $localSha !== null && $localSha !== $remoteSha;
        if ($stale) {
            $diagnostics[] = "local refs/heads/{$branch} ({$this->short($localSha)}) differs from canonical {$remote}/{$branch} ({$this->short($remoteSha)}); the canonical remote wins";
        }

        return new CanonicalBaseRef(
            resolved: true,
            source: CanonicalBaseRef::SOURCE_REMOTE_TRACKING_REF,
            branch: $branch,
            sha: $remoteSha,
            headSha: $headSha,
            mergeBaseSha: $this->mergeBase($remoteSha, $headSha),
            remote: $remote,
            localSha: $localSha,
            localStale: $stale,
            diagnostics: $diagnostics,
        );
    }

    private function resolveFromExplicitSha(string $sha, ?string $branch, ?string $remote, ?string $headSha): CanonicalBaseRef
    {
        // An API that promises "exact SHA" must not quietly accept a revision
        // expression (HEAD, HEAD~1, branch^{}, --option, ...). Reject first,
        // resolve second.
        if (! $this->isExactShaSyntax($sha)) {
            return CanonicalBaseRef::unavailable(
                self::FAILURE_INVALID_SHA,
                'Explicit base SHA is not an exact commit id. Revision expressions and options are not accepted.',
                branch: $branch,
                remote: $remote,
                headSha: $headSha,
            );
        }

        if (! $this->objectExists($sha)) {
            // One controlled attempt to fetch the exact object, then fail closed.
            $config = (array) config('devflow.base_resolution', []);
            if ($remote !== null && (bool) ($config['fetch_enabled'] ?? true)) {
                $this->git(['fetch', '--no-tags', '--quiet', '--', $remote, $sha], (int) ($config['fetch_timeout_seconds'] ?? 120));
            }
        }

        if (! $this->objectExists($sha)) {
            return CanonicalBaseRef::unavailable(
                self::FAILURE_OBJECT_MISSING,
                'Explicit base SHA is syntactically valid but the object is not available in this repository and could not be fetched.',
                branch: $branch,
                remote: $remote,
                headSha: $headSha,
            );
        }

        if (! $this->isCommit($sha)) {
            return CanonicalBaseRef::unavailable(
                self::FAILURE_NOT_A_COMMIT,
                'Explicit base SHA does not resolve to a commit object (trees, blobs and unpeeled tags are not a comparison base).',
                branch: $branch,
                remote: $remote,
                headSha: $headSha,
            );
        }

        $localSha = ($branch !== null && $this->isSafeBranchName($branch))
            ? $this->revParse('refs/heads/'.$branch)
            : null;

        return new CanonicalBaseRef(
            resolved: true,
            source: CanonicalBaseRef::SOURCE_EXPLICIT_SHA,
            branch: $branch,
            sha: strtolower($sha),
            headSha: $headSha,
            mergeBaseSha: $this->mergeBase($sha, $headSha),
            remote: $remote,
            localSha: $localSha,
            localStale: $localSha !== null && $localSha !== strtolower($sha),
            diagnostics: ['authoritative exact base SHA supplied; no branch resolution performed'],
        );
    }

    // -----------------------------------------------------------------------
    // Validation
    // -----------------------------------------------------------------------

    /**
     * Exact object id only: 40 hex (sha1) or 64 hex (sha256). Abbreviations
     * and revision expressions are rejected on purpose — "exact" means exact.
     */
    public function isExactShaSyntax(string $sha): bool
    {
        $pattern = (string) config('devflow.base_resolution.exact_sha_pattern', '/^[0-9a-f]{40}(?:[0-9a-f]{24})?$/i');

        return preg_match($pattern, $sha) === 1;
    }

    /**
     * Conservative branch-name allowlist. Blocks leading dashes (option
     * injection), whitespace, and git revision metacharacters before the name
     * ever reaches a git argument list.
     */
    public function isSafeBranchName(string $branch): bool
    {
        $pattern = (string) config('devflow.base_resolution.safe_branch_pattern', '/^(?!-)[A-Za-z0-9._\/-]{1,200}$/');

        if (preg_match($pattern, $branch) !== 1) {
            return false;
        }

        // Reject git revision syntax that would silently change the meaning.
        foreach (['..', '~', '^', ':', '?', '*', '[', '\\', '@{'] as $needle) {
            if (str_contains($branch, $needle)) {
                return false;
            }
        }

        return ! str_ends_with($branch, '.lock') && ! str_ends_with($branch, '/');
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    private function resolveBranchName(?string $override): ?string
    {
        $branch = $this->firstNonEmpty([
            $override,
            // Single source of truth — the same key the manifest validator uses.
            (string) config('devflow.manifest.required_base_branch', ''),
        ]);

        return $branch;
    }

    /**
     * Explicit remote only. "First remote wins" is deliberately not
     * implemented: with both `origin` and `upstream` present, guessing is how
     * a governance tool ends up comparing against the wrong fork.
     *
     * @param  array<string,mixed>  $config
     */
    private function canonicalRemote(array $config): ?string
    {
        $configured = trim((string) ($config['remote'] ?? ''));

        if ($configured === '') {
            return null;
        }

        if (! $this->isSafeBranchName($configured)) {
            return null;
        }

        $remotes = $this->git(['remote']);
        if ($remotes === null) {
            // Cannot enumerate remotes (not a repo / git failure). The
            // configured name is still the only candidate we would ever use;
            // downstream resolution fails closed if it does not exist.
            return $configured;
        }

        $available = array_values(array_filter(array_map('trim', explode("\n", $remotes)), static fn ($r) => $r !== ''));

        if ($available === []) {
            return null;
        }

        return in_array($configured, $available, true) ? $configured : null;
    }

    /**
     * @param  array<string,mixed>  $config
     */
    private function explicitShaFromEnvironment(array $config): ?string
    {
        $keys = (array) ($config['explicit_sha_env'] ?? []);

        foreach ($keys as $key) {
            $value = getenv((string) $key);
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $config
     */
    private function fetchRemoteBranch(string $remote, string $branch, array $config): bool
    {
        $timeout = (int) ($config['fetch_timeout_seconds'] ?? 120);

        // Explicit refspec into the remote-tracking namespace. `--` separates
        // options from operands so a hostile ref name can never become a flag.
        $refspec = "+refs/heads/{$branch}:refs/remotes/{$remote}/{$branch}";

        return $this->git(['fetch', '--no-tags', '--quiet', '--', $remote, $refspec], $timeout) !== null;
    }

    private function revParse(string $ref): ?string
    {
        $out = $this->git(['rev-parse', '--verify', '--quiet', $ref.'^{commit}']);

        if ($out === null) {
            return null;
        }

        $sha = trim($out);

        return $sha === '' ? null : strtolower($sha);
    }

    private function objectExists(string $sha): bool
    {
        return $this->git(['cat-file', '-e', $sha.'^{object}']) !== null;
    }

    private function isCommit(string $sha): bool
    {
        $type = $this->git(['cat-file', '-t', $sha]);

        return $type !== null && trim($type) === 'commit';
    }

    private function mergeBase(?string $base, ?string $head): ?string
    {
        if ($base === null || $head === null) {
            return null;
        }

        $out = $this->git(['merge-base', $base, $head]);

        if ($out === null || trim($out) === '') {
            return null;
        }

        return strtolower(trim($out));
    }

    /**
     * Safe subprocess git. Argument ARRAY only — never a shell string, never
     * interpolation, so no ref value can inject a shell or an option.
     *
     * @param  list<string>  $args
     */
    private function git(array $args, ?int $timeout = null): ?string
    {
        $pending = Process::path($this->basePath);

        if ($timeout !== null) {
            $pending = $pending->timeout($timeout);
        }

        try {
            $result = $pending->run(array_merge(['git'], $args));
        } catch (\Throwable) {
            return null;
        }

        return $result->successful() ? $result->output() : null;
    }

    /**
     * @param  list<string|null>  $candidates
     */
    private function firstNonEmpty(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }

    private function short(string $sha): string
    {
        return substr($sha, 0, 12);
    }
}
