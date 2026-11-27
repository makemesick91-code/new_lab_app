<?php

use Symfony\Component\Yaml\Yaml;

uses()->group('Cicd', 'Ci', 'CiTriggerRemoval', 'FoundationGovernance');

/*
|--------------------------------------------------------------------------
| CI-CD-PR-ONLY-POLICY-1 phase B5 — governance contract (owner decisions 17, 18 and 19)
|--------------------------------------------------------------------------
|
| CG-16 gains one narrow class that closes without a deploy and receives no
| GO tag of its own: the owner-authorised removal of the `push` and `schedule`
| triggers of .github/workflows/foundation-evidence-gates.yml, and its only
| rollback, the byte-for-byte restoration of the pinned pre-removal file. The
| owner required the class to read the same in the rule (CLAUDE.md CG-16), in
| the register that implements it (docs/governance/go-tag-exceptions.md) and
| in the tests, and never to become a general exception for GitHub Actions.
|
| This file pins four things:
|  1. the rule and the register state the same, narrow classes;
|  2. the two approved workflow states (SHA-256) and a classifier that accepts
|     only the exact removal and the exact restoration, rejecting every other
|     change to the workflow;
|  3. the workflow invariants both changes must keep;
|  4. B5-RESIDUAL-1: the `config/` and `app/` texts that will still describe
|     the removed triggers. They stay OPEN, pinned here so that they can
|     neither be "fixed" nor grow silently, with the proof that nothing on the
|     runtime, deploy, release-guard or security path reads them.
|
| DMS-SPN4-READY-PILOT-1 G6 / REM-1 (CG-19; proposed with both SHA-256 values in
| docs/sprints/dms-spn4-ready-pilot-1.md §13.3; the owner's merge of this
| amendment is the decision, recorded under CG-22) adds two pinned states. REM-1 is NOT
| one of the CG-16 CI trigger classes: it edits the critical-gate filter, so it is
| released with its sprint (merge, deploy, verification, GO tag). It inserts
| exactly three tokens before the last token of BOTH critical filters
| (`ctrgSelect`). The B5 rollback stays available on the selected file: its
| target is the same insertion applied to the pre-removal file. Removing the
| tokens again would weaken the gate (CG-17) and is rejected.
*/

const CTRG_WORKFLOW = '.github/workflows/foundation-evidence-gates.yml';
const CTRG_BASE_BRANCH = 'feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report';

// The two approved states of the workflow (decision 19). Also pinned in the register and the sprint record.
const CTRG_PRE_REMOVAL_SHA256 = '978e90c028fe6530b6f4eaf422760e1a1f913ab9400dfb24a70a9310c215d3d4';
const CTRG_POST_REMOVAL_SHA256 = '4cd0c3dbe7c374e30f05659f89e7c22a2f197005da3d0f92f423124622b98cd0';

// REM-1 (CG-19): the post-removal file with the selection applied, and its B5 rollback target. Also pinned in the
// SPN4 sprint record.
const CTRG_CG19_SHA256 = '82150b8b56ec96a23fc9406ea0d406bd4c4e5612a1ea14569429475bd76c555a';
const CTRG_CG19_PRE_REMOVAL_SHA256 = '4335c4dc7db9b4d1053b377a3e766b42e17a0ad9d23df81263eee2265a68bdfb';
const CTRG_CG19_TOKENS = ['BranchContext', 'PatientDataCompleteness', 'LabIntegration'];
const CTRG_CG19_ANCHOR = "|LabClinicCalendarBoundary|InventoryClinicCalendarBoundary'";

// O-CI-1 (owner decision "REL-1 ACCEPTANCE AND POST-DEPLOYMENT INTEGRATION" §5, 2026-11-26): the hosted critical gate
// installs and selects the PostgreSQL 16 client. Like REM-1 it is NOT a CG-16 CI trigger class: it adds exactly one
// pinned block of two steps after the Poppler step of `critical_test_gate`, released with its sprint. It applies onto
// the REM-1 file and onto its B5 rollback target. Also pinned in the register and the SPN4 sprint record.
const CTRG_OCI1_SHA256 = '4bfd792d070ce26c61fd49899038df12795fdbd526615840d197120c00dceb43';
const CTRG_OCI1_PRE_REMOVAL_SHA256 = 'b89bfd69b76ef6297e72a990eda35ac6fcf5a871f4e4b57a1ae80970ec5988d3';
const CTRG_OCI1_BLOCK = 'tests/Fixtures/ci/o-ci-1-critical-gate-steps.yml.txt';
const CTRG_OCI1_BLOCK_SHA256 = '779915fd9de322461dfe85b2322928b9640829f98bdc7ddba85b6edff744350d';
const CTRG_OCI1_HEAD = "          pdftoppm -v\n\n";
const CTRG_OCI1_TAIL = '      # CICD-CTRL-3 — hard production-database isolation guard. Runs BEFORE any';

// PB-7 (owner directive "R6-B3 ACCEPTANCE AND PARALLEL CRITICAL-PATH EXECUTION" §3, 2026-11-27; Design Lock row
// R6-B7): both critical-gate variants run the PostgreSQL 16 role-separation tests in a dedicated, serial step against a
// private, synthetic cluster, failing closed. Like O-CI-1 it is NOT a CG-16 CI trigger class: it adds exactly one
// pinned step to each critical gate, before "Run governance audit commands", released with its sprint. It applies onto
// the O-CI-1 file and onto its B5 rollback target. Also pinned in the register and the SPN4 sprint record.
const CTRG_PB7_SHA256 = '345d59fdb70dcdc5596cda52593d4cfec4622ce81fe4313d97dccc6424194472';
const CTRG_PB7_PRE_REMOVAL_SHA256 = 'd11c71af2c4e64653d0435fc915c134cc56a2b68bf955e0444beb557a4dc3c09';
// The script holds all of PB-7's fail-closed logic, so it is pinned with the workflow (an edit is a re-pin).
const CTRG_PB7_SCRIPT = 'scripts/ci/r6-role-test-cluster.sh';
const CTRG_PB7_SCRIPT_SHA256 = 'd9ee606651e8c4188c2e85b3336a8872334e89b79eaf275b1e7e5e5e263c39d6';
const CTRG_PB7_BLOCKS = [
    'hosted' => ['tests/Fixtures/ci/pb-7-critical-gate-step-hosted.yml.txt', '0738929bbac15425d5d5cb516b0c22e79fac8bdd6540e18b8a40a35a34159a7a',
        "      - name: Run governance audit commands\n        # Always runs — governance is never gated by the classifier.\n        run: bash scripts/ci/foundation-evidence-gates.sh --critical-only\n"],
    'self-hosted' => ['tests/Fixtures/ci/pb-7-critical-gate-step-self-hosted.yml.txt', '5512d748c336ed35ac4ba5ad80c430ce4591e49a02e548366e382673d4f31079',
        "      - name: Run governance audit commands\n        # Always runs — governance is never gated by the classifier.\n        run: bash scripts/ci/self-hosted-php.sh bash scripts/ci/foundation-evidence-gates.sh --critical-only\n"],
];

// The exact lines the removal deletes: pre-removal lines 7–9 and 55–56.
const CTRG_PUSH_LINES = [
    '  push:',
    '    branches:',
    '      - '.CTRG_BASE_BRANCH,
];
const CTRG_SCHEDULE_LINES = [
    '  schedule:',
    "    - cron: '0 2 * * 0'",
];

function ctrgNormalise(string $text): string
{
    return (string) preg_replace('/\s+/', ' ', $text);
}

/** The CG-16 bullet of CLAUDE.md, up to the next critical global rule. */
function ctrgCg16(): string
{
    $claude = (string) file_get_contents(base_path('CLAUDE.md'));
    $start = strpos($claude, '- **CG-16 ');
    $end = strpos($claude, '- **CG-17 ');
    expect($start)->not->toBeFalse()->and($end)->not->toBeFalse();

    return substr($claude, (int) $start, (int) $end - (int) $start);
}

function ctrgRegister(): string
{
    return (string) file_get_contents(base_path('docs/governance/go-tag-exceptions.md'));
}

/** Both register bullets (removal and restoration), up to the next heading. */
function ctrgClasses(): string
{
    $register = ctrgRegister();
    $start = strpos($register, '- A **CI trigger removal**');
    expect($start)->not->toBeFalse('the GO-tag register must state the CI trigger removal class');
    $end = strpos($register, "\n## ", (int) $start);

    return substr($register, (int) $start, ($end === false ? strlen($register) : $end) - (int) $start);
}

function ctrgRemovalClause(): string
{
    $classes = ctrgClasses();
    $split = strpos($classes, '- A **CI trigger restoration**');
    expect($split)->not->toBeFalse('the GO-tag register must state the CI trigger restoration');

    return substr($classes, 0, (int) $split);
}

function ctrgRestorationClause(): string
{
    $classes = ctrgClasses();

    return substr($classes, (int) strpos($classes, '- A **CI trigger restoration**'));
}

function ctrgWorkflowRaw(): string
{
    return (string) file_get_contents(base_path(CTRG_WORKFLOW));
}

function ctrgWorkflow(): array
{
    return Yaml::parseFile(base_path(CTRG_WORKFLOW));
}

/** Pre-removal content minus exactly the push and schedule lines. */
function ctrgRemove(string $pre): string
{
    $lines = explode("\n", $pre);
    expect(array_slice($lines, 6, 3))->toBe(CTRG_PUSH_LINES)
        ->and(array_slice($lines, 54, 2))->toBe(CTRG_SCHEDULE_LINES);

    return implode("\n", array_merge(array_slice($lines, 0, 6), array_slice($lines, 9, 45), array_slice($lines, 56)));
}

/** Post-removal content with exactly the push and schedule lines put back. */
function ctrgRestore(string $post): string
{
    $lines = explode("\n", $post);

    return implode("\n", array_merge(
        array_slice($lines, 0, 6), CTRG_PUSH_LINES, array_slice($lines, 6, 45), CTRG_SCHEDULE_LINES, array_slice($lines, 51),
    ));
}

/** REM-1: $content with exactly the CG-19 tokens inserted before the last token of BOTH critical filters. */
function ctrgSelect(string $content): string
{
    expect(substr_count($content, CTRG_CG19_ANCHOR))->toBe(2);

    return str_replace(CTRG_CG19_ANCHOR, '|LabClinicCalendarBoundary|'.implode('|', CTRG_CG19_TOKENS)."|InventoryClinicCalendarBoundary'", $content);
}

/** The inverse of ctrgSelect(), used only to derive the approved states from the checked-in file. */
function ctrgDeselect(string $content): string
{
    $selected = '|LabClinicCalendarBoundary|'.implode('|', CTRG_CG19_TOKENS)."|InventoryClinicCalendarBoundary'";
    expect(substr_count($content, $selected))->toBe(2);

    return str_replace($selected, CTRG_CG19_ANCHOR, $content);
}

/** O-CI-1: the pinned block of two steps, verified against its pinned SHA-256. */
function ctrgOci1Block(): string
{
    $block = (string) file_get_contents(base_path(CTRG_OCI1_BLOCK));
    expect(hash('sha256', $block))->toBe(CTRG_OCI1_BLOCK_SHA256, 'the O-CI-1 block changed without a re-pin');

    return $block;
}

/** O-CI-1: $content with exactly the pinned block inserted after the Poppler step of the hosted critical gate. */
function ctrgOci1Apply(string $content): string
{
    expect(substr_count($content, CTRG_OCI1_HEAD.CTRG_OCI1_TAIL))->toBe(1);

    return str_replace(CTRG_OCI1_HEAD.CTRG_OCI1_TAIL, CTRG_OCI1_HEAD.ctrgOci1Block().CTRG_OCI1_TAIL, $content);
}

/** The inverse of ctrgOci1Apply(), used only to derive the approved states from the checked-in file. */
function ctrgOci1Strip(string $content): string
{
    $applied = CTRG_OCI1_HEAD.ctrgOci1Block().CTRG_OCI1_TAIL;
    expect(substr_count($content, $applied))->toBe(1);

    return str_replace($applied, CTRG_OCI1_HEAD.CTRG_OCI1_TAIL, $content);
}

/** PB-7: a pinned step block, verified against its pinned SHA-256. */
function ctrgPb7Block(string $variant): string
{
    [$path, $sha] = CTRG_PB7_BLOCKS[$variant];
    $block = (string) file_get_contents(base_path($path));
    expect(hash('sha256', $block))->toBe($sha, "the PB-7 {$variant} block changed without a re-pin");

    return $block;
}

/** PB-7: $content with exactly the pinned step inserted before "Run governance audit commands" in both critical gates. */
function ctrgPb7Apply(string $content): string
{
    foreach (CTRG_PB7_BLOCKS as $variant => [, , $anchor]) {
        expect(substr_count($content, $anchor))->toBe(1);
        $content = str_replace($anchor, ctrgPb7Block($variant).$anchor, $content);
    }

    return $content;
}

/** The inverse of ctrgPb7Apply(), used only to derive the approved states from the checked-in file. */
function ctrgPb7Strip(string $content): string
{
    foreach (CTRG_PB7_BLOCKS as $variant => [, , $anchor]) {
        $applied = ctrgPb7Block($variant).$anchor;
        expect(substr_count($content, $applied))->toBe(1);
        $content = str_replace($applied, $anchor, $content);
    }

    return $content;
}

/** The eight approved contents, derived from the checked-in file in any of its approved states. */
function ctrgStates(): array
{
    $current = ctrgWorkflowRaw();
    $sha = hash('sha256', $current);
    $approved = [CTRG_PRE_REMOVAL_SHA256, CTRG_POST_REMOVAL_SHA256, CTRG_CG19_SHA256, CTRG_CG19_PRE_REMOVAL_SHA256,
        CTRG_OCI1_SHA256, CTRG_OCI1_PRE_REMOVAL_SHA256, CTRG_PB7_SHA256, CTRG_PB7_PRE_REMOVAL_SHA256];
    // (`toContain` is variadic, so the reason lives in `toBeTrue`.)
    expect(in_array($sha, $approved, true))
        ->toBeTrue("the workflow ({$sha}) must be in one of the eight approved states; any other change needs its own owner-approved re-pin");

    $post = match ($sha) {
        CTRG_PRE_REMOVAL_SHA256 => ctrgRemove($current),
        CTRG_POST_REMOVAL_SHA256 => $current,
        CTRG_CG19_SHA256 => ctrgDeselect($current),
        CTRG_CG19_PRE_REMOVAL_SHA256 => ctrgDeselect(ctrgRemove($current)),
        CTRG_OCI1_SHA256 => ctrgDeselect(ctrgOci1Strip($current)),
        CTRG_OCI1_PRE_REMOVAL_SHA256 => ctrgDeselect(ctrgRemove(ctrgOci1Strip($current))),
        CTRG_PB7_SHA256 => ctrgDeselect(ctrgOci1Strip(ctrgPb7Strip($current))),
        CTRG_PB7_PRE_REMOVAL_SHA256 => ctrgDeselect(ctrgRemove(ctrgOci1Strip(ctrgPb7Strip($current)))),
    };

    return [
        'pre' => ctrgRestore($post),
        'post' => $post,
        'cg19' => ctrgSelect($post),
        'cg19pre' => ctrgSelect(ctrgRestore($post)),
        'oci1' => ctrgOci1Apply(ctrgSelect($post)),
        'oci1pre' => ctrgOci1Apply(ctrgSelect(ctrgRestore($post))),
        'pb7' => ctrgPb7Apply(ctrgOci1Apply(ctrgSelect($post))),
        'pb7pre' => ctrgPb7Apply(ctrgOci1Apply(ctrgSelect(ctrgRestore($post)))),
    ];
}

/**
 * Classifies a change to the workflow under the CG-16 CI trigger classes:
 * `removal`, `restoration`, `unchanged`, or `rejected` for everything else; plus
 * REM-1's `cg19-selection`, which is not a CG-16 class (it is released with its
 * sprint). Removing the selection weakens the gate and is `rejected`.
 */
function ctrgClassify(string $before, string $after): string
{
    if ($before === $after) {
        return 'unchanged';
    }
    $from = hash('sha256', $before);
    $to = hash('sha256', $after);

    if ($from === CTRG_PRE_REMOVAL_SHA256 && $to === CTRG_POST_REMOVAL_SHA256 && ctrgRemove($before) === $after) {
        return 'removal';
    }
    if ($from === CTRG_POST_REMOVAL_SHA256 && $to === CTRG_PRE_REMOVAL_SHA256 && ctrgRestore($before) === $after) {
        return 'restoration';
    }
    if ($from === CTRG_CG19_PRE_REMOVAL_SHA256 && $to === CTRG_CG19_SHA256 && ctrgRemove($before) === $after) {
        return 'removal';
    }
    if ($from === CTRG_CG19_SHA256 && $to === CTRG_CG19_PRE_REMOVAL_SHA256 && ctrgRestore($before) === $after) {
        return 'restoration';
    }
    if ((($from === CTRG_POST_REMOVAL_SHA256 && $to === CTRG_CG19_SHA256)
        || ($from === CTRG_PRE_REMOVAL_SHA256 && $to === CTRG_CG19_PRE_REMOVAL_SHA256))
        && ctrgSelect($before) === $after) {
        return 'cg19-selection';
    }
    if ($from === CTRG_OCI1_PRE_REMOVAL_SHA256 && $to === CTRG_OCI1_SHA256 && ctrgRemove($before) === $after) {
        return 'removal';
    }
    if ($from === CTRG_OCI1_SHA256 && $to === CTRG_OCI1_PRE_REMOVAL_SHA256 && ctrgRestore($before) === $after) {
        return 'restoration';
    }
    if ((($from === CTRG_CG19_SHA256 && $to === CTRG_OCI1_SHA256)
        || ($from === CTRG_CG19_PRE_REMOVAL_SHA256 && $to === CTRG_OCI1_PRE_REMOVAL_SHA256))
        && ctrgOci1Apply($before) === $after) {
        return 'oci1-alignment';
    }
    if ($from === CTRG_PB7_PRE_REMOVAL_SHA256 && $to === CTRG_PB7_SHA256 && ctrgRemove($before) === $after) {
        return 'removal';
    }
    if ($from === CTRG_PB7_SHA256 && $to === CTRG_PB7_PRE_REMOVAL_SHA256 && ctrgRestore($before) === $after) {
        return 'restoration';
    }
    if ((($from === CTRG_OCI1_SHA256 && $to === CTRG_PB7_SHA256)
        || ($from === CTRG_OCI1_PRE_REMOVAL_SHA256 && $to === CTRG_PB7_PRE_REMOVAL_SHA256))
        && ctrgPb7Apply($before) === $after) {
        return 'pb7-role-separation';
    }

    return 'rejected';
}

/**
 * Relative paths of source files under $dirs whose content matches $pattern.
 *
 * @param  list<string>  $dirs
 * @return list<string>
 */
function ctrgFilesMatching(array $dirs, string $pattern): array
{
    $hits = [];
    foreach ($dirs as $dir) {
        $root = base_path($dir);
        if (! is_dir($root)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $path = $file->getPathname();
            if (str_contains($path, '/node_modules/') || ! preg_match('/\.(php|sh|conf|js|ts|vue)$/', $path)) {
                continue;
            }
            if (preg_match($pattern, (string) file_get_contents($path)) === 1) {
                $hits[] = ltrim(substr($path, strlen(base_path())), '/');
            }
        }
    }
    sort($hits);

    return $hits;
}

/*
|--------------------------------------------------------------------------
| 1. The rule and the register state the same, narrow classes
|--------------------------------------------------------------------------
*/

it('states the CI trigger removal and its rollback in CLAUDE.md CG-16, the rule the register implements', function () {
    $cg16 = ctrgNormalise(ctrgCg16());

    foreach ([
        'Nothing else is an exception',
        'close without a deploy and get no GO tag of their own',
        'never reported as deployed',
        'docs, rules or tests-only',
        'CI trigger removal of `docs/governance/go-tag-exceptions.md`',
        'deleting the `push` and `schedule` triggers of `.github/workflows/foundation-evidence-gates.yml`',
        'a separately owner-approved PR, green CI before merge',
        'restores the file byte for byte to its pinned pre-removal SHA-256',
        'keep `pull_request` and `workflow_dispatch`',
        'weaken no job or gate',
        'deploy nothing',
        'never extend to another GitHub Actions change',
    ] as $phrase) {
        expect(str_contains($cg16, $phrase))->toBeTrue("CLAUDE.md CG-16 lost: {$phrase}");
    }
});

it('states the removal, with all six conditions, in the GO-tag register', function () {
    $raw = ctrgRemovalClause();
    $clause = ctrgNormalise($raw);

    foreach ([
        'closed without a deploy and receives no GO tag of its own',
        'CLAUDE.md CG-16',
        'authorises in writing',
        'deletes the whole `push` and `schedule` entries from the `on:` block of `.github/workflows/foundation-evidence-gates.yml`',
        'minus exactly those lines',
        'both SHA-256 values',
        '`pull_request` and `workflow_dispatch` stay, with their branches and inputs unchanged',
        'no job, step, `if`, `permissions`, `concurrency`, `env`, secret use, runner selection, classifier input, critical-gate filter, policy file or other workflow',
        '`app/`, `config/`, `scripts/`, `deploy/`, `database/`, `routes/`, `resources/`, `bootstrap/`, `public/` or `.sprint/`',
        'the release guard, the signer, the runner and every approval contract stay unchanged',
        'states that the change was not deployed',
        CTRG_PRE_REMOVAL_SHA256,
        CTRG_POST_REMOVAL_SHA256,
    ] as $phrase) {
        expect(str_contains($clause, $phrase))->toBeTrue("go-tag-exceptions.md CI trigger removal lost: {$phrase}");
    }

    preg_match_all('/^ {2}([1-9])\. /m', $raw, $conditions);
    expect($conditions[1])->toBe(['1', '2', '3', '4', '5', '6']);
});

it('states the rollback, a byte-for-byte restoration with six conditions, in the GO-tag register', function () {
    $raw = ctrgRestorationClause();
    $clause = ctrgNormalise($raw);

    foreach ([
        'CI trigger restoration',
        'the rollback of that removal',
        'without a deploy and with no GO tag of its own',
        'byte for byte to the approved pre-removal version',
        'the pinned post-removal SHA-256, after it the pinned pre-removal SHA-256',
        'A partial or edited restoration is not a restoration',
        'Conditions 2 to 5 of the removal hold',
        'no other trigger, job, step, `permissions`, `concurrency`, `env`, secret use, runner selection, classifier input, critical-gate filter or release gate changes',
        'its own pull request with its own written owner approval',
        'Its CI runs on the exact head and passes before the merge',
        'deploys nothing and changes nothing on the VPS',
        'states that the change was not deployed',
        'Neither change is ever reported as deployed or released',
        'keeps its own manifest `go_tag`',
        'The two classes cover no other GitHub Actions change',
        'adding or editing a trigger, job or workflow, a partial restoration, or a restoration to any other content is outside them',
    ] as $phrase) {
        expect(str_contains($clause, $phrase))->toBeTrue("go-tag-exceptions.md CI trigger restoration lost: {$phrase}");
    }

    preg_match_all('/^ {2}([1-9])\. /m', $raw, $conditions);
    expect($conditions[1])->toBe(['1', '2', '3', '4', '5', '6']);

    // The sprint record pins the same two states.
    $record = (string) file_get_contents(base_path('docs/sprints/ci-cd-pr-only-policy-1.md'));
    expect($record)->toContain(CTRG_PRE_REMOVAL_SHA256)->toContain(CTRG_POST_REMOVAL_SHA256);
});

it('keeps the classes narrow: one workflow file, two triggers, no general GitHub Actions exception', function () {
    foreach (['CLAUDE.md CG-16' => ctrgCg16(), 'go-tag-exceptions.md' => ctrgClasses()] as $where => $text) {
        preg_match_all('#\.github/workflows/[A-Za-z0-9._-]+#', $text, $paths);
        expect(array_values(array_unique($paths[0])))
            ->toBe([CTRG_WORKFLOW], "{$where} may name only the one workflow the classes cover");

        $prose = strtolower(ctrgNormalise($text));
        foreach (['any workflow', 'all workflows', 'any trigger', 'any github actions', 'every workflow'] as $widening) {
            expect(str_contains($prose, $widening))->toBeFalse("{$where} must not widen the classes: {$widening}");
        }
    }
});

/*
|--------------------------------------------------------------------------
| 2. The two approved workflow states and the change classifier
|--------------------------------------------------------------------------
*/

it('keeps the checked-in workflow in one of the pinned states, which differ by exactly the trigger lines or the CG-19 tokens', function () {
    $states = ctrgStates();

    expect(hash('sha256', $states['pre']))->toBe(CTRG_PRE_REMOVAL_SHA256)
        ->and(hash('sha256', $states['post']))->toBe(CTRG_POST_REMOVAL_SHA256)
        ->and(hash('sha256', $states['cg19']))->toBe(CTRG_CG19_SHA256)
        ->and(hash('sha256', $states['cg19pre']))->toBe(CTRG_CG19_PRE_REMOVAL_SHA256)
        ->and(ctrgRemove($states['pre']))->toBe($states['post'])
        ->and(ctrgRestore($states['post']))->toBe($states['pre'])
        ->and(ctrgRemove($states['cg19pre']))->toBe($states['cg19'])
        ->and(ctrgRestore($states['cg19']))->toBe($states['cg19pre'])
        ->and(hash('sha256', $states['oci1']))->toBe(CTRG_OCI1_SHA256)
        ->and(hash('sha256', $states['oci1pre']))->toBe(CTRG_OCI1_PRE_REMOVAL_SHA256)
        ->and(ctrgRemove($states['oci1pre']))->toBe($states['oci1'])
        ->and(ctrgRestore($states['oci1']))->toBe($states['oci1pre'])
        ->and(hash('sha256', $states['pb7']))->toBe(CTRG_PB7_SHA256)
        ->and(hash('sha256', $states['pb7pre']))->toBe(CTRG_PB7_PRE_REMOVAL_SHA256)
        ->and(ctrgRemove($states['pb7pre']))->toBe($states['pb7'])
        ->and(ctrgRestore($states['pb7']))->toBe($states['pb7pre']);

    // PB-7 adds exactly one pinned step to each critical gate, before the governance audit, and changes nothing else.
    $pb7 = Yaml::parse($states['pb7']);
    $oci1Parsed = Yaml::parse($states['oci1']);
    foreach (['critical_test_gate', 'critical_test_gate_self_hosted'] as $job) {
        $names = array_map(fn (array $step) => (string) ($step['name'] ?? ''), $pb7['jobs'][$job]['steps']);
        $at = array_search('Run the PostgreSQL 16 role-separation tests (PB-7)', $names, true);
        expect(array_values(array_filter($names, fn (string $n) => str_ends_with($n, '(PB-7)'))))->toBe(['Run the PostgreSQL 16 role-separation tests (PB-7)'])
            ->and($names[$at + 1])->toBe('Run governance audit commands')
            ->and(array_values(array_filter($pb7['jobs'][$job]['steps'], fn (array $step) => ! str_ends_with((string) ($step['name'] ?? ''), '(PB-7)'))))->toBe($oci1Parsed['jobs'][$job]['steps'])
            ->and(array_diff_key($pb7['jobs'][$job], ['steps' => 1]))->toBe(array_diff_key($oci1Parsed['jobs'][$job], ['steps' => 1]))
            ->and($pb7['jobs'][$job]['steps'][$at]['if'])->toBe("\${{ needs.classify.outputs.run_critical_tests != 'false' }}")
            ->and($pb7['jobs'][$job]['steps'][$at])->not->toHaveKey('continue-on-error');
    }
    expect(array_diff_key($pb7['jobs'], ['critical_test_gate' => 1, 'critical_test_gate_self_hosted' => 1]))->toBe(array_diff_key($oci1Parsed['jobs'], ['critical_test_gate' => 1, 'critical_test_gate_self_hosted' => 1]))
        ->and(array_diff_key($pb7, ['jobs' => 1]))->toBe(array_diff_key($oci1Parsed, ['jobs' => 1]));

    // O-CI-1 adds exactly the pinned block to the hosted critical gate, and changes no other job or key.
    $cg19 = Yaml::parse($states['cg19']);
    $oci1 = Yaml::parse($states['oci1']);
    $hosted = array_map(fn (array $step) => (string) ($step['name'] ?? ''), $oci1['jobs']['critical_test_gate']['steps']);
    $without = array_values(array_filter($oci1['jobs']['critical_test_gate']['steps'], fn (array $step) => ! str_ends_with((string) ($step['name'] ?? ''), '(O-CI-1)')));
    expect(array_values(array_filter($hosted, fn (string $n) => str_ends_with($n, '(O-CI-1)'))))->toBe([
        'Install and select the PostgreSQL 16 client (O-CI-1)',
        'Verify the PostgreSQL client and server majors match (O-CI-1)',
    ])
        ->and($without)->toBe($cg19['jobs']['critical_test_gate']['steps'])
        ->and(array_diff_key($oci1['jobs'], ['critical_test_gate' => 1]))->toBe(array_diff_key($cg19['jobs'], ['critical_test_gate' => 1]))
        ->and(array_diff_key($oci1['jobs']['critical_test_gate'], ['steps' => 1]))->toBe(array_diff_key($cg19['jobs']['critical_test_gate'], ['steps' => 1]))
        ->and(array_diff_key($oci1, ['jobs' => 1]))->toBe(array_diff_key($cg19, ['jobs' => 1]));

    // REM-1 changes exactly the two critical filter lines, and only by the three tokens.
    $post = explode("\n", $states['post']);
    $cg19 = explode("\n", $states['cg19']);
    $changed = array_keys(array_diff_assoc($cg19, $post));
    expect(count($cg19))->toBe(count($post))
        ->and($changed)->toHaveCount(2);
    foreach ($changed as $line) {
        expect(str_replace('|'.implode('|', CTRG_CG19_TOKENS), '', $cg19[$line]))->toBe($post[$line]);
    }

    $pre = Yaml::parse($states['pre']);
    $post = Yaml::parse($states['post']);
    expect(array_keys($pre['on']))->toBe(['pull_request', 'push', 'workflow_dispatch', 'schedule'])
        ->and(array_keys($post['on']))->toBe(['pull_request', 'workflow_dispatch'])
        ->and($post['jobs'])->toBe($pre['jobs'])
        ->and($post['concurrency'])->toBe($pre['concurrency'])
        ->and($post['on']['pull_request'])->toBe($pre['on']['pull_request'])
        ->and($post['on']['workflow_dispatch'])->toBe($pre['on']['workflow_dispatch']);
});

it('accepts only the exact removal and the exact restoration', function () {
    $states = ctrgStates();

    expect(ctrgClassify($states['pre'], $states['post']))->toBe('removal')
        ->and(ctrgClassify($states['post'], $states['pre']))->toBe('restoration')
        ->and(ctrgClassify($states['pre'], $states['pre']))->toBe('unchanged')
        // REM-1: the selection, and the B5 classes on the selected file.
        ->and(ctrgClassify($states['post'], $states['cg19']))->toBe('cg19-selection')
        ->and(ctrgClassify($states['pre'], $states['cg19pre']))->toBe('cg19-selection')
        ->and(ctrgClassify($states['cg19pre'], $states['cg19']))->toBe('removal')
        ->and(ctrgClassify($states['cg19'], $states['cg19pre']))->toBe('restoration')
        // Removing the selection again weakens the gate (CG-17).
        ->and(ctrgClassify($states['cg19'], $states['post']))->toBe('rejected')
        ->and(ctrgClassify($states['cg19pre'], $states['pre']))->toBe('rejected')
        // A restoration onto the selected file must keep the selection.
        ->and(ctrgClassify($states['cg19'], $states['pre']))->toBe('rejected')
        // O-CI-1: the alignment onto the REM-1 file and onto its rollback target, and the B5 classes after it.
        ->and(ctrgClassify($states['cg19'], $states['oci1']))->toBe('oci1-alignment')
        ->and(ctrgClassify($states['cg19pre'], $states['oci1pre']))->toBe('oci1-alignment')
        ->and(ctrgClassify($states['oci1pre'], $states['oci1']))->toBe('removal')
        ->and(ctrgClassify($states['oci1'], $states['oci1pre']))->toBe('restoration')
        // Removing the alignment again, or applying it without REM-1, is outside every class.
        ->and(ctrgClassify($states['oci1'], $states['cg19']))->toBe('rejected')
        ->and(ctrgClassify($states['post'], ctrgOci1Apply($states['post'])))->toBe('rejected')
        ->and(ctrgClassify($states['oci1'], $states['cg19pre']))->toBe('rejected')
        // PB-7: the role-separation step onto the O-CI-1 file and onto its rollback target, and the B5 classes after it.
        ->and(ctrgClassify($states['oci1'], $states['pb7']))->toBe('pb7-role-separation')
        ->and(ctrgClassify($states['oci1pre'], $states['pb7pre']))->toBe('pb7-role-separation')
        ->and(ctrgClassify($states['pb7pre'], $states['pb7']))->toBe('removal')
        ->and(ctrgClassify($states['pb7'], $states['pb7pre']))->toBe('restoration')
        // Removing PB-7 again weakens the gate; applying it without O-CI-1 is outside every class.
        ->and(ctrgClassify($states['pb7'], $states['oci1']))->toBe('rejected')
        ->and(ctrgClassify($states['cg19'], ctrgPb7Apply($states['cg19'])))->toBe('rejected');
});

it('rejects every workflow change other than the exact removal or restoration', function (Closure $mutate, string $from) {
    $states = ctrgStates();
    [$before, $after] = $mutate($states['pre'], $states['post']);

    // Each case must really differ from the approved pair it imitates, or it would prove nothing.
    $approved = $from === 'post' ? [$states['post'], $states['pre']] : [$states['pre'], $states['post']];
    expect([$before, $after])->not->toBe($approved, 'the negative case did not change anything')
        ->and(ctrgClassify($before, $after))->toBe('rejected');
})->with([
    'restoration plus an extra trigger' => [fn (string $pre, string $post) => [$post, str_replace("on:\n", "on:\n  release:\n    types: [published]\n", $pre)], 'post'],
    'restoration plus a job edit' => [fn (string $pre, string $post) => [$post, preg_replace('/runs-on: ubuntu-latest/', 'runs-on: ubuntu-22.04', $pre, 1)], 'post'],
    'restoration plus token permissions' => [fn (string $pre, string $post) => [$post, str_replace("\nconcurrency:", "\npermissions: write-all\nconcurrency:", $pre)], 'post'],
    'restoration plus a new secret' => [fn (string $pre, string $post) => [$post, str_replace('secrets.CI_DB_PASSWORD', 'secrets.CI_DEPLOY_KEY', $pre)], 'post'],
    'restoration with concurrency weakened' => [fn (string $pre, string $post) => [$post, str_replace('cancel-in-progress: true', 'cancel-in-progress: false', $pre)], 'post'],
    'restoration with a classifier input change' => [fn (string $pre, string $post) => [$post, str_replace('resolve-gates.sh', 'resolve-gates-lenient.sh', $pre)], 'post'],
    'restoration with a different cron' => [fn (string $pre, string $post) => [$post, str_replace("cron: '0 2 * * 0'", "cron: '0 3 * * 0'", $pre)], 'post'],
    'partial restoration: push only' => [fn (string $pre, string $post) => [$post, str_replace(implode("\n", CTRG_SCHEDULE_LINES)."\n", '', $pre)], 'post'],
    'partial restoration: schedule only' => [fn (string $pre, string $post) => [$post, str_replace(implode("\n", CTRG_PUSH_LINES)."\n", '', $pre)], 'post'],
    'restoration with a whitespace-only difference' => [fn (string $pre, string $post) => [$post, $pre."\n"], 'post'],
    'restoration onto an unapproved base' => [fn (string $pre, string $post) => [$post."# drift\n", $pre], 'post'],
    'removal of push only' => [fn (string $pre, string $post) => [$pre, str_replace(implode("\n", CTRG_PUSH_LINES)."\n", '', $pre)], 'pre'],
    'removal plus a job edit' => [fn (string $pre, string $post) => [$pre, preg_replace('/runs-on: ubuntu-latest/', 'runs-on: ubuntu-22.04', $post, 1)], 'pre'],
    'removal that also drops pull_request' => [fn (string $pre, string $post) => [$pre, str_replace("  pull_request:\n    branches:\n      - ".CTRG_BASE_BRANCH."\n", '', $post)], 'pre'],
    'an unrelated edit of the approved file' => [fn (string $pre, string $post) => [$pre, str_replace('name: Foundation Evidence Gates', 'name: Foundation Evidence Gates (edited)', $pre)], 'pre'],
    'REM-1 selection in one variant only' => [fn (string $pre, string $post) => [$post, preg_replace('/'.preg_quote(CTRG_CG19_ANCHOR, '/').'/', '|LabClinicCalendarBoundary|'.implode('|', CTRG_CG19_TOKENS)."|InventoryClinicCalendarBoundary'", $post, 1)], 'post'],
    'REM-1 selection with a fourth token' => [fn (string $pre, string $post) => [$post, str_replace('|LabIntegration|', '|LabIntegration|Extra|', ctrgSelect($post))], 'post'],
    'REM-1 selection with a different token' => [fn (string $pre, string $post) => [$post, str_replace('|PatientDataCompleteness|', '|PatientData|', ctrgSelect($post))], 'post'],
    'REM-1 selection plus a job edit' => [fn (string $pre, string $post) => [$post, preg_replace('/runs-on: ubuntu-latest/', 'runs-on: ubuntu-22.04', ctrgSelect($post), 1)], 'post'],
    'REM-1 selection removed again (gate weakened)' => [fn (string $pre, string $post) => [ctrgSelect($post), $post], 'post'],
    'O-CI-1 with the major check softened' => [fn (string $pre, string $post) => [ctrgSelect($post), str_replace('does not match the server major $smaj"; exit 1; }', 'does not match the server major $smaj"; }', ctrgOci1Apply(ctrgSelect($post)))], 'post'],
    'O-CI-1 with the client left off PATH' => [fn (string $pre, string $post) => [ctrgSelect($post), str_replace('          echo /usr/lib/postgresql/16/bin >> "$GITHUB_PATH"'."\n", '', ctrgOci1Apply(ctrgSelect($post)))], 'post'],
    'O-CI-1 install step only' => [fn (string $pre, string $post) => [ctrgSelect($post), preg_replace('/\n      - name: Verify the PostgreSQL client and server majors match \(O-CI-1\).*?(?=\n      # CICD-CTRL-3)/s', "\n", ctrgOci1Apply(ctrgSelect($post)))], 'post'],
    'O-CI-1 marked continue-on-error' => [fn (string $pre, string $post) => [ctrgSelect($post), str_replace("      - name: Install and select the PostgreSQL 16 client (O-CI-1)\n        shell: bash\n", "      - name: Install and select the PostgreSQL 16 client (O-CI-1)\n        continue-on-error: true\n        shell: bash\n", ctrgOci1Apply(ctrgSelect($post)))], 'post'],
    'O-CI-1 plus a job edit' => [fn (string $pre, string $post) => [ctrgSelect($post), preg_replace('/runs-on: ubuntu-latest/', 'runs-on: ubuntu-22.04', ctrgOci1Apply(ctrgSelect($post)), 1)], 'post'],
    'O-CI-1 removed again' => [fn (string $pre, string $post) => [ctrgOci1Apply(ctrgSelect($post)), ctrgSelect($post)], 'post'],
    'PB-7 in the hosted gate only' => [fn (string $pre, string $post) => [ctrgOci1Apply(ctrgSelect($post)), str_replace(CTRG_PB7_BLOCKS['hosted'][2], ctrgPb7Block('hosted').CTRG_PB7_BLOCKS['hosted'][2], ctrgOci1Apply(ctrgSelect($post)))], 'post'],
    'PB-7 marked continue-on-error' => [fn (string $pre, string $post) => [ctrgOci1Apply(ctrgSelect($post)), str_replace("      - name: Run the PostgreSQL 16 role-separation tests (PB-7)\n", "      - name: Run the PostgreSQL 16 role-separation tests (PB-7)\n        continue-on-error: true\n", ctrgPb7Apply(ctrgOci1Apply(ctrgSelect($post))))], 'post'],
    'PB-7 with the requirement switched off' => [fn (string $pre, string $post) => [ctrgOci1Apply(ctrgSelect($post)), str_replace('bash scripts/ci/r6-role-test-cluster.sh', 'R6_ROLE_TEST_REQUIRED=0 bash scripts/ci/r6-role-test-cluster.sh', ctrgPb7Apply(ctrgOci1Apply(ctrgSelect($post))))], 'post'],
    'PB-7 plus a job edit' => [fn (string $pre, string $post) => [ctrgOci1Apply(ctrgSelect($post)), preg_replace('/runs-on: ubuntu-latest/', 'runs-on: ubuntu-22.04', ctrgPb7Apply(ctrgOci1Apply(ctrgSelect($post))), 1)], 'post'],
    'PB-7 removed again' => [fn (string $pre, string $post) => [ctrgPb7Apply(ctrgOci1Apply(ctrgSelect($post))), ctrgOci1Apply(ctrgSelect($post))], 'post'],
]);

/*
|--------------------------------------------------------------------------
| 3. Workflow invariants both changes must keep
|--------------------------------------------------------------------------
*/

it('pins the workflow invariants a CI trigger removal or restoration must keep', function () {
    $workflow = ctrgWorkflow();
    $raw = ctrgWorkflowRaw();
    $on = $workflow['on'];

    // No trigger beyond the four known ones; only push and schedule may ever be removed.
    expect(array_diff(array_keys($on), ['pull_request', 'push', 'workflow_dispatch', 'schedule']))->toBe([])
        ->and($on)->toHaveKeys(['pull_request', 'workflow_dispatch'])
        ->and($on['pull_request'])->toBe(['branches' => [CTRG_BASE_BRANCH]])
        ->and(array_keys($on['workflow_dispatch']['inputs']))
        ->toBe(['run_full_suite', 'full_suite_policy_override', 'runner_mode', 'critical_test_workers']);

    expect(array_keys($workflow['jobs']))->toBe([
        'classify', 'quality_gate', 'critical_test_gate', 'critical_test_gate_self_hosted', 'selective_module_gate',
        'release_safety_gate', 'nsf10_release_evidence_gate', 'full_suite_gate', 'android_clinic_app_gate',
    ]);

    // No token permission is widened at workflow or job level.
    expect($workflow)->not->toHaveKey('permissions');
    foreach ($workflow['jobs'] as $name => $job) {
        expect($job)->not->toHaveKey('permissions', "job {$name} must not declare token permissions");
    }

    preg_match_all('/secrets\.([A-Za-z0-9_]+)/', $raw, $secrets);
    expect(array_values(array_unique($secrets[1])))->toBe(['CI_DB_PASSWORD'])
        ->and($raw)->not->toContain('pull_request_target')
        ->and($workflow['concurrency'])->toBe([
            'group' => 'foundation-evidence-${{ github.ref }}',
            'cancel-in-progress' => true,
        ]);

    // Both critical-gate variants select the same tests.
    preg_match_all("/--filter='([^']+)'/", $raw, $filters);
    $critical = array_values(array_filter($filters[1], fn (string $f): bool => substr_count($f, '|') > 50));
    expect($critical)->toHaveCount(2)
        ->and($critical[0])->toBe($critical[1]);
});

it('selects the CG-19 suites in both critical variants once REM-1 is applied, and declares them', function () {
    $raw = ctrgWorkflowRaw();
    $sha = hash('sha256', $raw);
    preg_match_all("/--filter='([^']+)'/", $raw, $filters);
    $critical = array_values(array_filter($filters[1], fn (string $f): bool => substr_count($f, '|') > 50));
    $selected = in_array($sha, [CTRG_CG19_SHA256, CTRG_CG19_PRE_REMOVAL_SHA256, CTRG_OCI1_SHA256, CTRG_OCI1_PRE_REMOVAL_SHA256, CTRG_PB7_SHA256, CTRG_PB7_PRE_REMOVAL_SHA256], true);

    foreach ($critical as $filter) {
        foreach (CTRG_CG19_TOKENS as $token) {
            expect(in_array($token, explode('|', $filter), true))->toBe($selected, "token {$token} in a critical filter");
        }
    }

    if ($selected) {
        expect(config('ci_runner.critical_gate_mandatory_suites'))->toContain(
            'tests/Feature/Branch/BranchContextTest.php',
            'tests/Feature/Branch/BranchContextFallbackTest.php',
            'tests/Feature/RME/FixClinicOpsWorkingBranchContextTest.php',
            'tests/Feature/RME/PatientDataCompletenessAuditTest.php',
            'tests/Feature/RME/LabIntegrationTest.php',
        );
    }

    // The SPN4 sprint record pins the two REM-1 states and the two O-CI-1 states; the register pins O-CI-1 too.
    expect((string) file_get_contents(base_path('docs/sprints/dms-spn4-ready-pilot-1.md')))
        ->toContain(CTRG_CG19_SHA256)->toContain(CTRG_CG19_PRE_REMOVAL_SHA256)
        ->toContain(CTRG_OCI1_SHA256)->toContain(CTRG_OCI1_PRE_REMOVAL_SHA256)->toContain(CTRG_OCI1_BLOCK_SHA256)
        ->and(ctrgRegister())->toContain(CTRG_OCI1_SHA256)->toContain(CTRG_OCI1_PRE_REMOVAL_SHA256);

    // PB-7: both states and both block SHA-256s are pinned in the sprint record and the register.
    expect((string) file_get_contents(base_path('docs/sprints/dms-spn4-ready-pilot-1.md')))
        ->toContain(CTRG_PB7_SHA256)->toContain(CTRG_PB7_PRE_REMOVAL_SHA256)
        ->toContain(CTRG_PB7_BLOCKS['hosted'][1])->toContain(CTRG_PB7_BLOCKS['self-hosted'][1])
        ->and(ctrgRegister())->toContain(CTRG_PB7_SHA256)->toContain(CTRG_PB7_PRE_REMOVAL_SHA256)
        ->and((string) file_get_contents(base_path('docs/sprints/dms-spn4-ready-pilot-1.md')))->toContain(CTRG_PB7_SCRIPT_SHA256);
});

it('pins the PB-7 script, which keeps every fail-closed part (independent review of PB-7, finding 1)', function () {
    $sha = hash('sha256', ctrgWorkflowRaw());
    if (! in_array($sha, [CTRG_PB7_SHA256, CTRG_PB7_PRE_REMOVAL_SHA256], true)) {
        expect(file_exists(base_path(CTRG_PB7_SCRIPT)))->toBeFalse('the PB-7 script exists while the workflow has no PB-7 step');

        return;
    }
    $script = (string) file_get_contents(base_path(CTRG_PB7_SCRIPT));
    expect(hash('sha256', $script))->toBe(CTRG_PB7_SCRIPT_SHA256, 'the PB-7 script changed without a re-pin');

    // What the pin protects, stated so a re-pin cannot drop it unnoticed.
    expect($script)->toContain('set -euo pipefail')
        ->toContain('export R6_ROLE_TEST_REQUIRED=1')
        ->toContain('TEST_STATUS="${PIPESTATUS[0]}"')
        ->toContain("grep -Eqi 'skipped|failed|incomplete|risky|todo|pending'")
        ->toContain('--log-junit')
        ->toContain('PB7_MIN_TESTS=')
        ->toContain("-- '->(only|todo)\\('")
        ->toContain("listen_addresses=''")
        ->toContain('show listen_addresses')
        ->toContain('echo "::add-mask::${RT_PW}" >&3')
        ->toContain('run_as foundation:db-privilege-check')
        ->toContain("grep -q 'APPLIED: 0 statement(s) for 0 object(s)'")
        ->not->toContain('continue-on-error');
    foreach (preg_split('/\R/', $script) ?: [] as $line) {
        if (preg_match('/artisan (test|foundation:db-privilege)/', $line) === 1) {
            expect($line)->not->toContain('|| true', $line);
        }
    }
    preg_match('/PB7_MIN_TESTS=(\d+)/', $script, $min);
    expect((int) $min[1])->toBeGreaterThanOrEqual(230);
});

/*
|--------------------------------------------------------------------------
| 4. B5-RESIDUAL-1 — open, pinned, and off every runtime path
|--------------------------------------------------------------------------
*/

it('pins B5-RESIDUAL-1 as open, so it can neither be fixed nor grow silently', function () {
    // Closing this residual belongs to the next CI governance sprint that is released under its own
    // manifest and go_tag (owner decision 18). That sprint replaces these expectations with their inverse.
    expect(config('ci_runtime_control.required_workflow_markers'))->toContain('schedule')
        ->and(config('ci_runtime_control.full_suite_fallback_triggers'))->toBe(['schedule', 'workflow_dispatch'])
        ->and(config('ci_runtime_control.temporary_full_suite_policy.deferred_automatic_events'))->toBe(['schedule', 'push']);

    $service = (string) file_get_contents(base_path('app/Services/Foundation/CiRuntimeControlGovernanceService.php'));
    expect($service)
        ->toContain('The full Pest suite remains available via schedule (weekly), manual workflow_dispatch, and push-to-base.')
        ->toContain('Both automatic paths are deferred: the weekly schedule and the post-merge push to the base branch');
});

it('proves B5-RESIDUAL-1 reaches no runtime, deploy, release-guard or security path', function () {
    $runtimeDirs = ['app', 'routes', 'scripts', 'deploy', 'bootstrap', 'resources', 'database', 'public'];

    // Only governance and tooling code reads the CI runtime control config or classes.
    expect(ctrgFilesMatching($runtimeDirs, '/ci_runtime_control|CiRuntimeControl(Scanner|GovernanceService)/'))->toBe([
        'app/Console/Commands/FoundationCiRuntimeControlCheckCommand.php',
        'app/Console/Commands/FoundationSelfHostedRunnerCheckCommand.php',
        'app/Services/Foundation/CiRuntimeControlGovernanceService.php',
        'app/Services/Foundation/FiveBranchRolloutReadinessService.php',
        'app/Services/Foundation/SelfHostedRunnerGovernanceService.php',
        'app/Support/Cicd/CiRuntimeControlScanner.php',
        'app/Support/Devflow/DevflowScanner.php',
    ]);

    // The residual keys themselves are read by the scanner alone.
    expect(ctrgFilesMatching($runtimeDirs, '/ci_runtime_control\.(required_workflow_markers|full_suite_fallback_triggers|temporary_full_suite_policy\.deferred_automatic_events)/'))
        ->toBe(['app/Support/Cicd/CiRuntimeControlScanner.php']);

    // DEVFLOW reads two unrelated keys only.
    preg_match_all("/ci_runtime_control\.([a-z_.]+)/", (string) file_get_contents(base_path('app/Support/Devflow/DevflowScanner.php')), $keys);
    expect(array_values(array_unique($keys[1])))->toBe(['skip_critical_profiles', 'default_profile']);

    // The deploy never runs the CI runtime control or self-hosted runner checks.
    foreach (['scripts/deploy-vps.sh', 'scripts/deploy-vps-runner.sh'] as $script) {
        $body = (string) file_get_contents(base_path($script));
        expect($body)->not->toContain('ci-runtime-control-check')
            ->and($body)->not->toContain('self-hosted-runner-check');
    }

    // The web monitor never executes the check; it reads the cached decision only.
    expect(config('foundation_monitoring.signals.audit_ci_runtime_control.auto_run'))->toBeFalse();
});
