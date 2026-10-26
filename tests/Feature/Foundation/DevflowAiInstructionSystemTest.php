<?php

/*
|--------------------------------------------------------------------------
| DEV-AI-CLAUDE-MEMORY-OPTIMIZATION-1 — AI instruction system contract
|--------------------------------------------------------------------------
|
| Until 2026-10-26 CLAUDE.md carried 240 sprint records (1.56 MB). Claude Code
| loads that file into every session, so a session that only answered "OK"
| created 659,317 input tokens of cache before doing any work, and a
| 200K-context model could not open the repository at all.
|
| The records were moved verbatim to docs/sprints/claude-md-archive/ and the
| domain rules to path-scoped .claude/rules/*.md files. This suite is what
| keeps that true:
|
| - the always-loaded instructions stay within budget and keep every critical
|   global rule (CG-01..CG-24) with the content that makes it a rule;
| - every domain rule file is path-scoped by valid YAML whose globs match real
|   files (an unparseable or unscoped rule loads into EVERY session, silently
|   undoing the optimisation; a glob that matches nothing never loads at all);
| - every rule file stays under the on-demand truncation limit;
| - the archive is provably lossless against its manifest;
| - every referenced rule, doc and archive file exists, and the skills,
|   agents and knowledge-base entry points the instructions point at resolve.
*/

use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

const AI_INSTRUCTION_CLAUDE_MD_MAX_LINES = 200;
const AI_INSTRUCTION_CLAUDE_MD_MAX_BYTES = 25_000;
const AI_INSTRUCTION_RULE_MAX_CHARS = 30_000;

/** @return array<string, string> domain => absolute path */
function aiRuleFiles(): array
{
    $files = glob(base_path('.claude/rules/*.md')) ?: [];
    sort($files);
    $out = [];
    foreach ($files as $file) {
        $out[basename($file, '.md')] = $file;
    }

    return $out;
}

/** @return array{0: array<string, mixed>, 1: string} [frontmatter, body] */
function aiSplitFrontmatter(string $content): array
{
    if (! preg_match('/\A---\n(.*?)\n---\n(.*)\z/s', $content, $m)) {
        return [[], $content];
    }

    $parsed = Yaml::parse($m[1]);

    return [is_array($parsed) ? $parsed : [], $m[2]];
}

/** @return list<string> */
function aiTrackedFiles(): array
{
    static $files = null;

    if ($files === null) {
        $process = new Process(['git', 'ls-files', '-z'], base_path());
        $process->run();
        $files = $process->isSuccessful()
            ? array_values(array_filter(explode("\0", $process->getOutput())))
            : [];

        // Where git is unavailable (a containerised runner, an exported tree)
        // walk the filesystem instead, skipping generated and vendored trees.
        if ($files === []) {
            $skip = ['.git', 'vendor', 'node_modules', 'storage', 'bootstrap/cache', 'public/build', 'graphify-out'];
            $root = base_path();
            $iterator = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                function (SplFileInfo $entry) use ($root, $skip): bool {
                    $relative = ltrim(substr($entry->getPathname(), strlen($root)), '/');

                    return ! in_array($relative, $skip, true);
                },
            ));
            foreach ($iterator as $entry) {
                $files[] = ltrim(substr($entry->getPathname(), strlen($root)), '/');
            }
        }
    }

    return $files;
}

/**
 * Translate a Claude Code rule glob into a regex: `**` crosses directories,
 * `*` and `?` stay inside one path segment.
 */
function aiGlobToRegex(string $glob): string
{
    $regex = '';
    $length = strlen($glob);
    for ($i = 0; $i < $length; $i++) {
        $c = $glob[$i];
        if ($c === '*' && ($glob[$i + 1] ?? '') === '*') {
            $i++;
            if (($glob[$i + 1] ?? '') === '/') {
                $i++;
                $regex .= '(?:.*/)?';
            } else {
                $regex .= '.*';
            }
        } elseif ($c === '*') {
            $regex .= '[^/]*';
        } elseif ($c === '?') {
            $regex .= '[^/]';
        } else {
            $regex .= preg_quote($c, '#');
        }
    }

    return '#\A'.$regex.'\z#';
}

/** The critical global rules and the phrases that make each one a rule. */
function aiCriticalGlobalRules(): array
{
    return [
        'CG-01' => ['feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report', 'never `main`', 'devflow:base-ref-check'],
        'CG-02' => ['worktree', 'daengtisiams-kb-vault', 'never'],
        'CG-03' => ['git add -A', 'force-push', '--no-verify'],
        'CG-04' => ['FormRequest', 'RepositoryInterface', 'DB::transaction', 'no React or Vue', 'Never Controller → Model', 'RepositoryServiceProvider'],
        'CG-05' => ['BranchContext', 'branch_id', 'MAIN', '404'],
        'CG-06' => ['policy', 'Gate::before', 'service', '$user->can()'],
        'CG-07' => ['Maker', 'checker', 'transaction'],
        'CG-08' => ['FeatureFlagService', 'default OFF'],
        'CG-09' => ['migrate --force', 'migrate:fresh', 'db:wipe', 'backup'],
        'CG-10' => ['trx_inventory_movements', 'ledger-only'],
        'CG-11' => ['KTP/NIK', 'masked', 'consent document', 'ClinicalEvidenceStorage', 'private'],
        'CG-12' => ['ClinicalClock', 'Asia/Makassar', 'WITA'],
        'CG-13' => ['fabricate', 'dry run', '--apply'],
        'CG-14' => ['srv1730088', 'https://daengtisia.online', 'tinker', 'daengtisiams'],
        'CG-15' => ['bash scripts/deploy-vps-runner.sh start', 'on the VPS', 'exit=0', 'DEPLOY OK', 'PermissionSeeder'],
        'CG-16' => ['go_tag', '-go', 'Never move, recreate or delete an existing tag', '--no-tags', 'fetch tags explicitly'],
        'CG-17' => ['exact candidate SHA', 'Skipped is not passed', 'paths-ignore'],
        'CG-18' => ['GLOBAL TEMPORARY FULL-SUITE POLICY', 'never as passed', '--all-required', '--regression'],
        'CG-19' => ['--filter', 'both', 'critical_gate_mandatory_suites'],
        'CG-20' => ['Storage::fake', 'setTestNow', 'PostgreSQL', 'branch isolation'],
        'CG-21' => ['Pre-existing', 'measured'],
        'CG-22' => ['owner decision'],
        'CG-23' => ['limit-saver', 'graphify query', 'graphify update .'],
        'CG-24' => ['docs/sprints/', '.cursor/rules/', 'DevflowAiInstructionSystemTest', 'the environment file', '-- never'],
    ];
}

it('keeps CLAUDE.md within the always-loaded instruction budget', function () {
    $claude = (string) file_get_contents(base_path('CLAUDE.md'));
    $lines = substr_count($claude, "\n") + (str_ends_with($claude, "\n") ? 0 : 1);

    expect($lines)->toBeLessThanOrEqual(AI_INSTRUCTION_CLAUDE_MD_MAX_LINES)
        ->and(strlen($claude))->toBeLessThanOrEqual(AI_INSTRUCTION_CLAUDE_MD_MAX_BYTES);
});

it('keeps sprint closure records out of CLAUDE.md', function () {
    $claude = (string) file_get_contents(base_path('CLAUDE.md'));
    preg_match_all('/^## (.+)$/m', $claude, $headings);

    // A sprint record heading looks like "SPRINT-ID — title (2026-…)". Those
    // belong in docs/sprints/, never in the always-loaded file.
    $sprintRecords = array_values(array_filter(
        $headings[1],
        fn (string $h) => (bool) preg_match('/\(20\d\d-\d\d-\d\d\)\s*$/', $h),
    ));

    expect($sprintRecords)->toBe([])
        ->and(count($headings[1]))->toBeLessThanOrEqual(10);
});

it('never @-imports files into CLAUDE.md', function () {
    $claude = (string) file_get_contents(base_path('CLAUDE.md'));

    // An @path import is expanded into every session, defeating the budget.
    expect(preg_match('/(^|\s)@[\w.\/-]+\.(md|mdc|txt)\b/m', $claude))->toBe(0);
});

it('keeps the Foundation-First lock and every critical global rule with its content', function () {
    $claude = (string) file_get_contents(base_path('CLAUDE.md'));

    expect($claude)->toContain('FOUNDATION-FIRST LOCK ACTIVE')
        ->and($claude)->toContain('docs/architecture/foundation-first-sprint-lock-governance.md');

    preg_match_all('/\*\*(CG-\d\d) /', $claude, $ids);
    $expected = array_keys(aiCriticalGlobalRules());
    expect($ids[1])->toBe($expected);

    foreach (aiCriticalGlobalRules() as $id => $phrases) {
        // The rule body runs from its id to the next rule id or heading.
        preg_match('/\*\*'.preg_quote($id, '/').' (.*?)(?=\n- \*\*CG-|\n### |\n## |\z)/s', $claude, $m);
        expect($m)->not->toBe([], "{$id} is missing");
        $body = preg_replace('/\s+/', ' ', $m[1]);
        foreach ($phrases as $phrase) {
            expect(str_contains($body, $phrase))->toBeTrue("{$id} lost its required content: {$phrase}");
        }
    }
});

it('names every domain rule file in CLAUDE.md and ships exactly those files', function () {
    $claude = (string) file_get_contents(base_path('CLAUDE.md'));
    preg_match('/Domain rule files in `\.claude\/rules\/`: (.*?)\. A rule file/s', $claude, $m);
    expect($m)->not->toBe([]);
    preg_match_all('/`([a-z0-9-]+)`/', $m[1], $listed);

    $shipped = array_keys(aiRuleFiles());
    $named = $listed[1];
    sort($named);

    expect($shipped)->toBe($named)
        ->and(count($shipped))->toBeGreaterThanOrEqual(10);
});

it('path-scopes every domain rule file with parseable YAML whose globs match tracked files', function () {
    $tracked = aiTrackedFiles();
    expect($tracked)->not->toBe([]);

    foreach (aiRuleFiles() as $domain => $file) {
        $content = (string) file_get_contents($file);
        expect(str_starts_with($content, "---\n"))->toBeTrue("{$domain}: frontmatter must open the file");

        [$frontmatter] = aiSplitFrontmatter($content);
        $paths = $frontmatter['paths'] ?? null;

        // An unscoped rule loads into every session at launch.
        expect(is_array($paths) && $paths !== [])->toBeTrue("{$domain}: `paths` must be a non-empty YAML list");

        foreach ($paths as $glob) {
            expect(is_string($glob) && $glob !== '')->toBeTrue("{$domain}: empty glob");
            expect(str_contains($glob, '{'))->toBeFalse("{$domain}: brace globs expand against a shared budget; list patterns instead ({$glob})");

            $regex = aiGlobToRegex($glob);
            $matched = false;
            foreach ($tracked as $path) {
                if (preg_match($regex, $path) === 1) {
                    $matched = true;
                    break;
                }
            }
            expect($matched)->toBeTrue("{$domain}: glob matches no tracked file, so the rule would never load: {$glob}");
        }
    }
});

it('keeps every domain rule file under the on-demand truncation limit', function () {
    foreach (aiRuleFiles() as $domain => $file) {
        $chars = mb_strlen((string) file_get_contents($file));

        // Claude Code truncates an on-demand rule past 40,000 characters in a
        // 200K-context session; the budget keeps headroom below that.
        expect($chars)->toBeLessThanOrEqual(AI_INSTRUCTION_RULE_MAX_CHARS, "{$domain}: {$chars} characters");
    }
});

it('gives every domain rule file uniquely numbered invariants under one prefix and the authority statement', function () {
    $seen = [];

    foreach (aiRuleFiles() as $domain => $file) {
        $content = (string) file_get_contents($file);

        // Markdown re-wraps freely; compare the sentence, not its line breaks.
        $flowing = (string) preg_replace('/\s+/', ' ', $content);
        expect($flowing)->toContain('Authority: code > canonical `.cursor/rules/` > this file > archive');
        expect($content)->toContain('## Invariants');

        preg_match_all('/^- \*\*(AIR-([A-Z]+)-(\d\d))\*\*/m', $content, $m);
        expect(count($m[1]))->toBeGreaterThanOrEqual(5, "{$domain}: too few invariants");
        expect(count(array_unique($m[2])))->toBe(1, "{$domain}: one rule-id prefix per file");

        $numbers = array_map('intval', $m[3]);
        expect($numbers)->toBe(range(1, count($numbers)), "{$domain}: invariants must be numbered 01..n in order");

        foreach ($m[1] as $id) {
            expect(isset($seen[$id]))->toBeFalse("duplicate rule id {$id}");
            $seen[$id] = $domain;
        }
    }
});

it('only references rule, doc and archive files that exist', function () {
    $sources = aiRuleFiles() + ['CLAUDE.md' => base_path('CLAUDE.md')];

    foreach ($sources as $name => $file) {
        $content = (string) file_get_contents($file);
        preg_match_all('#(?<![\w/.-])((?:\.cursor/rules|\.claude/rules|\.claude/skills|docs)/[\w./-]+\.(?:mdc|md|json))#', $content, $refs);

        foreach (array_unique($refs[1]) as $ref) {
            expect(file_exists(base_path($ref)))->toBeTrue("{$name} references a missing file: {$ref}");
        }
    }
});

it('keeps secrets, identity numbers and the environment-file name out of the instruction files', function () {
    $files = aiRuleFiles() + ['CLAUDE.md' => base_path('CLAUDE.md')];

    foreach ($files as $name => $file) {
        $content = (string) file_get_contents($file);

        expect(preg_match('/\b\d{16}\b/', $content))->toBe(0, "{$name}: a 16-digit run (NIK-shaped)")
            ->and(preg_match('/-----BEGIN [A-Z ]*PRIVATE KEY-----/', $content))->toBe(0, "{$name}: private key material")
            ->and(preg_match('/\b(APP_KEY|DB_PASSWORD|AWS_SECRET_ACCESS_KEY)\s*=/', $content))->toBe(0, "{$name}: a secret assignment")
            ->and(preg_match('/\bgh[pousr]_[A-Za-z0-9]{20,}/', $content))->toBe(0, "{$name}: a token")
            ->and(preg_match('/(?<![\w-])\.env(?![\w.-])/', $content))->toBe(0, "{$name}: the literal environment-file name");
    }
});

it('keeps the CLAUDE.md archive lossless against its manifest', function () {
    $dir = base_path('docs/sprints/claude-md-archive');
    $manifest = json_decode((string) file_get_contents($dir.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($manifest['section_count'])->toBe(count($manifest['sections']))
        ->and($manifest['section_count'])->toBe(240)
        ->and($manifest['source_file'])->toBe('CLAUDE.md');

    // Hash every section actually present in the archive files.
    $found = [];
    foreach (glob($dir.'/*.md') ?: [] as $file) {
        if (basename($file) === 'README.md') {
            continue;
        }
        $lines = explode("\n", (string) file_get_contents($file));
        $starts = array_keys(array_filter($lines, fn (string $l) => str_starts_with($l, '## ')));
        $starts = array_values($starts);
        foreach ($starts as $k => $i) {
            $j = $starts[$k + 1] ?? count($lines);
            $text = rtrim(implode("\n", array_slice($lines, $i, $j - $i)), "\n");
            $found[hash('sha256', $text)][] = basename($file);
        }
    }

    $declaredFiles = [];
    foreach ($manifest['sections'] as $section) {
        expect($found[$section['sha256']] ?? [])->toBe([$section['file']], "section {$section['n']} ({$section['heading']}) is missing or altered");
        $declaredFiles[$section['file']] = true;
    }

    // Nothing in the archive is unaccounted for.
    expect(array_sum(array_map('count', $found)))->toBe(count($manifest['sections']));

    $onDisk = array_map('basename', glob($dir.'/*.md') ?: []);
    $onDisk = array_values(array_diff($onDisk, ['README.md']));
    sort($onDisk);
    $declared = array_keys($declaredFiles);
    sort($declared);
    expect($onDisk)->toBe($declared);
});

it('feeds evidence tests from the archived records, never from the archive index', function () {
    // The README indexes every heading; if the evidence helper read it, an
    // assertion could pass on the index after the record itself was deleted.
    $memory = aiProjectMemory();

    expect($memory)->not->toContain('# CLAUDE.md archive — index')
        ->and($memory)->toContain('## ENT-10 — CI/CD Enterprise Gate (2026-07-06)');
});

it('points every archive section at a domain rule file that exists', function () {
    $manifest = json_decode((string) file_get_contents(base_path('docs/sprints/claude-md-archive/manifest.json')), true, flags: JSON_THROW_ON_ERROR);
    $domains = array_keys(aiRuleFiles());

    foreach ($manifest['sections'] as $section) {
        $domain = $section['rules_domain'];
        if ($domain === 'global') {
            continue;
        }
        expect(in_array($domain, $domains, true))->toBeTrue("section {$section['n']} maps to unknown rules domain {$domain}");
    }
});

it('registers every project skill and agent with a valid name and description', function () {
    foreach (glob(base_path('.claude/skills/*/SKILL.md')) ?: [] as $file) {
        [$frontmatter] = aiSplitFrontmatter((string) file_get_contents($file));
        $dir = basename(dirname($file));

        expect($frontmatter['name'] ?? null)->toBe($dir, "skill {$dir}: name must match its directory")
            ->and(trim((string) ($frontmatter['description'] ?? '')))->not->toBe('', "skill {$dir}: missing description");
    }

    foreach (glob(base_path('.claude/agents/*.md')) ?: [] as $file) {
        [$frontmatter] = aiSplitFrontmatter((string) file_get_contents($file));
        $name = basename($file, '.md');

        expect($frontmatter['name'] ?? null)->toBe($name, "agent {$name}: name must match its file")
            ->and(trim((string) ($frontmatter['description'] ?? '')))->not->toBe('', "agent {$name}: missing description");
    }

    expect(json_decode((string) file_get_contents(base_path('.claude/settings.json')), true))->toBeArray();
});

it('keeps the knowledge-base entry points the instructions depend on', function () {
    foreach ([
        'docs/00-INDEX.md',
        'docs/knowledge-base/module-index.md',
        'docs/knowledge-base/doc-status-registry.md',
        'docs/knowledge-base/conventions.md',
        'docs/knowledge-base/claude-code-workflow.md',
        'docs/sprints/claude-md-archive/README.md',
        '.claude/skills/knowledge-base/SKILL.md',
        '.claude/skills/sprint-doc-writer/SKILL.md',
    ] as $path) {
        expect(file_exists(base_path($path)))->toBeTrue("missing entry point {$path}");
    }

    // Existing is not enough: an emptied index still "exists". CLAUDE.md sends every session to
    // docs/00-INDEX.md, so the index must still route to the registry, the module index, the domain
    // rules and the archive.
    $index = (string) file_get_contents(base_path('docs/00-INDEX.md'));
    foreach (['knowledge-base/doc-status-registry.md', 'knowledge-base/module-index.md', '.claude/rules', 'sprints/claude-md-archive/README.md'] as $target) {
        expect($index)->toContain($target);
    }

    $workflow = (string) file_get_contents(base_path('docs/knowledge-base/claude-code-workflow.md'));
    expect($workflow)->toContain('.claude/rules/')
        ->and($workflow)->toContain('claude-md-archive');

    $kbSkill = aiSplitFrontmatter((string) file_get_contents(base_path('.claude/skills/knowledge-base/SKILL.md')))[1];
    expect($kbSkill)->toContain('docs/00-INDEX.md')
        ->and($kbSkill)->toContain('.claude/rules')
        ->and($kbSkill)->toContain('claude-md-archive');

    // The sprint-closure procedure must no longer append to CLAUDE.md. Read the BODY: the description
    // in the frontmatter repeats the phrase, so a whole-file match survives deleting the rule itself.
    $writer = aiSplitFrontmatter((string) file_get_contents(base_path('.claude/skills/sprint-doc-writer/SKILL.md')))[1];
    expect($writer)->toContain('docs/sprints/')
        ->and($writer)->toContain('Never append sprint records to CLAUDE.md')
        ->and($writer)->toContain('Never edit `docs/sprints/claude-md-archive/`');
});
