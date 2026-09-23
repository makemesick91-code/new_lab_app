<?php

/**
 * DOCTOR-PWA-GLOBAL-ROLLOUT-READINESS-1 — the runtime half of the forbidden
 * command control. Salvaged from the closed PHASE4A pilot-activation pull
 * request, which is where it was written.
 *
 * WHY THIS EXISTS ALONGSIDE ProductionShellCommandGuard
 *
 * That guard scans the tracked executable scripts and it works. It cannot see
 * an invocation that was never written to a file, and the invocations that
 * actually caused harm were typed into an interactive session. A control over
 * files has no reach over a keyboard, so this one sits at `CommandStarting`,
 * which every invocation passes through however it started.
 *
 * THE SPECIFIC TRAP
 *
 * A guard on console commands is one careless prefix match away from refusing
 * the deploy chain. `migrate --force`, `queue:work`, `config:cache` and every
 * foundation gate have to keep working, so the match is exact and that is
 * asserted here rather than assumed. The second trap is the mirror image: an
 * environment nobody declared must be refused, not permitted, because the cost
 * of a false refusal is naming your environment and the cost of a false permit
 * is an unaudited write path against clinical data.
 *
 * WHAT A PASS HERE DOES NOT PROVE
 *
 * That an interactive shell is unreachable. This stops the command inside THIS
 * application. A raw `php -a`, a database client or another framework's console
 * are access-control problems, and a test at the bottom records that limit so
 * nobody reads this file as coverage it does not have.
 */

use App\Exceptions\ForbiddenProductionCommandException;
use App\Support\Deploy\ForbiddenConsoleCommandGuard;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/** File-unique `repl` prefix. */
function replGuard(): ForbiddenConsoleCommandGuard
{
    return app(ForbiddenConsoleCommandGuard::class);
}

// ---------------------------------------------------------------------------
// The refusal, and which way it fails
// ---------------------------------------------------------------------------

it('refuses the interactive shell on every environment nobody declared safe', function () {
    foreach (['production', 'pilot', 'staging', 'prod', '', 'PRODUCTION', 'typo-env'] as $environment) {
        expect(replGuard()->shouldBlock('tinker', $environment))
            ->toBeTrue("tinker must be refused on '{$environment}'");
    }
});

it('permits it only on the environments that were declared', function () {
    foreach (['local', 'testing', 'LOCAL', '  testing  '] as $environment) {
        expect(replGuard()->shouldBlock('tinker', $environment))
            ->toBeFalse("tinker must remain available on '{$environment}'");
    }
});

it('matches the command name exactly, so the deploy chain keeps working', function () {
    // A prefix match here would refuse real work and teach an operator to
    // switch the guard off, which is strictly worse than not having it.
    foreach ([
        'migrate',
        'queue:work',
        'config:cache',
        'db:seed',
        'permission:cache-reset',
        'release:automated-smoke',
        'foundation:health-check',
        'doctor:rollout-readiness',
        'android:phase4a-pilot-readiness',
        'tinkerwell',
        'tinker:something',
        'my-tinker',
    ] as $command) {
        expect(replGuard()->shouldBlock($command, 'production'))
            ->toBeFalse("{$command} must not be refused");
    }
});

it('ignores a bare artisan invocation, which names no command at all', function () {
    expect(replGuard()->shouldBlock(null, 'production'))->toBeFalse();
    expect(replGuard()->shouldBlock('', 'production'))->toBeFalse();
    expect(replGuard()->shouldBlock('   ', 'production'))->toBeFalse();
});

// ---------------------------------------------------------------------------
// It is actually wired, not merely present
// ---------------------------------------------------------------------------

it('throws at CommandStarting rather than merely being available to call', function () {
    // A guard class nobody dispatches is documentation. This drives the real
    // event the application registers in its own service provider.
    config()->set('release_safety.forbidden_production_commands.repl_allowed_environments', ['local']);

    expect(fn () => event(new CommandStarting(
        'tinker',
        new ArrayInput([]),
        new BufferedOutput,
    )))->toThrow(ForbiddenProductionCommandException::class);
});

it('lets every other command through the same listener untouched', function () {
    config()->set('release_safety.forbidden_production_commands.repl_allowed_environments', ['local']);

    event(new CommandStarting(
        'migrate',
        new ArrayInput([]),
        new BufferedOutput,
    ));

    expect(true)->toBeTrue();
});

// ---------------------------------------------------------------------------
// The refusal must not cause the harm it prevents
// ---------------------------------------------------------------------------

it('is excluded from error reporting, because logging the refusal is the harm', function () {
    // Half the reason the shell is forbidden is that it writes ERROR records
    // which pin the monitoring log signal to WATCH for 24 hours. A guard that
    // logged an ERROR on every refusal would cause exactly that.
    $handler = app(ExceptionHandler::class);

    expect($handler->shouldReport(new ForbiddenProductionCommandException('refused')))->toBeFalse();
});

it('explains what to do instead, without naming a secret or a path', function () {
    $reason = replGuard()->reason('tinker');

    expect($reason)->toContain('tinker');
    expect($reason)->toContain('read-only');

    foreach (['password', 'DB_', 'APP_KEY', '/var/www', 'secret'] as $forbidden) {
        expect($reason)->not->toContain($forbidden);
    }
});

// ---------------------------------------------------------------------------
// Configuration, and the limit this control genuinely has
// ---------------------------------------------------------------------------

it('keeps the forbidden name in configuration, not in the guard that forbids it', function () {
    // A guard whose own source contains the literal it bans reddens on itself
    // under the release-safety scan, and a control that reddens on itself gets
    // switched off. The pattern scan over scripts is a sibling control, not
    // this one — so the name lives in config.
    expect(config('release_safety.forbidden_production_commands.blocked_console_commands'))
        ->toContain('tinker');
    expect(config('release_safety.forbidden_production_commands.repl_allowed_environments'))
        ->toBe(['local', 'testing']);
});

it('refuses everything once the allow-list is emptied, rather than opening up', function () {
    config()->set('release_safety.forbidden_production_commands.repl_allowed_environments', []);

    expect(replGuard()->shouldBlock('tinker', 'local'))->toBeTrue();
    expect(replGuard()->shouldBlock('tinker', 'testing'))->toBeTrue();
});

it('blocks nothing when no command is declared, so the list stays the only authority', function () {
    config()->set('release_safety.forbidden_production_commands.blocked_console_commands', []);

    expect(replGuard()->shouldBlock('tinker', 'production'))->toBeFalse();
});

it('records the one form it cannot reach, so nobody mistakes it for coverage', function () {
    // Stated as a test rather than a comment because a limit written only in
    // prose is a limit that gets forgotten the next time someone asks whether
    // the interactive shell is "blocked".
    $source = (string) file_get_contents(base_path('app/Support/Deploy/ForbiddenConsoleCommandGuard.php'));

    // Matched on one line of the docblock, not across the wrap — an assertion
    // that spans a line break breaks on reflow and gets deleted rather than
    // fixed.
    expect($source)->toContain('It does not stop a REPL started');
    expect($source)->toContain('WHAT IT DOES NOT DO, STATED PLAINLY');
});
