<?php

namespace App\Support\Documentation;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;

/**
 * ENT-15 — read-only Enterprise Documentation & Runbook scanner.
 *
 * Verifies the mandatory enterprise runbook set without mutating anything: every
 * declared runbook file exists; each carries the required actionable sections
 * (purpose, safe commands, forbidden commands, evidence, rollback, smoke,
 * security notes, owner, review cadence); every runbook documents the destructive
 * commands as forbidden and never shows a destructive literal outside a safe-zone
 * section; the runbook set collectively covers every required topic and
 * references every required foundation command; and no runbook or ENT-15
 * governance doc leaks a secret/PII forbidden pattern. It also verifies the
 * summary command is registered, the release-evidence profiles require the ENT-15
 * artifact, and the release-safety pre-deploy gate lists the ENT-15 command.
 *
 * All destructive literals come from config('enterprise_documentation'), so this
 * scanner source carries none inline (config-not-code convention).
 */
class EnterpriseDocumentationScanner
{
    /**
     * Read a repo-relative file; null when missing so callers can flag it.
     */
    private function readFile(string $relative): ?string
    {
        if ($relative === '') {
            return null;
        }

        $full = base_path($relative);
        if (! is_file($full)) {
            return null;
        }

        return (string) file_get_contents($full);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function runbooks(): array
    {
        return (array) config('enterprise_documentation.mandatory_runbooks', []);
    }

    /**
     * Registry posture: at least one mandatory runbook is declared and every
     * required topic maps to at least one runbook.
     *
     * @return array<string, mixed>
     */
    public function registryPosture(): array
    {
        $runbooks = $this->runbooks();
        $requiredTopics = (array) config('enterprise_documentation.required_topics', []);

        $covered = [];
        foreach ($runbooks as $runbook) {
            foreach ((array) ($runbook['topics'] ?? []) as $topic) {
                $covered[$topic] = true;
            }
        }

        $issues = [];
        if ($runbooks === []) {
            $issues[] = 'no mandatory runbooks declared';
        }

        $missingTopics = array_values(array_filter($requiredTopics, fn (string $t) => ! isset($covered[$t])));
        if ($missingTopics !== []) {
            $issues[] = 'runbook set missing required topic(s): '.implode(', ', $missingTopics);
        }

        return [
            'ok' => $issues === [],
            'runbook_count' => count($runbooks),
            'covered_topics' => array_keys($covered),
            'missing_topics' => $missingTopics,
            'issues' => $issues,
        ];
    }

    /**
     * Runbook file posture: every declared runbook file exists on disk.
     *
     * @return array<string, mixed>
     */
    public function runbookFilesPosture(): array
    {
        $issues = [];
        $present = [];

        foreach ($this->runbooks() as $key => $runbook) {
            $path = (string) ($runbook['path'] ?? '');
            $exists = $path !== '' && is_file(base_path($path));
            $present[$key] = $exists;
            if (! $exists) {
                $issues[] = "runbook file missing: {$key} ({$path})";
            }
        }

        return [
            'ok' => $issues === [],
            'present' => $present,
            'issues' => $issues,
        ];
    }

    /**
     * Required sections posture: every runbook contains every required section
     * heading (matched as a case-insensitive heading substring).
     *
     * @return array<string, mixed>
     */
    public function requiredSectionsPosture(): array
    {
        $required = (array) config('enterprise_documentation.required_sections', []);
        $issues = [];

        foreach ($this->runbooks() as $key => $runbook) {
            $contents = $this->readFile((string) ($runbook['path'] ?? ''));
            if ($contents === null) {
                continue; // file presence is reported by runbookFilesPosture()
            }

            $lower = strtolower($contents);
            $missing = [];
            foreach ($required as $section) {
                if (! str_contains($lower, strtolower((string) $section))) {
                    $missing[] = $section;
                }
            }

            if ($missing !== []) {
                $issues[] = "runbook {$key} missing section(s): ".implode(', ', $missing);
            }
        }

        return ['ok' => $issues === [], 'issues' => $issues];
    }

    /**
     * Forbidden-command declaration posture: every runbook documents each
     * destructive command as forbidden AND references its required foundation
     * commands.
     *
     * @return array<string, mixed>
     */
    public function forbiddenCommandDeclarationPosture(): array
    {
        $destructive = array_map('strval', (array) config('enterprise_documentation.forbidden_destructive_patterns', []));
        $issues = [];

        foreach ($this->runbooks() as $key => $runbook) {
            $contents = $this->readFile((string) ($runbook['path'] ?? ''));
            if ($contents === null) {
                continue;
            }

            $lower = strtolower($contents);

            $missingForbidden = array_values(array_filter(
                $destructive,
                fn (string $cmd) => ! str_contains($lower, strtolower($cmd))
            ));
            if ($missingForbidden !== []) {
                $issues[] = "runbook {$key} must document forbidden command(s): ".implode(', ', $missingForbidden);
            }

            $mustRef = array_map('strval', (array) ($runbook['must_reference_commands'] ?? []));
            $missingRef = array_values(array_filter(
                $mustRef,
                fn (string $cmd) => ! str_contains($lower, strtolower($cmd))
            ));
            if ($missingRef !== []) {
                $issues[] = "runbook {$key} must reference command(s): ".implode(', ', $missingRef);
            }
        }

        return ['ok' => $issues === [], 'issues' => $issues];
    }

    /**
     * Destructive-command safety posture: a destructive literal may only appear
     * inside a safe-zone section (forbidden/warning). Anywhere else is a
     * violation.
     *
     * @return array<string, mixed>
     */
    public function destructiveCommandSafetyPosture(): array
    {
        $issues = [];

        foreach ($this->runbooks() as $key => $runbook) {
            $contents = $this->readFile((string) ($runbook['path'] ?? ''));
            if ($contents === null) {
                continue;
            }

            foreach ($this->scanContentForUnsafeDestructive($contents) as $violation) {
                $issues[] = "runbook {$key}: {$violation}";
            }
        }

        return ['ok' => $issues === [], 'issues' => $issues];
    }

    /**
     * Public content scanner: returns human-readable violations for a destructive
     * literal that appears outside a safe-zone section. Exposed so tests can
     * assert detection on synthetic content without touching real files.
     *
     * @return list<string>
     */
    public function scanContentForUnsafeDestructive(string $contents): array
    {
        $destructive = array_map('strtolower', array_map('strval', (array) config('enterprise_documentation.forbidden_destructive_patterns', [])));
        $safeHeadings = array_map('strtolower', array_map('strval', (array) config('enterprise_documentation.destructive_safe_zone_headings', [])));

        $violations = [];
        $inSafeZone = false;

        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $lower = strtolower($line);

            if (preg_match('/^\s{0,3}#{1,6}\s/', $line) === 1) {
                // A markdown heading: recompute whether we are in a safe zone.
                $inSafeZone = false;
                foreach ($safeHeadings as $heading) {
                    if ($heading !== '' && str_contains($lower, $heading)) {
                        $inSafeZone = true;
                        break;
                    }
                }

                continue;
            }

            if ($inSafeZone) {
                continue;
            }

            foreach ($destructive as $pattern) {
                if ($pattern !== '' && str_contains($lower, $pattern)) {
                    $violations[] = "destructive command '{$pattern}' appears outside a forbidden/warning section";
                }
            }
        }

        return array_values(array_unique($violations));
    }

    /**
     * Sensitive-content posture: no runbook or ENT-15 governance doc contains a
     * secret/PII forbidden pattern or matches a forbidden regex (reuses the
     * release-evidence forbidden sets).
     *
     * @return array<string, mixed>
     */
    public function sensitiveContentPosture(): array
    {
        $patterns = array_map('strval', (array) config('release_evidence.forbidden_patterns', []));
        $regexes = array_map('strval', (array) config('release_evidence.forbidden_regex', []));

        $paths = [];
        foreach ($this->runbooks() as $runbook) {
            $paths[] = (string) ($runbook['path'] ?? '');
        }
        $paths[] = (string) config('enterprise_documentation.policy_doc', '');

        $issues = [];
        foreach (array_filter($paths) as $path) {
            $contents = $this->readFile($path);
            if ($contents === null) {
                continue;
            }

            foreach ($this->scanContentForSensitive($contents) as $hit) {
                $issues[] = "{$path}: {$hit}";
            }
            // regexes are not stored in scanContentForSensitive (which is used by
            // tests on short synthetic strings); apply them here too.
            foreach ($regexes as $regex) {
                if ($regex !== '' && preg_match($regex, $contents) === 1) {
                    $issues[] = "{$path}: matches forbidden regex {$regex}";
                }
            }
        }

        return ['ok' => $issues === [], 'issues' => $issues];
    }

    /**
     * Public content scanner for secret/PII forbidden patterns. Exposed so tests
     * can assert detection on synthetic content.
     *
     * @return list<string>
     */
    public function scanContentForSensitive(string $contents): array
    {
        $patterns = array_map('strval', (array) config('release_evidence.forbidden_patterns', []));
        $regexes = array_map('strval', (array) config('release_evidence.forbidden_regex', []));

        $hits = [];
        foreach ($patterns as $pattern) {
            if ($pattern !== '' && str_contains($contents, $pattern)) {
                $hits[] = "contains forbidden literal {$pattern}";
            }
        }
        foreach ($regexes as $regex) {
            if ($regex !== '' && preg_match($regex, $contents) === 1) {
                $hits[] = "matches forbidden regex {$regex}";
            }
        }

        return array_values(array_unique($hits));
    }

    /**
     * Foundation command linkage posture: every required foundation command is
     * referenced somewhere in the runbook set.
     *
     * @return array<string, mixed>
     */
    public function foundationCommandLinkagePosture(): array
    {
        $required = array_map('strval', (array) config('enterprise_documentation.required_command_references', []));

        $corpus = '';
        foreach ($this->runbooks() as $runbook) {
            $corpus .= "\n".(string) $this->readFile((string) ($runbook['path'] ?? ''));
        }
        $corpus = strtolower($corpus);

        $missing = array_values(array_filter($required, fn (string $cmd) => ! str_contains($corpus, strtolower($cmd))));

        $issues = [];
        if ($missing !== []) {
            $issues[] = 'runbook set missing command reference(s): '.implode(', ', $missing);
        }

        return ['ok' => $issues === [], 'missing' => $missing, 'issues' => $issues];
    }

    /**
     * Summary command posture: docs:enterprise-runbook-summary is registered.
     *
     * @return array<string, mixed>
     */
    public function summaryCommandPosture(): array
    {
        $command = (string) config('enterprise_documentation.summary_command', '');
        $registered = false;
        try {
            $all = app(ConsoleKernel::class)->all();
            $registered = $command !== '' && array_key_exists($command, $all);
        } catch (\Throwable) {
            $registered = false;
        }

        $issues = [];
        if (! $registered) {
            $issues[] = "summary command not registered: {$command}";
        }

        return ['ok' => $issues === [], 'summary_command' => $command, 'registered' => $registered, 'issues' => $issues];
    }

    /**
     * Release-evidence profile posture: the ENT-15 artifact and the ENT-12..14
     * sibling artifacts must be required in the configured profiles.
     *
     * @return array<string, mixed>
     */
    public function evidenceProfilePosture(): array
    {
        $artifact = (string) config('enterprise_documentation.evidence.artifact', '');
        $profiles = (array) config('enterprise_documentation.evidence.required_in_profiles', []);
        $siblings = (array) config('enterprise_documentation.evidence.required_sibling_artifacts', []);

        $issues = [];
        $perProfile = [];

        foreach ($profiles as $profile) {
            $required = (array) config("release_evidence.profiles.{$profile}.required_artifacts", []);

            $artifactPresent = in_array($artifact, $required, true);
            $missingSiblings = array_values(array_filter($siblings, fn (string $s) => ! in_array($s, $required, true)));

            if (! $artifactPresent) {
                $issues[] = "profile {$profile} missing artifact {$artifact}";
            }
            if ($missingSiblings !== []) {
                $issues[] = "profile {$profile} missing sibling artifact(s): ".implode(', ', $missingSiblings);
            }

            $perProfile[$profile] = ['artifact_present' => $artifactPresent, 'missing_siblings' => $missingSiblings];
        }

        return ['artifact' => $artifact, 'profiles' => $perProfile, 'ok' => $issues === [], 'issues' => $issues];
    }

    /**
     * Release-safety posture: the pre-deploy gate list includes the ENT-15
     * command.
     *
     * @return array<string, mixed>
     */
    public function releaseSafetyPosture(): array
    {
        $gates = (array) config('release_safety.required_pre_deploy_gates', []);
        $requiredGate = (string) config('enterprise_documentation.required_pre_deploy_gate_command', '');
        $gatePresent = $requiredGate !== '' && collect($gates)->contains(fn (string $gate) => str_contains($gate, $requiredGate));

        $issues = [];
        if (! $gatePresent) {
            $issues[] = "pre-deploy gate missing command: {$requiredGate}";
        }

        return ['ok' => $issues === [], 'pre_deploy_gate_ok' => $gatePresent, 'issues' => $issues];
    }
}
