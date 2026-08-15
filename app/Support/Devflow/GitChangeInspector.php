<?php

declare(strict_types=1);

namespace App\Support\Devflow;

use Illuminate\Support\Facades\Process;

/**
 * DEVFLOW-1 — read-only git change inspector.
 * DEVFLOW-FIX-BASE-REF-1 — now diffs against a canonical, pinned exact SHA.
 *
 * Resolves the set of changed files for the current work, using only safe,
 * arg-array git invocations (no shell string interpolation). Fail-closed:
 * when the diff can't be resolved, callers should treat the change set as
 * unknown/high-risk rather than empty.
 *
 * The comparison base is never a bare branch name. It is the exact commit SHA
 * produced by {@see CanonicalBaseRefResolver} (explicit SHA, else the canonical
 * remote-tracking ref, else fail closed) — so a stale, ahead, or diverged local
 * base ref cannot change what this inspector reports.
 */
final class GitChangeInspector
{
    public function __construct(
        private readonly string $basePath,
        private readonly ?CanonicalBaseRefResolver $baseResolver = null,
    ) {}

    private function resolver(): CanonicalBaseRefResolver
    {
        return $this->baseResolver ?? new CanonicalBaseRefResolver($this->basePath);
    }

    /**
     * Resolve (and pin) the canonical base authority for this inspector.
     */
    public function canonicalBase(?string $explicitSha = null, ?string $baseBranch = null): CanonicalBaseRef
    {
        return $this->resolver()->resolve($explicitSha, $baseBranch);
    }

    /**
     * Changed + untracked files relative to the repo root.
     *
     * `$baseRef` accepts an authoritative EXACT SHA or a base BRANCH NAME.
     * A branch name is treated as discovery input only — it is still resolved
     * through the canonical remote before any diff runs.
     *
     * @return array{files:list<string>, resolved:bool, base:string, reason:string, base_ref:CanonicalBaseRef}
     */
    public function changedFiles(?string $baseRef = null): array
    {
        $resolver = $this->resolver();

        [$explicitSha, $branchOverride] = $this->splitBaseInput($resolver, $baseRef);

        $base = $resolver->resolve($explicitSha, $branchOverride);

        if (! $base->resolved) {
            // FAIL CLOSED. Never silently diff against the local branch ref —
            // that is precisely how a stale base produced false conclusions.
            return [
                'files' => [],
                'resolved' => false,
                'base' => 'UNRESOLVED',
                'reason' => ($base->failureCode ?? 'BASE_AUTHORITY_UNAVAILABLE').': '.($base->failureReason ?? 'canonical base could not be resolved'),
                'base_ref' => $base,
            ];
        }

        $files = [];
        $resolved = true;

        // "Changes introduced since the branch point" — merge-base semantics,
        // computed FROM the pinned canonical SHA (never from a moving name).
        $comparison = $base->comparisonRef();
        $reason = 'diff against '.substr((string) $base->sha, 0, 12)." ({$base->source})";

        $diff = $comparison === null ? null : $this->runGit(['diff', '--name-only', $comparison, 'HEAD', '--']);

        if ($diff === null) {
            $resolved = false;
            $reason = 'unable to diff against the pinned canonical base '.substr((string) $base->sha, 0, 12).'; treating as unknown/high-risk';
        } else {
            $files = array_merge($files, $this->lines($diff));
        }

        // Uncommitted (staged + unstaged + untracked) working-tree changes.
        foreach ($this->workingTreeChanges() as $path) {
            $files[] = $path;
        }

        $files = array_values(array_unique(array_filter($files, static fn ($f) => $f !== '')));
        sort($files);

        return [
            'files' => $files,
            'resolved' => $resolved,
            'base' => (string) $base->sha,
            'reason' => $reason,
            'base_ref' => $base,
        ];
    }

    public function currentBranch(): ?string
    {
        $out = $this->runGit(['rev-parse', '--abbrev-ref', 'HEAD']);

        return $out === null ? null : trim($out);
    }

    public function headCommit(): ?string
    {
        $out = $this->runGit(['rev-parse', 'HEAD']);

        return $out === null ? null : trim($out);
    }

    public function isWorktreeClean(): bool
    {
        $out = $this->runGit(['status', '--porcelain']);

        return $out !== null && trim($out) === '';
    }

    public function tagAtHead(): ?string
    {
        $out = $this->runGit(['describe', '--tags', '--exact-match', 'HEAD']);

        return $out === null ? null : trim($out);
    }

    public function tagExists(string $tag): bool
    {
        $out = $this->runGit(['tag', '--list', $tag]);

        return $out !== null && trim($out) !== '';
    }

    /**
     * A caller-supplied base is either an exact SHA (comparison authority) or a
     * branch name (discovery authority). Anything else is passed through as a
     * branch name so the resolver's own validation rejects it.
     *
     * @return array{0:?string,1:?string}
     */
    private function splitBaseInput(CanonicalBaseRefResolver $resolver, ?string $baseRef): array
    {
        if ($baseRef === null || trim($baseRef) === '') {
            return [null, null];
        }

        $baseRef = trim($baseRef);

        return $resolver->isExactShaSyntax($baseRef) ? [$baseRef, null] : [null, $baseRef];
    }

    /**
     * Uncommitted (staged + unstaged + untracked) working-tree paths.
     *
     * Parsed from NUL-terminated porcelain so paths containing spaces, quotes
     * or non-ASCII bytes survive intact.
     *
     * Regression note (DEVFLOW-FIX-BASE-REF-1): the previous implementation
     * trimmed each line BEFORE slicing at offset 3. Porcelain emits a leading
     * status column (" M path"), so trimming shifted every MODIFIED tracked
     * path left by one character — `app/Policies/X.php` arrived as
     * `pp/Policies/X.php` and silently stopped matching the security /
     * migration contradiction patterns. That is a fail-OPEN in exactly the
     * checks this sprint exists to make trustworthy.
     *
     * @return list<string>
     */
    private function workingTreeChanges(): array
    {
        // -z    : NUL-terminated records — paths with spaces survive intact.
        // -uall : list untracked FILES individually. The default collapses a
        //         wholly-new directory to "newdir/", which no extension- or
        //         filename-based contradiction pattern can ever match (a new
        //         module folder full of .js or migration files would slip past).
        // core.quotePath=false : no C-style escaping of non-ASCII bytes.
        $status = $this->runGit(['-c', 'core.quotePath=false', 'status', '--porcelain', '-z', '-uall']);

        if ($status === null) {
            return [];
        }

        $records = array_values(array_filter(explode("\0", $status), static fn ($r) => $r !== ''));
        $paths = [];

        for ($i = 0; $i < count($records); $i++) {
            $record = $records[$i];

            // "XY <path>" — status is exactly two columns plus one space.
            if (strlen($record) < 4) {
                continue;
            }

            $x = $record[0];
            $y = $record[1];
            $path = substr($record, 3);

            if ($path !== '') {
                $paths[] = $path;
            }

            // Rename/copy entries are followed by the ORIGINAL path as its own
            // record. Consume it and keep it: the old path changed too.
            if ($x === 'R' || $x === 'C' || $y === 'R' || $y === 'C') {
                $i++;
                if (isset($records[$i]) && $records[$i] !== '') {
                    $paths[] = $records[$i];
                }
            }
        }

        return $paths;
    }

    /**
     * @param  list<string>  $args
     */
    private function runGit(array $args): ?string
    {
        $result = Process::path($this->basePath)->run(array_merge(['git'], $args));

        return $result->successful() ? $result->output() : null;
    }

    /**
     * @return list<string>
     */
    private function lines(string $text): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", $text)), static fn ($l) => $l !== ''));
    }
}
