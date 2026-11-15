<?php

/**
 * DMS developer toolchain readiness (DMS-DEPLOY-PRIVSEP-HARDENING-1 closure).
 *
 * scripts/dev/toolchain-readiness-check.sh tells a DEVFLOW session which tools it
 * can use on the workstation and what the owner must install. It must stay
 * read-only — never installing, never changing configuration, never printing a
 * secret — and must fail when a required tool is missing.
 */
function tcScript(): string
{
    return base_path('scripts/dev/toolchain-readiness-check.sh');
}

/** @return array{0:int,1:string} */
function tcRun(array $args, array $env): array
{
    $proc = proc_open(array_merge(['bash', tcScript()], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    $out = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($proc), (string) $out];
}

it('never installs, configures or contacts anything', function () {
    $code = (string) preg_replace('/^\s*#.*$/m', '', (string) file_get_contents(tcScript()));
    // Owner actions appear only as quoted advice passed to check() or row(); nothing
    // runs them. Drop every quoted string, then look at what the shell executes.
    $executable = (string) preg_replace(['/\'[^\'\\n]*\'/', '/"[^"\\n]*"/'], ["''", '""'], $code);

    expect($executable)->not->toMatch('/(^|[;&|(]\s*|\$\(\s*)(apt-get|apt|pip|pip3|pipx|npm|curl|wget|sudo)\b/m')
        ->and($executable)->not->toMatch('/(^|[;&|]\s*)(rm|mv|cp|chmod|chown|tee|install|mkdir|touch|ln)\s/m')
        ->and($executable)->not->toMatch('/\d?>{1,2}\s*(?!\/dev\/null|&)\S/')
        ->and($code)->not->toContain('gh auth token')
        ->and($code)->toContain('gh auth status >/dev/null 2>&1');
});

it('reports READY when every required tool, the project tools and the skills are present', function () {
    [$exit, $out] = tcRun(['--app-dir', base_path()], ['PATH' => (string) getenv('PATH'), 'HOME' => (string) getenv('HOME')]);

    // The required CLIs may be absent on a CI image; the verdict must match what is there.
    if (str_contains($out, ' MISSING ')) {
        expect($exit)->toBe(1)->and($out)->toContain('TOOLCHAIN_READINESS=MISSING');
    } else {
        expect($exit)->toBe(0)->and($out)->toContain('TOOLCHAIN_READINESS=READY');
    }
    expect($out)->toContain('skill:limit-saver')
        ->and($out)->toContain('skill:superpowers-workflow');
});

it('fails when a required tool or the project tooling is missing, naming the owner action', function () {
    $app = tempArtifactDir('toolchain-app-', 0755);
    $bin = tempArtifactDir('toolchain-bin-', 0755);
    // Only bash basics on PATH: every required CLI is missing.
    foreach (['bash', 'head', 'tr', 'cut', 'grep', 'dirname'] as $tool) {
        $path = trim((string) shell_exec('command -v '.escapeshellarg($tool)));
        if ($path !== '') {
            symlink($path, $bin.'/'.$tool);
        }
    }

    [$exit, $out] = tcRun(['--app-dir', $app], ['PATH' => $bin]);

    expect($exit)->toBe(1)
        ->and($out)->toContain('TOOLCHAIN_READINESS=MISSING')
        ->and($out)->toMatch('/^git\s+MISSING\s+owner: /m')
        ->and($out)->toMatch('/^pest\s+MISSING\s+copy vendor\/ or run composer install/m')
        ->and($out)->toMatch('/^graphify\s+OPTIONAL-MISSING\s+owner: sudo apt-get install pipx && pipx install graphifyy==0\.9\.99/m');
});

it('pins Graphify to the official package and documents the reinstall procedure', function () {
    $runbook = (string) file_get_contents(base_path('docs/runbooks/dev-toolchain-readiness.md'));

    expect(file_get_contents(tcScript()))->toContain('pipx install graphifyy==0.9.99')
        ->and($runbook)->toContain('github.com/Graphify-Labs/graphify')
        ->and($runbook)->toContain('pipx install graphifyy==0.9.99')
        ->and($runbook)->toContain('graphify claude install')
        ->and($runbook)->toContain('scripts/dev/toolchain-readiness-check.sh');
});
