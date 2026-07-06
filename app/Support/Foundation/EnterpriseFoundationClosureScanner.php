<?php

namespace App\Support\Foundation;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;

/**
 * ENT-16 — read-only Enterprise Foundation Closure scanner.
 *
 * Verifies the static, config/file-level preconditions for an enterprise
 * foundation closure GO WITHOUT running a deploy, backup, restore, load test,
 * migration, or any DB/queue command:
 *  - every ENT-1..ENT-16 roadmap entry is present and completed with a GO tag;
 *  - every mandatory ENT-5..15 gate declares its governance section, readiness
 *    command (registered) and GO tag;
 *  - the closure evidence artifact + sibling are declared in the ci/vps
 *    release-evidence profiles;
 *  - the closure command is a release-safety pre-deploy gate;
 *  - the ENT-16 CI-evidence gate is registered;
 *  - the deploy/rollback/backup/restore/load-test scripts and the mandatory
 *    runbooks exist;
 *  - the final closure tag is declared and referenced in the freeze rules;
 *  - no closure doc leaks a secret/PII forbidden pattern.
 *
 * All destructive literals come from config('enterprise_foundation_closure'),
 * so this scanner source carries none inline (config-not-code convention).
 */
class EnterpriseFoundationClosureScanner
{
    private function readFile(string $relative): ?string
    {
        if ($relative === '') {
            return null;
        }

        $full = base_path($relative);

        return is_file($full) ? (string) file_get_contents($full) : null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function roadmapById(): array
    {
        $sequence = (array) config('foundation_roadmap.approved_sequence', []);
        $byId = [];
        foreach ($sequence as $entry) {
            if (isset($entry['id'])) {
                $byId[(string) $entry['id']] = $entry;
            }
        }

        return $byId;
    }

    /**
     * Roadmap posture: every required ENT-1..ENT-16 id is present, completed,
     * and carries a non-empty go_tag; ENT-16 itself declares its governance
     * section and readiness command.
     *
     * @return array<string, mixed>
     */
    public function roadmapPosture(): array
    {
        $required = array_map('strval', (array) config('enterprise_foundation_closure.required_completed_roadmap_ids', []));
        $byId = $this->roadmapById();
        $issues = [];
        $completed = [];

        foreach ($required as $id) {
            $entry = $byId[$id] ?? null;
            if ($entry === null) {
                $issues[] = "roadmap entry missing: {$id}";

                continue;
            }
            if (($entry['status'] ?? null) !== 'completed') {
                $issues[] = "roadmap entry not completed: {$id} (status ".(string) ($entry['status'] ?? 'n/a').')';

                continue;
            }
            if (empty($entry['go_tag'])) {
                $issues[] = "roadmap entry missing go_tag: {$id}";

                continue;
            }
            $completed[] = $id;
        }

        $ent16 = $byId['ENT-16'] ?? [];
        if (($ent16['governance_section'] ?? '') !== 'enterprise_foundation_closure_governance') {
            $issues[] = 'ENT-16 must declare governance_section=enterprise_foundation_closure_governance';
        }
        if (($ent16['readiness_command'] ?? '') !== (string) config('enterprise_foundation_closure.required_pre_deploy_gate_command')) {
            $issues[] = 'ENT-16 must declare readiness_command=foundation:enterprise-closure-check';
        }

        return [
            'ok' => $issues === [],
            'completed_ids' => $completed,
            'completed_count' => count($completed),
            'issues' => $issues,
        ];
    }

    /**
     * Mandatory gate config posture: each ENT-5..15 gate declares its section,
     * readiness command (registered) and go_tag.
     *
     * @return array<string, mixed>
     */
    public function mandatoryGatesConfigPosture(): array
    {
        $gates = (array) config('enterprise_foundation_closure.mandatory_gates', []);
        $registered = $this->registeredCommands();
        $issues = [];

        foreach ($gates as $id => $gate) {
            foreach (['governance_section', 'readiness_command', 'go_tag'] as $field) {
                if (empty($gate[$field])) {
                    $issues[] = "gate {$id} missing {$field}";
                }
            }
            $command = (string) ($gate['readiness_command'] ?? '');
            if ($command !== '' && ! in_array($command, $registered, true)) {
                $issues[] = "gate {$id} readiness command not registered: {$command}";
            }
        }

        return [
            'ok' => $issues === [],
            'gate_count' => count($gates),
            'issues' => $issues,
        ];
    }

    /**
     * @return list<string>
     */
    private function registeredCommands(): array
    {
        try {
            return array_keys(app(ConsoleKernel::class)->all());
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Release-evidence profile posture: the closure artifact and sibling are
     * required in the configured profiles.
     *
     * @return array<string, mixed>
     */
    public function evidenceProfilePosture(): array
    {
        $artifact = (string) config('enterprise_foundation_closure.evidence.artifact', '');
        $profiles = (array) config('enterprise_foundation_closure.evidence.required_in_profiles', []);
        $siblings = (array) config('enterprise_foundation_closure.evidence.required_sibling_artifacts', []);
        $issues = [];

        foreach ($profiles as $profile) {
            $required = (array) config("release_evidence.profiles.{$profile}.required_artifacts", []);
            if (! in_array($artifact, $required, true)) {
                $issues[] = "profile {$profile} missing artifact {$artifact}";
            }
            foreach ($siblings as $sibling) {
                if (! in_array($sibling, $required, true)) {
                    $issues[] = "profile {$profile} missing sibling artifact {$sibling}";
                }
            }
        }

        return ['ok' => $issues === [], 'artifact' => $artifact, 'issues' => $issues];
    }

    /**
     * Release-safety posture: pre-deploy gate list includes the closure command.
     *
     * @return array<string, mixed>
     */
    public function releaseSafetyPosture(): array
    {
        $gates = (array) config('release_safety.required_pre_deploy_gates', []);
        $requiredGate = (string) config('enterprise_foundation_closure.required_pre_deploy_gate_command', '');
        $present = $requiredGate !== '' && collect($gates)->contains(fn (string $gate) => str_contains($gate, $requiredGate));

        return [
            'ok' => $present,
            'pre_deploy_gate_ok' => $present,
            'issues' => $present ? [] : ["pre-deploy gate missing command: {$requiredGate}"],
        ];
    }

    /**
     * CI evidence gate registry posture: foundation_governance declares ENT-16.
     *
     * @return array<string, mixed>
     */
    public function ciGateRegistryPosture(): array
    {
        $gates = (array) config('foundation_governance.ci_evidence_gates.gates', []);
        $present = array_key_exists('ENT-16', $gates);

        return [
            'ok' => $present,
            'issues' => $present ? [] : ['foundation_governance ci_evidence_gates missing ENT-16'],
        ];
    }

    /**
     * Scripts posture: required deploy/rollback/backup/restore/load-test scripts
     * exist on disk (closure verifies the operational chain, never runs it).
     *
     * @return array<string, mixed>
     */
    public function scriptsPosture(): array
    {
        $scripts = (array) config('enterprise_foundation_closure.required_scripts', []);
        $issues = [];
        foreach ($scripts as $key => $path) {
            if (! is_file(base_path((string) $path))) {
                $issues[] = "script missing: {$key} ({$path})";
            }
        }

        return ['ok' => $issues === [], 'issues' => $issues];
    }

    /**
     * Runbooks posture: required runbooks exist on disk.
     *
     * @return array<string, mixed>
     */
    public function runbooksPosture(): array
    {
        $runbooks = array_map('strval', (array) config('enterprise_foundation_closure.required_runbooks', []));
        $issues = [];
        foreach ($runbooks as $path) {
            if (! is_file(base_path($path))) {
                $issues[] = "runbook missing: {$path}";
            }
        }

        return ['ok' => $issues === [], 'issues' => $issues];
    }

    /**
     * Final closure tag posture: config declares the final tag and it is
     * referenced by the freeze rules doc so operators know closure ends the
     * freeze.
     *
     * @return array<string, mixed>
     */
    public function finalClosureTagPosture(): array
    {
        $tag = (string) config('enterprise_foundation_closure.final_closure_tag', '');
        $freeze = $this->readFile((string) config('enterprise_foundation_closure.closure_docs.freeze_rules_doc', ''));
        $issues = [];

        if ($tag === '') {
            $issues[] = 'final_closure_tag not declared';
        } elseif ($freeze === null || ! str_contains($freeze, $tag)) {
            $issues[] = "freeze rules doc does not reference final closure tag {$tag}";
        }

        return ['ok' => $issues === [], 'final_closure_tag' => $tag, 'issues' => $issues];
    }

    /**
     * Sensitive-content posture: no closure doc contains a secret/PII forbidden
     * pattern or matches a forbidden regex (reuses the release-evidence sets).
     *
     * @return array<string, mixed>
     */
    public function sensitiveContentPosture(): array
    {
        $paths = array_values(array_filter(array_map('strval', (array) config('enterprise_foundation_closure.closure_docs', []))));
        $issues = [];

        foreach ($paths as $path) {
            $contents = $this->readFile($path);
            if ($contents === null) {
                continue;
            }
            foreach ($this->scanContentForSensitive($contents) as $hit) {
                $issues[] = "{$path}: {$hit}";
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
     * Closure docs posture: the policy + runbook closure docs exist on disk.
     *
     * @return array<string, mixed>
     */
    public function closureDocsPosture(): array
    {
        $issues = [];
        foreach (['policy_doc', 'runbook_doc'] as $key) {
            $path = (string) config("enterprise_foundation_closure.closure_docs.{$key}", '');
            if ($path === '' || ! is_file(base_path($path))) {
                $issues[] = "closure doc missing: {$key} ({$path})";
            }
        }

        return ['ok' => $issues === [], 'issues' => $issues];
    }
}
