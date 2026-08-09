<?php

use App\Services\Foundation\ReleaseEvidenceService;
use Illuminate\Support\Facades\File;

uses()->group('Foundation', 'Nsf10', 'ReleaseEvidence');

/*
 * CICD-FIX-6 Phase A — the POST-ENT evidence producers must actually exist.
 *
 * config/release_evidence.php has declared three POST-ENT runtime-hardening
 * artifacts as OPTIONAL for the ci and vps profiles since the POST-ENT sprint,
 * and both shell evidence flows already produced them (scripts/deploy-vps.sh
 * for vps, scripts/ci/foundation-evidence-gates.sh for ci). The capture job map
 * inside ReleaseEvidenceService was the single place they were never wired, so
 * `release:evidence-capture` — the canonical producer the NSF-10 gate runs —
 * could not emit them at all.
 *
 * The consequence was structural, not cosmetic. A missing OPTIONAL artifact is
 * a warning, and one warning downgrades the decision to WATCH, so a completely
 * healthy capture (exit 0, decision GO, 26 artifacts written) still produced
 * `release:evidence-check --profile=ci` = WATCH with exactly three "Optional
 * artifact missing" warnings. The ci evidence chain could therefore never reach
 * GO however healthy the release was — which is what five governance
 * integration suites and both ReleaseSafetyEvidenceClosureTest GO cases were
 * failing on in the Full Suite.
 *
 * It also broke POST-ENT rule PEH-R009: "optional evidence is non-blocking but
 * never silently skipped". Silently skipped is precisely what it was.
 *
 * These tests pin the repaired contract in both directions: the producers are
 * mapped and really emit evidence, AND a failing producer still cannot
 * fabricate an artifact or manufacture a GO.
 */

/** The three POST-ENT hardening artifacts and their authoritative producers. */
function postEntEvidenceProducers(): array
{
    return [
        'ent-1-4-audit-check.json' => 'foundation:ent-1-4-audit-check',
        'queue-worker-runtime-check.json' => 'foundation:queue-worker-runtime-check',
        'runtime-hardening-check.json' => 'foundation:runtime-hardening-check',
    ];
}

/** Reach the private capture job map for a profile. */
function releaseEvidenceJobs(string $profile): array
{
    $method = new ReflectionMethod(ReleaseEvidenceService::class, 'buildJobs');
    $method->setAccessible(true);

    return $method->invoke(app(ReleaseEvidenceService::class), $profile, null, null);
}

/** Run one capture job through the real private runJob(). */
function releaseEvidenceRunJob(string $filename, array $job, string $directory): array
{
    $method = new ReflectionMethod(ReleaseEvidenceService::class, 'runJob');
    $method->setAccessible(true);

    return $method->invoke(app(ReleaseEvidenceService::class), $filename, $job, $directory);
}

// ---------------------------------------------------------------------------
// The producer map itself
// ---------------------------------------------------------------------------

it('maps every POST-ENT hardening artifact to its authoritative producer command', function () {
    foreach (['ci', 'vps'] as $profile) {
        $jobs = releaseEvidenceJobs($profile);

        foreach (postEntEvidenceProducers() as $artifact => $command) {
            expect(array_key_exists($artifact, $jobs))
                ->toBeTrue("profile '{$profile}' has no capture job for {$artifact}");

            expect($jobs[$artifact]['command'])->toBe($command)
                ->and($jobs[$artifact]['arguments'])->toBe(['--json' => true]);
        }
    }
});

it('takes those producer commands from the real evidence flows rather than the artifact names', function () {
    // Traceability: the command wired into the capture map must be the same one
    // the deploy path and the CI gates shell already invoke. An artifact name is
    // not evidence of a command name, so this pins the actual source.
    $deploy = file_get_contents(base_path('scripts/deploy-vps.sh'));
    $gates = file_get_contents(base_path('scripts/ci/foundation-evidence-gates.sh'));

    foreach (postEntEvidenceProducers() as $artifact => $command) {
        expect(str_contains($deploy, $command))
            ->toBeTrue("deploy flow does not invoke {$command}");
        expect(str_contains($gates, $command))
            ->toBeTrue("CI gates shell does not invoke {$command}");
    }
});

it('keeps the POST-ENT hardening artifacts optional and never promotes them to required', function () {
    // Wiring a producer must not change the blocking semantics. A missing
    // optional artifact stays a warning; it must never become a hard error.
    foreach (['ci', 'vps'] as $profile) {
        $required = (array) config("release_evidence.profiles.{$profile}.required_artifacts");
        $optional = (array) config("release_evidence.profiles.{$profile}.optional_artifacts");

        foreach (array_keys(postEntEvidenceProducers()) as $artifact) {
            expect(in_array($artifact, $optional, true))
                ->toBeTrue("{$artifact} is no longer optional in the {$profile} profile");
            expect(in_array($artifact, $required, true))
                ->toBeFalse("{$artifact} was promoted to required in the {$profile} profile");
        }
    }
});

// ---------------------------------------------------------------------------
// Negative proof — a failing producer must not fabricate evidence
// ---------------------------------------------------------------------------

it('never writes an artifact when its producer fails', function () {
    $dir = base_path('storage/framework/testing/postent-producer-'.bin2hex(random_bytes(6)));
    File::makeDirectory($dir, 0755, true);

    try {
        // A producer that cannot run at all: Artisan throws, runJob catches.
        // The point is what happens next — nothing may be written to disk, and
        // the outcome must be reported as an error rather than as success.
        $outcome = releaseEvidenceRunJob('runtime-hardening-check.json', [
            'command' => 'foundation:this-producer-does-not-exist',
            'arguments' => ['--json' => true],
        ], $dir);

        expect($outcome['status'])->toBe('error')
            ->and($outcome['status'])->not->toBe('written')
            ->and(is_file($dir.'/runtime-hardening-check.json'))
            ->toBeFalse('a failing producer fabricated an evidence artifact');

        // And an absent artifact can never be read as healthy.
        expect(File::files($dir))->toBeEmpty();
    } finally {
        File::deleteDirectory($dir);
    }
});

it('cannot reach GO on the ci profile when a mapped optional artifact is absent', function () {
    // Non-blocking is preserved: absent optional evidence is a WATCH, not a
    // FAIL — but it is emphatically not a GO either.
    $dir = 'storage/framework/testing/postent-absent-'.bin2hex(random_bytes(6));
    config(['release_evidence.profiles.ci.directory' => $dir]);
    File::deleteDirectory(base_path($dir));

    try {
        $report = app(ReleaseEvidenceService::class)->check('ci');

        expect($report['summary']['decision'])->not->toBe('GO');

        $missing = collect($report['checks'])
            ->where('status', '!=', 'passed')
            ->pluck('message')
            ->implode("\n");

        foreach (array_keys(postEntEvidenceProducers()) as $artifact) {
            expect($missing)->toContain($artifact);
        }
    } finally {
        File::deleteDirectory(base_path($dir));
    }
});

// ---------------------------------------------------------------------------
// Positive proof — the producers really emit valid, safe evidence
// ---------------------------------------------------------------------------

it('emits real, safe, schema-valid JSON evidence for each POST-ENT producer', function () {
    $dir = base_path('storage/framework/testing/postent-emit-'.bin2hex(random_bytes(6)));
    File::makeDirectory($dir, 0755, true);

    try {
        $jobs = releaseEvidenceJobs('ci');

        foreach (array_keys(postEntEvidenceProducers()) as $artifact) {
            $outcome = releaseEvidenceRunJob($artifact, $jobs[$artifact], $dir);

            // "written" is the only status that means evidence exists. An
            // "unsafe" status would mean the producer emitted something the
            // safety scan rejected, which must never silently pass either.
            expect($outcome['status'])->toBe('written', "{$artifact} was not produced");

            $path = $dir.'/'.$artifact;
            expect(is_file($path))->toBeTrue();

            $contents = (string) file_get_contents($path);
            expect(trim($contents))->not->toBe('');

            $decoded = json_decode($contents, true);
            expect(json_last_error())->toBe(JSON_ERROR_NONE, "{$artifact} is not valid JSON")
                ->and($decoded)->toBeArray()
                ->and($decoded)->toHaveKey('decision');

            // Privacy/safety: evidence must never carry a credential or a
            // KTP/NIK-shaped run. This mirrors the capture-time scan.
            foreach ((array) config('release_evidence.forbidden_patterns') as $needle) {
                expect(str_contains($contents, (string) $needle))
                    ->toBeFalse("{$artifact} contains forbidden pattern {$needle}");
            }
            expect(preg_match('/\d{16}/', $contents))->toBe(0);
        }
    } finally {
        File::deleteDirectory($dir);
    }
});
