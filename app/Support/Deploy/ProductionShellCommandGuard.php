<?php

namespace App\Support\Deploy;

use App\Support\DeveloperConsole\SensitiveValueMasker;

/**
 * Refuses an interactive-REPL invocation before it can reach production.
 *
 * The prohibition existed in four documents and the command was still run
 * against production twice. Documentation is not a control, and the repository
 * was actively teaching the opposite lesson: three tracked scripts invoked the
 * REPL, and two of them did it to ASK whether they were running on production
 * — so the forbidden command executed before the check that would have
 * refused it.
 *
 * Two surfaces, because there are two ways it recurs:
 *
 *   isForbidden() — one command about to be run, checked before it is run.
 *   scan()        — the tracked scripts, checked before any of them ships.
 *
 * The patterns are read from config on purpose. Holding them here would put
 * the forbidden literal inside the guard, and the guard would then be the
 * first thing its own scan reddened on.
 */
class ProductionShellCommandGuard
{
    public function __construct(
        private readonly string $basePath,
        private readonly SensitiveValueMasker $masker,
    ) {}

    /**
     * Every pattern this guard enforces, keyed by the name config gave it.
     *
     * @return array<string,string>
     */
    public function patterns(): array
    {
        /** @var array<string,string> $patterns */
        $patterns = (array) config('release_safety.forbidden_production_commands.patterns', []);

        return $patterns;
    }

    public function reason(): string
    {
        return (string) config('release_safety.forbidden_production_commands.reason', '');
    }

    /**
     * Does this single command line invoke the forbidden REPL?
     *
     * Checked against the command as it will be executed, so the wrapper does
     * not matter: `ssh host "php artisan …"` and a bare local invocation are
     * the same violation.
     */
    public function isForbidden(string $command): bool
    {
        return $this->matchedPatterns($command) !== [];
    }

    /**
     * The names of the patterns a command trips, for an operator who has to be
     * told WHICH rule stopped them rather than just that something did.
     *
     * @return array<int,string>
     */
    public function matchedPatterns(string $command): array
    {
        $matched = [];

        foreach ($this->patterns() as $name => $pattern) {
            if (preg_match($pattern, $command) === 1) {
                $matched[] = $name;
            }
        }

        return $matched;
    }

    /**
     * Scan the declared deploy and release scripts.
     *
     * `files_missing` is reported rather than skipped: a scan pointed at paths
     * that no longer exist would otherwise report PASS for having looked at
     * nothing, which is exactly how this class of control dies quietly.
     *
     * @return array{status:string,findings:array<int,array{file:string,line:int,pattern:string,excerpt:string}>,files_scanned:int,files_missing:array<int,string>}
     */
    public function scan(): array
    {
        $declared = (array) config('release_safety.forbidden_production_commands.scanned_files', []);

        return $this->scanPaths(array_map(
            fn (string $relative): string => $this->basePath.'/'.$relative,
            $declared,
        ));
    }

    /**
     * One bounded, MASKED line of context.
     *
     * Masked because this output is designed to be captured: `--json` feeds
     * release evidence, and evidence is committed. A command an operator hands
     * to `--command` is the operator's text, and it can carry a credential —
     * so the gate that exists to protect production must not become the thing
     * that writes a secret into a durable artifact.
     */
    public function excerpt(string $line): string
    {
        return mb_strimwidth($this->masker->mask(trim($line)), 0, 120, '…');
    }

    /**
     * @param  array<int,string>  $paths
     * @return array{status:string,findings:array<int,array{file:string,line:int,pattern:string,excerpt:string}>,files_scanned:int,files_missing:array<int,string>}
     */
    public function scanPaths(array $paths): array
    {
        $findings = [];
        $missing = [];
        $scanned = 0;

        foreach ($paths as $path) {
            if (! is_file($path)) {
                $missing[] = $path;

                continue;
            }

            $scanned++;

            // Bounded, like the sibling Android scanner. This runs on the
            // release path; a pathological file must not OOM the gate that is
            // standing between a forbidden command and production.
            $limit = (int) config('android_release.scanner.max_scanned_file_bytes', 1048576);
            $size = filesize($path);

            if ($size === false || $size > $limit) {
                $findings[] = [
                    'file' => $path,
                    'line' => 0,
                    'pattern' => 'unreadable',
                    'excerpt' => 'exceeds the scan size bound; refusing to read it',
                ];

                continue;
            }

            $lines = preg_split('/\R/', (string) file_get_contents($path)) ?: [];

            foreach ($lines as $index => $line) {
                foreach ($this->matchedPatterns($line) as $pattern) {
                    $findings[] = [
                        'file' => $path,
                        'line' => $index + 1,
                        'pattern' => $pattern,
                        // Trimmed and bounded: a finding has to be readable in
                        // a gate log without pasting an entire script line,
                        // and without becoming a channel for whatever else
                        // that line contained.
                        'excerpt' => $this->excerpt($line),
                    ];
                }
            }
        }

        return [
            'status' => $findings === [] && $missing === [] ? 'PASS' : 'FAIL',
            'findings' => $findings,
            'files_scanned' => $scanned,
            'files_missing' => $missing,
        ];
    }
}
