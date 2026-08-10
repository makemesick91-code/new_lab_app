<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Support;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * LEGACY-RME-PDF-1B — the single, safe way this module executes a binary.
 *
 * Every command is an ARGUMENT ARRAY. A caller-supplied value is only ever an
 * array element, never spliced into a shell string, so there is no shell to
 * inject into (Process runs the binary directly, without `sh -c`).
 *
 * Diagnostics (command line, stderr) go to the application log only. They are
 * never returned to the caller as a message, because the caller's message is
 * both rendered in the UI and persisted to `failure_message`.
 */
class LegacyRmeProcessRunner
{
    /**
     * @param  list<string>  $command
     * @return array{exit_code: int, stdout: string, stderr: string}
     *
     * @throws LegacyRmePdfException on timeout
     */
    public function run(array $command, ?string $workingDirectory = null, ?int $timeoutSeconds = null): array
    {
        $timeout = $timeoutSeconds ?? $this->defaultTimeout();

        $process = new Process($command, $workingDirectory);
        $process->setTimeout($timeout);
        $process->setIdleTimeout($timeout);
        // No interactive input, and never inherit the web request's environment
        // expectations — the binary gets a plain, deterministic invocation.
        $process->setInput(null);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            $this->logDiagnostic($command, 'timed out', '');

            throw LegacyRmePdfException::make(LegacyRmePdfFailure::PDF_PROCESS_TIMEOUT);
        }

        $exitCode = (int) $process->getExitCode();

        if ($exitCode !== 0) {
            $this->logDiagnostic($command, 'exit '.$exitCode, $process->getErrorOutput());
        }

        return [
            'exit_code' => $exitCode,
            'stdout' => $process->getOutput(),
            'stderr' => $process->getErrorOutput(),
        ];
    }

    public function binaryAvailable(string $binary): bool
    {
        // `command -v` would need a shell; probe the binary directly instead.
        $process = new Process([$binary, '-v']);
        $process->setTimeout(10);

        try {
            $process->run();
        } catch (\Throwable) {
            return false;
        }

        // Poppler tools print their version banner to stderr and may exit
        // non-zero for `-v`; a missing binary raises instead of running.
        return true;
    }

    private function defaultTimeout(): int
    {
        $timeout = (int) config('legacy_rme.processing.process_timeout', 180);

        return $timeout > 0 ? $timeout : 180;
    }

    /**
     * @param  list<string>  $command
     */
    private function logDiagnostic(array $command, string $outcome, string $stderr): void
    {
        Log::warning('legacy_rme.process_failed', [
            // The binary name only — never the full argument list, which
            // contains filesystem paths.
            'binary' => basename($command[0] ?? 'unknown'),
            'outcome' => $outcome,
            'stderr' => mb_substr(trim($stderr), 0, 500),
        ]);
    }
}
