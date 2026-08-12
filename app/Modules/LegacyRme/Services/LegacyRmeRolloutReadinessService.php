<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Services;

use App\Modules\Branch\Services\BranchService;
use App\Modules\LegacyRme\Support\LegacyRmeFeatureGuard;
use App\Modules\LegacyRme\Support\LegacyRmeRolloutCheck;
use App\Services\Foundation\FeatureFlagService;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessExceptionInterface;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * LEGACY-RME-PDF-ROLL-2 — the read-only gate that decides whether this
 * deployment may have the legacy RME archive switched ON, and whether it can
 * be switched back OFF.
 *
 * The service answers one question per check and never repairs anything. It
 * opens no clinical row, writes nothing outside a self-cleaning storage probe,
 * and cannot enable the feature — enabling stays an explicit operator action
 * taken against a green report.
 *
 * Every check is individually guarded. A dependency that throws degrades to
 * UNKNOWN rather than taking the command down, because a readiness gate that
 * crashes tells the operator nothing. UNKNOWN is never treated as GO.
 */
class LegacyRmeRolloutReadinessService
{
    public const STATUS_GO = 'GO';

    public const STATUS_WATCH = 'WATCH';

    public const STATUS_FAIL = 'FAIL';

    public const STATUS_UNKNOWN = 'UNKNOWN';

    public const DECISION_GO = 'GO';

    public const DECISION_WATCH = 'WATCH';

    public const DECISION_NO_GO = 'NO_GO';

    public function __construct(
        private readonly FeatureFlagService $flags,
        private readonly LegacyRmeFeatureGuard $guard,
        private readonly BranchService $branches,
        private readonly LegacyRmeBranchAdmissionService $admission,
        private readonly LegacyRmeIngestionCapacityService $capacity,
    ) {}

    /**
     * @param  'off'|'on'|null  $expectState  Assert the effective flag state. `off`
     *                                        before enabling and after rollback, `on` once enabled. Null skips
     *                                        the assertion and only reports what it found.
     * @return array<string, mixed>
     */
    public function report(?string $expectState = null): array
    {
        $checks = [
            $this->checkFeatureFlagRegistered(),
            $this->checkRuntimeOverrideCapture(),
            $this->checkEffectiveState($expectState),
            $this->checkFlagMetadataCurrent(),
            $this->checkSchema(),
            $this->checkPermissions(),
            $this->checkRoutes(),
            $this->checkPrivateDiskConfiguration(),
            $this->checkPrivateDiskWritable(),
            $this->checkPopplerBinaries(),
            $this->checkQueueContract(),
            $this->checkRollbackContract(),
            $this->checkPilotScopeApproved(),
            $this->checkBranchAdmission(),
            $this->checkIngestionCapacity(),
        ];

        $checks = array_map(static fn (LegacyRmeRolloutCheck $c) => $c->toArray(), $checks);

        return [
            'sprint' => (string) config('legacy_rme_rollout.sprint'),
            'generated_at' => now()->toIso8601String(),
            'environment' => (string) app()->environment(),
            'expected_state' => $expectState,
            'decision' => $this->decide($checks),
            'summary' => $this->summarise($checks),
            'checks' => $checks,
        ];
    }

    /**
     * A single FAIL blocks the rollout outright. UNKNOWN is deliberately also
     * blocking: "we could not tell" is not a basis for switching a clinical
     * feature on. WATCH degrades but does not block by itself.
     *
     * @param  array<int, array<string, mixed>>  $checks
     */
    public function decide(array $checks): string
    {
        foreach ($checks as $check) {
            if (in_array($check['status'], [self::STATUS_FAIL, self::STATUS_UNKNOWN], true)) {
                return self::DECISION_NO_GO;
            }
        }

        foreach ($checks as $check) {
            if ($check['status'] === self::STATUS_WATCH) {
                return self::DECISION_WATCH;
            }
        }

        return self::DECISION_GO;
    }

    /**
     * @param  array<int, array<string, mixed>>  $checks
     * @return array<string, int>
     */
    private function summarise(array $checks): array
    {
        $summary = [self::STATUS_GO => 0, self::STATUS_WATCH => 0, self::STATUS_FAIL => 0, self::STATUS_UNKNOWN => 0];

        foreach ($checks as $check) {
            $summary[$check['status']] = ($summary[$check['status']] ?? 0) + 1;
        }

        return $summary;
    }

    private function flagKey(): string
    {
        return $this->guard->flagKey();
    }

    private function checkFeatureFlagRegistered(): LegacyRmeRolloutCheck
    {
        return $this->guarded('feature_flag_registered', function (): LegacyRmeRolloutCheck {
            $configured = (string) config('legacy_rme_rollout.feature_flag');
            $runtimeKey = $this->flagKey();

            if ($configured !== '' && $configured !== $runtimeKey) {
                return LegacyRmeRolloutCheck::fail(
                    'feature_flag_registered',
                    'The rollout contract audits a different flag from the one the runtime reads.',
                    ['contract_key' => $configured, 'runtime_key' => $runtimeKey],
                    'Align config/legacy_rme_rollout.php `feature_flag` with config/legacy_rme.php `feature_flag`.',
                );
            }

            $metadata = $this->flags->metadata($runtimeKey);

            if ($metadata === []) {
                return LegacyRmeRolloutCheck::fail(
                    'feature_flag_registered',
                    'The legacy archive flag is not registered in the feature flag registry.',
                    ['key' => $runtimeKey],
                    'Register the flag in config/feature_flags.php before enabling the archive.',
                );
            }

            return LegacyRmeRolloutCheck::go(
                'feature_flag_registered',
                'The legacy archive flag is registered.',
                [
                    'key' => $runtimeKey,
                    'risk_level' => $metadata['risk_level'] ?? null,
                    'rollout_status' => $metadata['rollout_status'] ?? null,
                ],
            );
        });
    }

    /**
     * LEGACY-RME-PDF-ROLL-1 invariant. A declared env_key that was never
     * captured at config-BUILD time is silently ignored once `config:cache`
     * runs — the operator would set the override, see nothing change, and have
     * no signal why. That is a rollout blocker in both directions: it breaks
     * enablement AND rollback.
     */
    private function checkRuntimeOverrideCapture(): LegacyRmeRolloutCheck
    {
        return $this->guarded('runtime_override_capture', function (): LegacyRmeRolloutCheck {
            $metadata = $this->flags->metadata($this->flagKey());
            $envKey = (string) ($metadata['env_key'] ?? '');
            $captured = (bool) ($metadata['env_captured'] ?? false);

            if ($envKey === '') {
                return LegacyRmeRolloutCheck::fail(
                    'runtime_override_capture',
                    'The flag declares no environment override key, so it cannot be switched without a code change.',
                    [],
                    'Declare an env_key on the flag definition.',
                );
            }

            if (! $captured) {
                return LegacyRmeRolloutCheck::fail(
                    'runtime_override_capture',
                    'The environment override is not captured at config-build time and would be ignored under a cached config.',
                    ['env_key' => $envKey],
                    'Restore the ROLL-1 config-build-time capture in config/feature_flags.php.',
                );
            }

            return LegacyRmeRolloutCheck::go(
                'runtime_override_capture',
                'The environment override is captured at config-build time and survives a cached config.',
                ['env_key' => $envKey, 'resolution' => $metadata['env_resolution'] ?? null],
            );
        });
    }

    private function checkEffectiveState(?string $expectState): LegacyRmeRolloutCheck
    {
        return $this->guarded('effective_state', function () use ($expectState): LegacyRmeRolloutCheck {
            $metadata = $this->flags->metadata($this->flagKey());
            $enabled = $this->guard->enabled();

            $context = [
                'enabled' => $enabled,
                'resolution' => $metadata['env_resolution'] ?? null,
                'registry_enabled' => $metadata['enabled'] ?? null,
            ];

            // The guard is what every route and service actually consults. If it
            // disagrees with the registry the two have diverged and no state
            // claim can be trusted.
            if (array_key_exists('enabled', $metadata) && (bool) $metadata['enabled'] !== $enabled) {
                return LegacyRmeRolloutCheck::fail(
                    'effective_state',
                    'The feature guard and the flag registry disagree about the effective state.',
                    $context,
                    'Investigate FeatureFlagService resolution before touching the rollout.',
                );
            }

            if ($expectState === null) {
                return LegacyRmeRolloutCheck::go(
                    'effective_state',
                    $enabled
                        ? 'The legacy archive is currently ON.'
                        : 'The legacy archive is currently OFF.',
                    $context,
                );
            }

            $expected = $expectState === 'on';

            if ($enabled !== $expected) {
                return LegacyRmeRolloutCheck::fail(
                    'effective_state',
                    sprintf(
                        'Expected the legacy archive to be %s but it is %s.',
                        $expected ? 'ON' : 'OFF',
                        $enabled ? 'ON' : 'OFF',
                    ),
                    $context,
                    $expected
                        ? 'Set the override to true and rebuild the config cache, then re-run.'
                        : 'Set the override to false and rebuild the config cache, then re-run.',
                );
            }

            return LegacyRmeRolloutCheck::go(
                'effective_state',
                sprintf('The legacy archive is %s, as expected.', $enabled ? 'ON' : 'OFF'),
                $context,
            );
        });
    }

    private function checkFlagMetadataCurrent(): LegacyRmeRolloutCheck
    {
        return $this->guarded('flag_metadata_current', function (): LegacyRmeRolloutCheck {
            $metadata = $this->flags->metadata($this->flagKey());
            $delivered = (string) config('legacy_rme_rollout.delivered_sprint');
            $reviewTarget = (string) ($metadata['review_target'] ?? '');

            if ($delivered !== '' && $reviewTarget !== '' && $reviewTarget !== $delivered) {
                return LegacyRmeRolloutCheck::watch(
                    'flag_metadata_current',
                    'The flag review target is stale relative to the delivered runtime.',
                    ['review_target' => $reviewTarget, 'delivered_sprint' => $delivered],
                    'Update the flag `review_target` so the governance record matches the shipped runtime.',
                );
            }

            return LegacyRmeRolloutCheck::go(
                'flag_metadata_current',
                'The flag governance metadata matches the delivered runtime.',
                ['review_target' => $reviewTarget, 'delivered_sprint' => $delivered],
            );
        });
    }

    private function checkSchema(): LegacyRmeRolloutCheck
    {
        return $this->guarded('schema_ready', function (): LegacyRmeRolloutCheck {
            $required = (array) config('legacy_rme_rollout.required_tables', []);
            $missing = array_values(array_filter(
                $required,
                static fn (string $table) => ! Schema::hasTable($table),
            ));

            if ($missing !== []) {
                return LegacyRmeRolloutCheck::fail(
                    'schema_ready',
                    'Legacy archive tables are missing from this database.',
                    ['missing' => $missing],
                    'Run the pending migrations before enabling the archive.',
                );
            }

            return LegacyRmeRolloutCheck::go(
                'schema_ready',
                'All legacy archive tables are present.',
                ['tables' => count($required)],
            );
        });
    }

    private function checkPermissions(): LegacyRmeRolloutCheck
    {
        return $this->guarded('permissions_registered', function (): LegacyRmeRolloutCheck {
            $required = (array) config('legacy_rme_rollout.required_permissions', []);

            if (! Schema::hasTable('permissions')) {
                return LegacyRmeRolloutCheck::fail(
                    'permissions_registered',
                    'The permission table does not exist, so the archive authorization boundary cannot be verified.',
                    [],
                    'Run the pending migrations, then seed the permissions.',
                );
            }

            $present = DB::table('permissions')->whereIn('name', $required)->pluck('name')->all();
            $missing = array_values(array_diff($required, $present));

            if ($missing !== []) {
                return LegacyRmeRolloutCheck::fail(
                    'permissions_registered',
                    'Legacy archive permissions are not registered, so authorized operators would be refused.',
                    ['missing' => $missing],
                    'Run the permission seeder, then reset the permission cache.',
                );
            }

            return LegacyRmeRolloutCheck::go(
                'permissions_registered',
                'All legacy archive permissions are registered.',
                ['permissions' => count($required)],
            );
        });
    }

    private function checkRoutes(): LegacyRmeRolloutCheck
    {
        return $this->guarded('routes_registered', function (): LegacyRmeRolloutCheck {
            // Console never builds the router's web middleware group, so the
            // HTTP kernel has to be resolved first or every route name reads as
            // missing on the CLI.
            app()->make(HttpKernel::class);

            $groups = (array) config('legacy_rme_rollout.required_routes', []);
            $missing = [];
            $total = 0;

            foreach ($groups as $group => $names) {
                foreach ((array) $names as $name) {
                    $total++;
                    if (! Route::has($name)) {
                        $missing[] = $group.':'.$name;
                    }
                }
            }

            if ($missing !== []) {
                return LegacyRmeRolloutCheck::fail(
                    'routes_registered',
                    'Legacy archive routes are not registered on this deployment.',
                    ['missing' => $missing],
                    'Clear the route cache and rebuild it, then re-run.',
                );
            }

            return LegacyRmeRolloutCheck::go(
                'routes_registered',
                'All legacy archive routes are registered.',
                ['routes' => $total],
            );
        });
    }

    private function checkPrivateDiskConfiguration(): LegacyRmeRolloutCheck
    {
        return $this->guarded('private_disk_configured', function (): LegacyRmeRolloutCheck {
            $disk = (string) config('legacy_rme.storage.disk');
            $forbidden = (array) config('legacy_rme_rollout.storage.forbidden_disks', []);

            if ($disk === '') {
                return LegacyRmeRolloutCheck::fail(
                    'private_disk_configured',
                    'No storage disk is configured for the legacy archive.',
                    [],
                    'Set the legacy archive disk in config/legacy_rme.php.',
                );
            }

            if (in_array($disk, $forbidden, true)) {
                return LegacyRmeRolloutCheck::fail(
                    'private_disk_configured',
                    'The legacy archive is pointed at a disk that can expose clinical evidence publicly.',
                    ['disk' => $disk],
                    'Point the archive at the dedicated private disk.',
                );
            }

            $definition = (array) config('filesystems.disks.'.$disk, []);

            if ($definition === []) {
                return LegacyRmeRolloutCheck::fail(
                    'private_disk_configured',
                    'The configured legacy archive disk is not defined.',
                    ['disk' => $disk],
                    'Define the disk in config/filesystems.php.',
                );
            }

            $visibility = (string) ($definition['visibility'] ?? '');
            $served = (bool) ($definition['serve'] ?? false);
            $expectedVisibility = (string) config('legacy_rme_rollout.storage.expected_visibility', 'private');

            if ($visibility !== $expectedVisibility || $served !== false) {
                return LegacyRmeRolloutCheck::fail(
                    'private_disk_configured',
                    'The legacy archive disk is not private and non-served.',
                    ['disk' => $disk, 'visibility' => $visibility, 'serve' => $served],
                    'Set the disk to private visibility and disable framework serving.',
                );
            }

            return LegacyRmeRolloutCheck::go(
                'private_disk_configured',
                'The legacy archive disk is private and not framework-served.',
                ['disk' => $disk],
            );
        });
    }

    /**
     * Non-destructive: writes one probe file under a dedicated prefix, reads it
     * back and deletes it. It never touches an archive path and never leaves a
     * file behind, including on the failure paths.
     */
    private function checkPrivateDiskWritable(): LegacyRmeRolloutCheck
    {
        return $this->guarded('private_disk_writable', function (): LegacyRmeRolloutCheck {
            $disk = (string) config('legacy_rme.storage.disk');
            $forbidden = (array) config('legacy_rme_rollout.storage.forbidden_disks', []);

            // Never probe a disk the configuration check already rejected. A
            // misconfigured archive pointed at the public disk must not have a
            // probe file written into a web-reachable root as a side effect of
            // auditing it.
            if ($disk === '' || in_array($disk, $forbidden, true) || config('filesystems.disks.'.$disk) === null) {
                return LegacyRmeRolloutCheck::fail(
                    'private_disk_writable',
                    'Writability was not probed because the configured archive disk is unusable.',
                    ['disk' => $disk],
                    'Fix the archive disk configuration first, then re-run.',
                );
            }

            $prefix = (string) config('legacy_rme_rollout.storage.probe_prefix', 'rollout-readiness');
            $path = $prefix.'/probe-'.bin2hex(random_bytes(8)).'.txt';
            $payload = 'legacy-rme-rollout-readiness-probe';

            $filesystem = Storage::disk($disk);

            try {
                $filesystem->put($path, $payload);
                $readBack = $filesystem->get($path);

                if ($readBack !== $payload) {
                    return LegacyRmeRolloutCheck::fail(
                        'private_disk_writable',
                        'The legacy archive disk did not read back what was written to it.',
                        ['disk' => $disk],
                        'Investigate the storage backend before enabling the archive.',
                    );
                }

                return LegacyRmeRolloutCheck::go(
                    'private_disk_writable',
                    'The legacy archive disk accepted a write, a read and a delete.',
                    ['disk' => $disk],
                );
            } finally {
                try {
                    if ($filesystem->exists($path)) {
                        $filesystem->delete($path);
                    }
                } catch (Throwable) {
                    // A probe that cannot clean itself up must not mask the
                    // result of the check it was probing for.
                }
            }
        });
    }

    private function checkPopplerBinaries(): LegacyRmeRolloutCheck
    {
        return $this->guarded('poppler_available', function (): LegacyRmeRolloutCheck {
            $configured = [
                'pdfinfo' => (string) config('legacy_rme.processing.pdfinfo_binary', 'pdfinfo'),
                'pdftoppm' => (string) config('legacy_rme.processing.pdftoppm_binary', 'pdftoppm'),
            ];

            $missing = [];
            $versions = [];

            foreach ($configured as $label => $binary) {
                $version = $this->probeBinaryVersion($binary);

                if ($version === null) {
                    $missing[] = $label;

                    continue;
                }

                $versions[$label] = $version;
            }

            if ($missing !== []) {
                return LegacyRmeRolloutCheck::fail(
                    'poppler_available',
                    'Poppler is not available, so an import would stall in processing and never render a page.',
                    ['missing' => $missing],
                    'Install poppler-utils on this host, then re-run.',
                );
            }

            return LegacyRmeRolloutCheck::go(
                'poppler_available',
                'Poppler is available for page rendering.',
                $versions,
            );
        });
    }

    private function probeBinaryVersion(string $binary): ?string
    {
        if ($binary === '') {
            return null;
        }

        try {
            // Argument array, never an interpolated shell string. The binary
            // name comes from config and is never user input.
            $process = new Process([$binary, '-v']);
            $process->setTimeout(15);
            $process->run();

            // Poppler writes its banner to stderr and exits non-zero for `-v`
            // on some builds, so presence is judged by the banner, not the code.
            $output = trim($process->getErrorOutput()."\n".$process->getOutput());

            if ($output === '') {
                return null;
            }

            $firstLine = trim(strtok($output, "\n") ?: '');

            return $firstLine === '' ? null : $firstLine;
        } catch (ProcessExceptionInterface) {
            return null;
        } catch (Throwable) {
            // A binary that cannot even be launched is missing for our purposes.
            // Reporting it as absent is more useful to the operator than
            // collapsing the whole check into UNKNOWN.
            return null;
        }
    }

    /**
     * Rasterization is queued by design, so the queue is on the critical path
     * of every import. `sync` would drag a multi-minute render into the
     * operator's HTTP request; no worker at all leaves the import in
     * PROCESSING forever.
     */
    private function checkQueueContract(): LegacyRmeRolloutCheck
    {
        return $this->guarded('queue_contract', function (): LegacyRmeRolloutCheck {
            $connection = (string) config('queue.default');
            $forbidden = (array) config('legacy_rme_rollout.queue.forbidden_connections', []);
            $workerEnvironments = (array) config('legacy_rme_rollout.queue.worker_required_environments', []);
            $environment = (string) app()->environment();
            $workerExpected = in_array($environment, $workerEnvironments, true);

            $context = [
                'connection' => $connection,
                'environment' => $environment,
                'worker_expected' => $workerExpected,
            ];

            if ($workerExpected && in_array($connection, $forbidden, true)) {
                return LegacyRmeRolloutCheck::fail(
                    'queue_contract',
                    'Page rendering is queued, but this environment would run it inline in the upload request.',
                    $context,
                    'Point the queue at a real background connection before enabling the archive.',
                );
            }

            // LEGACY-RME-PDF-ROLL-2 pilot finding: a usable CONNECTION is not a
            // usable PIPELINE. Rasterization is dispatched to a dedicated queue,
            // and a worker that does not consume that queue leaves every import
            // stuck at QUEUED — no failed job, no error, nothing to notice. The
            // first pilot upload hit exactly that while this check said GO.
            $renderQueue = (string) config('legacy_rme.processing.queue', 'legacy-rme-documents');
            $context['render_queue'] = $renderQueue;

            // Prefer the INSTALLED unit over the tracked one. The deploy script
            // deliberately never installs or starts a worker (ENT-5), so the
            // repository file and what systemd actually runs can diverge: an
            // operator who edits the tracked unit, deploys and restarts still
            // runs the OLD unit. Reading only the tracked file would report GO
            // for a production worker that still ignores the render queue —
            // the same false GO this check was written to remove.
            $unitPath = (string) config('legacy_rme_rollout.queue.worker_unit_file', '');
            $installedPath = (string) config('legacy_rme_rollout.queue.installed_worker_unit_file', '');

            $unitContents = null;
            $unitSource = null;

            foreach ([['installed', $installedPath, false], ['tracked', $unitPath, true]] as [$label, $path, $relative]) {
                if ($path === '') {
                    continue;
                }

                $absolute = $relative ? base_path($path) : $path;

                if (is_file($absolute) && is_readable($absolute)) {
                    $unitContents = (string) file_get_contents($absolute);
                    $unitSource = $label;
                    break;
                }
            }

            $context['worker_unit_file'] = $unitPath;
            $context['installed_worker_unit_file'] = $installedPath;
            $context['worker_unit_source'] = $unitSource;
            $context['worker_unit_readable'] = $unitContents !== null;

            if ($workerExpected) {
                if ($unitContents === null) {
                    return LegacyRmeRolloutCheck::fail(
                        'queue_contract',
                        'The queue worker unit could not be read, so it is unknown whether the rendering queue is consumed.',
                        $context,
                        'Restore the tracked worker unit file before enabling the archive.',
                    );
                }

                if (! $this->workerUnitConsumesQueue($unitContents, $renderQueue)) {
                    return LegacyRmeRolloutCheck::fail(
                        'queue_contract',
                        sprintf('The queue worker does not consume the "%s" queue, so no import would ever be rendered.', $renderQueue),
                        $context,
                        sprintf('Add "%s" to the worker unit --queue list and restart the worker.', $renderQueue),
                    );
                }

                // The name must also be an approved ENT-5 queue, otherwise the
                // worker and the queue governance contract disagree.
                $allowed = (array) config('queue_governance.ent5_retry_failed_job.allowed_queue_names', []);
                $context['queue_name_approved'] = in_array($renderQueue, $allowed, true);

                if (! $context['queue_name_approved']) {
                    return LegacyRmeRolloutCheck::fail(
                        'queue_contract',
                        sprintf('The rendering queue "%s" is not an approved queue name.', $renderQueue),
                        $context,
                        'Declare the queue in the ENT-5 allowed queue names before enabling the archive.',
                    );
                }
            }

            if (Schema::hasTable('failed_jobs')) {
                $context['failed_jobs'] = DB::table('failed_jobs')->count();
            }

            return LegacyRmeRolloutCheck::go(
                'queue_contract',
                'The queue connection can carry the rendering job, and the worker consumes its queue.',
                $context,
            );
        });
    }

    /**
     * Whether a systemd worker unit actually consumes the given queue.
     *
     * The unit lists its queues as a single comma-separated `--queue=` value,
     * so a substring match would happily accept `legacy-rme-documents-archive`
     * for `legacy-rme-documents`. The list is split and compared exactly.
     */
    private function workerUnitConsumesQueue(string $unitContents, string $queue): bool
    {
        if ($queue === '') {
            return false;
        }

        if (preg_match_all('/--queue[=\s]+([^\s\\\\]+)/', $unitContents, $matches) !== false) {
            foreach (($matches[1] ?? []) as $list) {
                foreach (explode(',', $list) as $name) {
                    if (trim($name) === $queue) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private function checkRollbackContract(): LegacyRmeRolloutCheck
    {
        return $this->guarded('rollback_contract', function (): LegacyRmeRolloutCheck {
            $metadata = $this->flags->metadata($this->flagKey());
            $action = trim((string) ($metadata['rollback_action'] ?? ''));
            $runbook = (string) config('legacy_rme_rollout.rollback.runbook', '');
            $requireAction = (bool) config('legacy_rme_rollout.rollback.require_documented_rollback_action', true);

            if ($requireAction && $action === '') {
                return LegacyRmeRolloutCheck::fail(
                    'rollback_contract',
                    'The flag documents no rollback action, so the OFF path is undefined.',
                    [],
                    'Document the rollback action on the flag definition.',
                );
            }

            $runbookPresent = $runbook !== '' && is_file(base_path($runbook));

            if (! $runbookPresent) {
                return LegacyRmeRolloutCheck::fail(
                    'rollback_contract',
                    'The rollout runbook is missing, so the operator has no rehearsed OFF path.',
                    ['runbook' => $runbook],
                    'Restore the rollout runbook before enabling the archive.',
                );
            }

            return LegacyRmeRolloutCheck::go(
                'rollback_contract',
                'A documented rollback action and rollout runbook are both present.',
                ['runbook' => $runbook],
            );
        });
    }

    /**
     * The decisive gate. Absence of an approval is NOT a warning — it is the
     * difference between a controlled pilot and an unauthorized clinical
     * import, so it fails closed.
     */
    private function checkPilotScopeApproved(): LegacyRmeRolloutCheck
    {
        return $this->guarded('pilot_scope_approved', function (): LegacyRmeRolloutCheck {
            $approved = (bool) config('legacy_rme_rollout.pilot_scope.approved', false);
            $reference = trim((string) config('legacy_rme_rollout.pilot_scope.approval_reference', ''));
            $branchCode = trim((string) config('legacy_rme_rollout.pilot_scope.branch_code', ''));
            $forbidden = (array) config('legacy_rme_rollout.pilot_scope.forbidden_branch_codes', []);

            if (! $approved) {
                return LegacyRmeRolloutCheck::fail(
                    'pilot_scope_approved',
                    'No approved pilot scope is declared, so no controlled import may run on this deployment.',
                    ['approved' => false],
                    'Record the operator approval (reference and branch code) before enabling the archive.',
                );
            }

            if ($reference === '' || $branchCode === '') {
                return LegacyRmeRolloutCheck::fail(
                    'pilot_scope_approved',
                    'The pilot scope is marked approved but is incomplete.',
                    ['has_reference' => $reference !== '', 'has_branch_code' => $branchCode !== ''],
                    'Provide both the approval reference and the pilot branch code.',
                );
            }

            if (in_array(strtoupper($branchCode), array_map('strtoupper', $forbidden), true)) {
                return LegacyRmeRolloutCheck::fail(
                    'pilot_scope_approved',
                    'The declared pilot branch may never host a clinical pilot.',
                    ['branch_code' => $branchCode],
                    'Choose an active RME-enabled clinic branch.',
                );
            }

            $branch = $this->branches->listRmeEnabled()
                ->first(fn ($candidate) => strtoupper((string) $candidate->code) === strtoupper($branchCode));

            if ($branch === null) {
                return LegacyRmeRolloutCheck::fail(
                    'pilot_scope_approved',
                    'The declared pilot branch is not an active RME-enabled branch on this deployment.',
                    ['branch_code' => $branchCode],
                    'Point the pilot at an active RME-enabled branch, or enable RME on that branch.',
                );
            }

            return LegacyRmeRolloutCheck::go(
                'pilot_scope_approved',
                'An approved, RME-enabled pilot scope is declared.',
                [
                    'approval_reference' => $reference,
                    'branch_code' => (string) $branch->code,
                ],
            );
        });
    }

    /**
     * LEGACY-RME-PDF-ROLL-3 — is branch admission a real gate on this
     * deployment, and does the declared wave point at branches that can
     * actually receive an archive?
     *
     * ROLL-2 shipped `pilot_scope.branch_code` that only this report ever read,
     * so the runtime admitted every RME branch. This check exists to make sure
     * that can never silently return: it verifies the gate is ENFORCED, and
     * that every admitted code resolves to a real, active, RME-enabled branch.
     */
    private function checkBranchAdmission(): LegacyRmeRolloutCheck
    {
        return $this->guarded('branch_admission', function (): LegacyRmeRolloutCheck {
            $enforced = $this->admission->enforced();
            $admitted = $this->admission->admittedBranchCodes();
            $wave = $this->admission->wave();
            $environment = (string) app()->environment();
            $realDeployment = in_array(
                $environment,
                (array) config('legacy_rme_rollout.queue.worker_required_environments', []),
                true,
            );

            $context = [
                'enforced' => $enforced,
                'admitted_branch_codes' => implode(',', $admitted),
                'admitted_count' => count($admitted),
                'wave' => $wave,
                'environment' => $environment,
            ];

            // A production-like deployment with the gate switched off is the
            // pre-ROLL-3 defect restored on purpose. It is never a warning.
            if (! $enforced && $realDeployment) {
                return LegacyRmeRolloutCheck::fail(
                    'branch_admission',
                    'Branch admission is not enforced on a deployment that runs real migrations, so every RME branch could import.',
                    $context,
                    'Set the admission enforcement switch back on and rebuild the config cache.',
                );
            }

            if (! $enforced) {
                return LegacyRmeRolloutCheck::watch(
                    'branch_admission',
                    'Branch admission is not enforced in this environment.',
                    $context,
                    'Enforcement may only be off in local or CI environments.',
                );
            }

            $forbidden = LegacyRmeBranchAdmissionService::forbiddenBranchCodes();
            $declaredForbidden = array_values(array_intersect($admitted, $forbidden));

            if ($declaredForbidden !== []) {
                return LegacyRmeRolloutCheck::fail(
                    'branch_admission',
                    'A branch that may never host a clinical migration is declared in the admission allowlist.',
                    $context + ['forbidden_declared' => implode(',', $declaredForbidden)],
                    'Remove the administrative branch from the allowlist.',
                );
            }

            // Every admitted code must name a branch that can actually own RME
            // history. A typo that admits nothing is worse than an empty list,
            // because the operator believes a branch is live when it is not.
            $known = $this->branches->listRmeEnabled()
                ->map(static fn ($branch): string => strtoupper((string) $branch->code))
                ->all();

            $unknown = array_values(array_diff($admitted, $known));

            if ($unknown !== []) {
                return LegacyRmeRolloutCheck::fail(
                    'branch_admission',
                    'The admission allowlist names a branch that is not an active RME-enabled branch on this deployment.',
                    $context + ['unknown_declared' => implode(',', $unknown)],
                    'Correct the branch code, or activate and RME-enable that branch.',
                );
            }

            // Capability ON with nothing admitted is safe but useless, and
            // usually means a wave was declared without its allowlist.
            if ($admitted === [] && $this->guard->enabled()) {
                return LegacyRmeRolloutCheck::watch(
                    'branch_admission',
                    'The archive is switched on but no branch is admitted, so no migration can start.',
                    $context,
                    'Declare the wave branch codes, or switch the capability back off.',
                );
            }

            if ($admitted === []) {
                return LegacyRmeRolloutCheck::go(
                    'branch_admission',
                    'Branch admission is enforced and no branch is admitted — the closed, pre-wave state.',
                    $context,
                );
            }

            return LegacyRmeRolloutCheck::go(
                'branch_admission',
                'Branch admission is enforced and every admitted branch is an active RME-enabled branch.',
                $context,
            );
        });
    }

    /**
     * LEGACY-RME-PDF-ROLL-3 — does the rendering pipeline have room, and is
     * backpressure configured at all?
     *
     * Saturation is reported as WATCH rather than FAIL: a deep queue is a
     * transient operational condition that resolves itself as the worker
     * drains, and refusing a deploy over it would be the wrong response. What
     * matters at release time is that the ceiling EXISTS.
     */
    private function checkIngestionCapacity(): LegacyRmeRolloutCheck
    {
        return $this->guarded('ingestion_capacity', function (): LegacyRmeRolloutCheck {
            $capacity = $this->capacity->evaluate();
            $context = $capacity->measurements;

            if (! $this->capacity->enforced()) {
                return LegacyRmeRolloutCheck::watch(
                    'ingestion_capacity',
                    'Ingestion backpressure is disabled, so a saturated pipeline would not refuse new uploads.',
                    $context,
                    'Enable capacity enforcement before running a multi-branch wave.',
                );
            }

            $thresholds = [
                (int) config('legacy_rme_rollout.capacity.max_pending_jobs', 0),
                (int) config('legacy_rme_rollout.capacity.max_oldest_pending_seconds', 0),
                (int) config('legacy_rme_rollout.capacity.min_free_disk_bytes', 0),
            ];

            if (array_sum(array_map(static fn (int $v): int => $v > 0 ? 1 : 0, $thresholds)) === 0) {
                return LegacyRmeRolloutCheck::watch(
                    'ingestion_capacity',
                    'Every backpressure threshold is disabled, so ingestion has no ceiling.',
                    $context,
                    'Configure at least one capacity threshold before running a multi-branch wave.',
                );
            }

            if ($capacity->isSaturated()) {
                return LegacyRmeRolloutCheck::watch(
                    'ingestion_capacity',
                    'The rendering pipeline is currently saturated, so new uploads are being refused.',
                    $context + ['rule_code' => $capacity->code],
                    'Let the queue drain, or investigate the worker if it is not draining.',
                );
            }

            return LegacyRmeRolloutCheck::go(
                'ingestion_capacity',
                'Ingestion backpressure is configured and the rendering pipeline has room.',
                $context,
            );
        });
    }

    /**
     * @param  callable(): LegacyRmeRolloutCheck  $probe
     */
    private function guarded(string $id, callable $probe): LegacyRmeRolloutCheck
    {
        try {
            return $probe();
        } catch (Throwable $e) {
            return LegacyRmeRolloutCheck::unknown(
                $id,
                'The check could not be evaluated on this deployment.',
                ['error' => class_basename($e)],
                'Investigate the underlying dependency; an unevaluated check never counts as ready.',
            );
        }
    }
}
