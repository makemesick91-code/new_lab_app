<?php

namespace App\Support\Android;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * PHASE4A-DOCTOR-ANDROID-PILOT-ACTIVATION-1 — the narrowest possible writer for
 * the one environment key this sprint arms.
 *
 * It writes ONE key, whose name comes from the feature-flag registry rather
 * than from a caller, so this cannot be pointed at another variable. It reads
 * and restores the whole file as an opaque blob for rollback and never parses,
 * reformats or reorders it — an environment file is full of secrets, and the
 * safest thing to do with the parts you do not need is not to understand them.
 *
 * Nothing here is ever logged or returned. The snapshot is held in memory for
 * the duration of one command and dropped.
 */
final class EnvFileWriter
{
    /**
     * @param  string  $envKey  the environment variable to write, resolved by the
     *                          caller from the flag registry.
     *
     * The flag KEY is deliberately not named here. `DoctorAppLoginGate` is the
     * one class permitted to read `doctor.trusted_device_enforcement`, and an
     * existing test enforces that by scanning app/, routes/ and bootstrap/ for
     * the literal — because a second reader is how enforcement starts
     * disagreeing with itself, and the disagreement gets found by a doctor who
     * cannot see their patients. So the environment variable arrives already
     * resolved, and this class never learns which flag it belongs to.
     */
    public function __construct(
        private readonly string $path,
        private readonly string $basePath,
        private readonly string $envKey,
    ) {}

    public function flagKey(): string
    {
        if (trim($this->envKey) === '') {
            throw new RuntimeException('Feature flag env key is not declared in the registry.');
        }

        return $this->envKey;
    }

    /** The whole file, for rollback. Opaque on purpose. */
    public function snapshot(): string
    {
        return is_file($this->path) ? (string) file_get_contents($this->path) : '';
    }

    public function restore(string $snapshot): void
    {
        $this->write($snapshot);
    }

    /**
     * Set the flag, replacing an existing declaration or appending one.
     *
     * Anchored to the start of a line so a commented-out or similarly-named key
     * is never mistaken for the real one.
     */
    public function setFlag(bool $armed): void
    {
        $key = $this->flagKey();
        $line = $key.'='.($armed ? 'true' : 'false');
        $contents = $this->snapshot();

        $pattern = '/^'.preg_quote($key, '/').'=.*$/m';

        $updated = preg_match($pattern, $contents) === 1
            ? (string) preg_replace($pattern, $line, $contents)
            : rtrim($contents, "\n")."\n\n# PHASE4A-DOCTOR-ANDROID-PILOT-ACTIVATION-1 — set by the audited enforcement command.\n".$line."\n";

        $this->write($updated);
    }

    /** Rebuild the cached config so a fresh process reads the new value. */
    public function rebuildConfigCache(): void
    {
        foreach ([['config:clear'], ['config:cache']] as $argv) {
            $process = new Process(array_merge(['php', 'artisan'], $argv), $this->basePath, null, null, 120);
            $process->run();

            if (! $process->isSuccessful()) {
                throw new RuntimeException('Config cache rebuild failed: '.implode(' ', $argv));
            }
        }
    }

    /**
     * Read the resulting state through a FRESH process.
     *
     * The process that wrote the file still holds the old config in memory, so
     * asking it would confirm a change that had not taken effect. This runs the
     * same diagnostic an operator would.
     *
     * @return array<string,mixed>
     */
    public function verifyThroughFreshProcess(): array
    {
        $process = new Process(
            ['php', 'artisan', 'android:phase4a-pilot-scope', '--json'],
            $this->basePath,
            null,
            null,
            120,
        );

        $process->run();

        $decoded = json_decode(trim($process->getOutput()), true);

        if (! is_array($decoded)) {
            throw new RuntimeException('Post-change verification produced no readable report.');
        }

        return $decoded;
    }

    private function write(string $contents): void
    {
        $mode = is_file($this->path) ? (fileperms($this->path) & 0777) : 0640;

        if (file_put_contents($this->path, $contents) === false) {
            throw new RuntimeException('Could not write the environment file.');
        }

        // An environment file holds secrets; a write must not widen its mode.
        @chmod($this->path, $mode);
    }
}
