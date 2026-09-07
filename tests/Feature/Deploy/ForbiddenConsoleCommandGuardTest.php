<?php

/**
 * PHASE4A-DOCTOR-ANDROID-PILOT-ACTIVATION-1 — refusing the REPL at the moment
 * it is typed, not only where it is written down.
 *
 * WHY THIS EXISTS WHEN A GUARD ALREADY DID
 *
 * `ProductionShellCommandGuard` scans eleven tracked executable scripts before
 * they ship. It works, it is CI-enforced, and it would have caught the REPL in
 * any deploy script. It cannot catch what is not in a file.
 *
 * Both production invocations during this sprint were ad-hoc — typed into an
 * interactive SSH command, in no script, scanned by nothing. Extending
 * `scanned_files` would not have caught either one. `config/release_safety.php`
 * had already recorded the same lesson about an earlier pair of invocations:
 * "the prohibition was written down in four places and the command was still
 * executed against production twice, because prose is not a control." It was
 * right, and the control it produced still had no reach over a keyboard.
 *
 * So this guard runs at `CommandStarting`, which is the one place every
 * invocation must pass through no matter who typed it or how.
 *
 * FAIL CLOSED, AND IN THE DIRECTION THAT COSTS LEAST
 *
 * The environment allowlist is `local` and `testing`. Every other environment —
 * production, pilot, staging, a typo, an unset value — is refused. The cost of a
 * false refusal is a developer typing an environment name; the cost of a false
 * permit is an unaudited write path against clinical data and a monitoring
 * signal pinned to WATCH for 24 hours.
 *
 * IT MUST NOT LOG AN ERROR
 *
 * The harm being prevented is partly that PsySH writes ERROR records which pin
 * the log signal. A guard that logged an ERROR every time it fired would cause
 * the very thing it exists to stop, so the refusal is reported to the console
 * and deliberately not reported to the log.
 */

use App\Exceptions\ForbiddenProductionCommandException;
use App\Support\Deploy\ForbiddenConsoleCommandGuard;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\Process;

function guardFor(array $config = []): ForbiddenConsoleCommandGuard
{
    config()->set('release_safety.forbidden_production_commands', array_replace(
        (array) config('release_safety.forbidden_production_commands'),
        $config,
    ));

    return app(ForbiddenConsoleCommandGuard::class);
}

// ---------------------------------------------------------------------------
// The gap that was actually exploited
// ---------------------------------------------------------------------------

it('refuses the REPL on a production-like environment', function () {
    $guard = guardFor();

    foreach (['production', 'pilot', 'staging', 'live'] as $environment) {
        expect($guard->shouldBlock('tinker', $environment))
            ->toBeTrue("`{$environment}` must refuse the REPL");
    }
});

it('allows the REPL where it is legitimate, so the guard is not deleted for being useless', function () {
    $guard = guardFor();

    expect($guard->shouldBlock('tinker', 'local'))->toBeFalse();
    expect($guard->shouldBlock('tinker', 'testing'))->toBeFalse();
});

it('fails closed on an environment nobody declared', function () {
    $guard = guardFor();

    // A typo, an unset value, something new. None of them is an argument for
    // opening a REPL on it.
    foreach (['', 'prod', 'pilot-2', 'unknown', 'PRODUCTION'] as $environment) {
        expect($guard->shouldBlock('tinker', $environment))
            ->toBeTrue("unrecognised environment `{$environment}` must fail closed");
    }
});

it('leaves every ordinary command alone', function () {
    $guard = guardFor();

    foreach (['migrate', 'route:list', 'android:phase4a-pilot-scope', 'test', 'about', null] as $command) {
        expect($guard->shouldBlock($command, 'production'))
            ->toBeFalse('an ordinary command must not be refused: '.var_export($command, true));
    }
});

it('does not refuse a longer command that merely starts with the same letters', function () {
    $guard = guardFor();

    // A guard that reddens on obedience gets switched off.
    expect($guard->shouldBlock('tinkerbell:run', 'production'))->toBeFalse();
    expect($guard->shouldBlock('app:tinker-report', 'production'))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Wired to the one place every invocation passes through
// ---------------------------------------------------------------------------

it('aborts the command through the console lifecycle, whoever typed it', function () {
    config()->set('release_safety.forbidden_production_commands.repl_allowed_environments', ['never-matches']);

    // The event is dispatched directly rather than by calling the command.
    // `CommandStarting` is the exact hook the listener binds to, so this proves
    // the wiring — and if the guard ever regressed, actually invoking the REPL
    // would open a stdin-blocked shell and hang CI instead of failing. A test
    // whose failure mode is a hung job is worse than no test.
    expect(fn () => event(new CommandStarting(
        'tinker',
        new ArrayInput([]),
        new BufferedOutput,
    )))->toThrow(ForbiddenProductionCommandException::class);
});

it('lets an ordinary command through the same hook untouched', function () {
    config()->set('release_safety.forbidden_production_commands.repl_allowed_environments', ['never-matches']);

    // The guard must be invisible to everything it does not forbid, on the very
    // environment where it is strictest.
    event(new CommandStarting('migrate', new ArrayInput([]), new BufferedOutput));

    expect(true)->toBeTrue();
});

it('says why, and names the command, without printing anything sensitive', function () {
    $guard = guardFor();
    $reason = $guard->reason('tinker');

    expect($reason)->toContain('tinker');
    expect(strtolower($reason))->toContain('production');

    // The operator needs to know what to do instead.
    expect(strtolower($reason))->toContain('read-only');
});

it('is not reported to the application log, because that is the harm it prevents', function () {
    // PsySH writing ERROR records is half of why the REPL is forbidden: it pins
    // the monitoring log signal to WATCH for 24 hours. A guard that logged an
    // ERROR on every refusal would cause exactly that.
    $handler = app(ExceptionHandler::class);

    expect($handler->shouldReport(new ForbiddenProductionCommandException('x')))->toBeFalse();
});

// ---------------------------------------------------------------------------
// The honest limit, asserted so nobody later mistakes reach for coverage
// ---------------------------------------------------------------------------

it('actually refuses a real invocation on a production-like environment', function () {
    // The event-level tests above prove the wiring. They do NOT prove the
    // framework routes a real invocation through that hook, and the difference
    // is not academic: the first version of this guard passed every event-level
    // test while failing to block anything, and only running the real binary
    // showed it. So this runs the real one.
    //
    // `--execute` rather than a bare `tinker`: it exercises the same command
    // without opening a stdin-blocked REPL that would hang the suite.
    $process = new Process(
        ['php', 'artisan', 'tinker', '--execute=echo 1;'],
        base_path(),
        ['APP_ENV' => 'pilot'] + $_SERVER,
        null,
        60,
    );

    $process->run();

    expect($process->getExitCode())->not->toBe(0, 'a REPL must not run on a production-like environment');
});

it('records the one form it cannot reach, so nobody mistakes it for coverage', function () {
    // `artisan tinker --version` is answered by Symfony's Application before any
    // command is resolved, so `CommandStarting` never fires and this guard never
    // sees it. That form starts no REPL, touches no application state and writes
    // no log record — which is exactly why the two invocations that prompted
    // this guard left no trace — but it is a real limit and it is written down
    // here rather than discovered again.
    $process = new Process(
        ['php', 'artisan', 'tinker', '--version'],
        base_path(),
        ['APP_ENV' => 'pilot'] + $_SERVER,
        null,
        60,
    );

    $process->run();

    // Asserted as the documented limit, not as desired behaviour.
    expect($process->getExitCode())->toBe(0);
    expect($process->getOutput())->toContain('Laravel Framework');
});

it('declares the blocked commands in config, never in the guard source', function () {
    // Same reasoning the sibling scanner uses: a scanner containing the literal
    // it forbids reddens on itself, and gets switched off.
    $source = file_get_contents(app_path('Support/Deploy/ForbiddenConsoleCommandGuard.php'));

    expect($source)->not->toContain('tinker');
    expect($source)->not->toContain('psysh');

    expect(config('release_safety.forbidden_production_commands.blocked_console_commands'))
        ->toBeArray()->not->toBeEmpty();
});
