<?php

namespace App\Support\Deploy;

/**
 * DOCTOR-PWA-GLOBAL-ROLLOUT-READINESS-1 — refusing a forbidden command at the
 * moment it is typed.
 *
 * Written for PHASE4A-DOCTOR-ANDROID-PILOT-ACTIVATION-1 and salvaged here when
 * that pull request was closed: four of its five commits were already upstream
 * or superseded, and its centrepiece could no longer operate on a cohort. This
 * guard was the part that was still both needed and correct, so it landed on
 * its own rather than riding a branch that would have dragged single-doctor
 * scope semantics back over the multi-doctor cohort.
 *
 * WHY A SECOND GUARD
 *
 * `ProductionShellCommandGuard` scans the tracked executable scripts before
 * they ship, and it does that well. It cannot see an invocation that was never
 * written to a file — an operator typing into an interactive SSH session, a
 * one-off command in a terminal. Both production invocations that prompted this
 * class were exactly that shape, and extending the scanned-file list would have
 * caught neither.
 *
 * `CommandStarting` is the one place every invocation passes through regardless
 * of who typed it or how, so that is where this sits.
 *
 * THE DIRECTION IT FAILS IN
 *
 * Only explicitly allowed environments may run a blocked command. Production,
 * pilot, staging, a typo and an unset value are all refused, because the cost of
 * a false refusal is a developer naming their environment, and the cost of a
 * false permit is an unaudited write path against clinical data.
 *
 * WHAT IT DOES NOT DO, STATED PLAINLY
 *
 * It stops the command inside THIS application. It does not stop a REPL started
 * some other way — a raw `php -a`, a database client, another framework's
 * console. Those are environment and access-control problems, not repository
 * ones, and pretending otherwise would be the same mistake as a scanner that
 * only reads files claiming to cover a keyboard.
 *
 * The command names live in config, never here: a guard containing the literal
 * it forbids reddens on its own source, and a guard that reddens on itself gets
 * switched off.
 */
final class ForbiddenConsoleCommandGuard
{
    /** @return array<int,string> */
    public function blockedCommands(): array
    {
        return array_values(array_filter(array_map(
            static fn ($value): string => strtolower(trim((string) $value)),
            (array) config('release_safety.forbidden_production_commands.blocked_console_commands', []),
        ), static fn (string $value): bool => $value !== ''));
    }

    /** @return array<int,string> */
    public function allowedEnvironments(): array
    {
        return array_values(array_filter(array_map(
            static fn ($value): string => strtolower(trim((string) $value)),
            (array) config('release_safety.forbidden_production_commands.repl_allowed_environments', []),
        ), static fn (string $value): bool => $value !== ''));
    }

    /**
     * Should this invocation be refused?
     *
     * Exact match on the command name, not a prefix: a longer command that
     * merely begins with the same letters is a different command, and refusing
     * it would teach people to disable the guard.
     */
    public function shouldBlock(?string $command, string $environment): bool
    {
        $command = strtolower(trim((string) $command));

        if ($command === '' || ! in_array($command, $this->blockedCommands(), true)) {
            return false;
        }

        // Fail closed: an environment nobody declared is not an argument for
        // opening a REPL on it.
        return ! in_array(strtolower(trim($environment)), $this->allowedEnvironments(), true);
    }

    /** What the operator is told, and what to do instead. */
    public function reason(string $command): string
    {
        return sprintf(
            'Perintah "%s" ditolak: sebuah REPL interaktif di environment production adalah jalur tulis yang tidak terekam audit, '
            .'dan ia menulis ERROR ke log aplikasi sehingga sinyal monitoring tertahan di WATCH selama 24 jam. '
            .'Gunakan perintah diagnostik read-only, atau kueri read-only yang terekam.',
            $command,
        );
    }
}
