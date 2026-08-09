<?php

use App\Services\Architecture\NsfApplicationRulesService;

uses()->group('Architecture', 'FoundationGovernance', 'Cicd');

/*
 * CICD-FIX-1 — NSF-R020 must not depend on ambient developer state.
 *
 * Regression origin: FoundationGovernanceSummaryCommandTest and its siblings
 * assert combined_decision === 'GO', but built no fixture — they read whatever
 * ambient state the machine happened to have. NSF-R020 checks that the evidence
 * roots in config('nsf.evidence_roots') exist on disk. Those directories are
 * created as a side effect of writing evidence, and `storage/app/.gitignore`
 * ignored them, so:
 *
 *   - a developer machine that had ever written evidence HAD them  -> GO  -> pass
 *   - a fresh CI checkout never had them                           -> WATCH -> fail
 *
 * The rule was right; the repository simply never guaranteed the standard it
 * describes. The roots are now tracked (each carries its own .gitignore, the
 * same pattern Laravel uses for storage/app/public), so every checkout has them.
 *
 * NSF-R020 is NOT weakened: the tests below prove it still reports a warning
 * when a root is genuinely absent.
 */

/**
 * @return array<string, mixed>|null
 */
function nsfRule(string $ruleId): ?array
{
    $report = app(NsfApplicationRulesService::class)->collect();

    foreach ($report['rules'] as $rule) {
        if (($rule['rule_id'] ?? null) === $ruleId) {
            return $rule;
        }
    }

    return null;
}

it('tracks every configured evidence root so any checkout has it', function () {
    $roots = config('nsf.evidence_roots', []);

    expect($roots)->not->toBeEmpty();

    foreach ($roots as $root) {
        $path = base_path($root);

        expect(is_dir($path))->toBeTrue(
            "{$root} must exist in a clean checkout; NSF-R020 requires it"
        );

        // Tracked via its own .gitignore — the directory itself is guaranteed,
        // its contents stay ignored.
        expect(is_file($path.'/.gitignore'))->toBeTrue(
            "{$root} must be kept by a tracked .gitignore, not by local side effects"
        );
    }
});

it('passes NSF-R020 when the evidence roots are present', function () {
    $rule = nsfRule('NSF-R020');

    expect($rule)->not->toBeNull()
        ->and($rule['status'])->toBe('passed', (string) ($rule['message'] ?? ''));
});

it('still warns on NSF-R020 when an evidence root is genuinely missing', function () {
    // The rule must keep its teeth. Point it at a path that cannot exist.
    config(['nsf.evidence_roots' => ['storage/app/definitely-not-created-'.bin2hex(random_bytes(4))]]);

    $rule = nsfRule('NSF-R020');

    expect($rule)->not->toBeNull()
        ->and($rule['status'])->toBe('warning')
        // `title` comes from config/nsf.php; the runtime finding is in `message`.
        ->and($rule['message'])->toContain('missing');
});

it('drives the NSF decision to WATCH when a required evidence root is absent', function () {
    // Proves the blocking behaviour end-to-end: a real warning state still
    // produces WATCH rather than being suppressed.
    config(['nsf.evidence_roots' => ['storage/app/definitely-not-created-'.bin2hex(random_bytes(4))]]);

    $report = app(NsfApplicationRulesService::class)->collect();

    expect($report['summary']['decision'])->toBe('WATCH');
});

it('reaches a GO NSF decision from the repository state alone', function () {
    // The determinism guarantee: no ambient artifact, no prior test run and no
    // developer-local directory is needed for the governance decision to be GO.
    $report = app(NsfApplicationRulesService::class)->collect();

    $offenders = collect($report['rules'])
        ->filter(fn (array $r): bool => ($r['status'] ?? '') !== 'passed')
        ->map(fn (array $r): string => ($r['rule_id'] ?? '?').': '.($r['title'] ?? ''))
        ->values()
        ->all();

    expect($report['summary']['decision'])->toBe('GO', implode("\n", $offenders));
});
