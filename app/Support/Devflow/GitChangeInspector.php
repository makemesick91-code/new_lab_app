<?php

declare(strict_types=1);

namespace App\Support\Devflow;

use Illuminate\Support\Facades\Process;

/**
 * DEVFLOW-1 — read-only git change inspector.
 *
 * Resolves the set of changed files for the current work, using only safe,
 * arg-array git invocations (no shell string interpolation). Fail-closed:
 * when the diff can't be resolved, callers should treat the change set as
 * unknown/high-risk rather than empty.
 */
final class GitChangeInspector
{
    public function __construct(private readonly string $basePath) {}

    /**
     * Changed + untracked files relative to the repo root.
     *
     * @return array{files:list<string>, resolved:bool, base:string, reason:string}
     */
    public function changedFiles(?string $baseRef = null): array
    {
        $base = $baseRef ?? (string) config('devflow.manifest.required_base_branch', 'HEAD');

        $files = [];
        $resolved = true;
        $reason = "diff against {$base}";

        // Committed diff vs the base branch (three-dot = merge-base).
        $diff = $this->runGit(['diff', '--name-only', "{$base}...HEAD"]);
        if ($diff === null) {
            // Fallback: two-dot, then working tree only.
            $diff = $this->runGit(['diff', '--name-only', $base]);
        }
        if ($diff === null) {
            $resolved = false;
            $reason = "unable to diff against {$base}; treating as unknown/high-risk";
        } else {
            $files = array_merge($files, $this->lines($diff));
        }

        // Uncommitted (staged + unstaged + untracked) working-tree changes.
        $status = $this->runGit(['status', '--porcelain']);
        if ($status !== null) {
            foreach ($this->lines($status) as $line) {
                // Format: "XY <path>" or "XY <old> -> <new>".
                $path = trim(substr($line, 3));
                if (str_contains($path, ' -> ')) {
                    $path = trim((string) substr(strrchr($path, '>'), 1));
                }
                if ($path !== '') {
                    $files[] = $path;
                }
            }
        }

        $files = array_values(array_unique(array_filter($files, static fn ($f) => $f !== '')));
        sort($files);

        return ['files' => $files, 'resolved' => $resolved, 'base' => $base, 'reason' => $reason];
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
        $status = $this->runGit(['status', '--porcelain']);

        return $status !== null && trim($status) === '';
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
     * @param  list<string>  $args
     */
    private function runGit(array $args): ?string
    {
        $result = Process::path($this->basePath)->run(array_merge(['git'], $args));

        return $result->successful() ? $result->output() : null;
    }

    private function lines(string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/', $text) ?: [])));
    }
}
