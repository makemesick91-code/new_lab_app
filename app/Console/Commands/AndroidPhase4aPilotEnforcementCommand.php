<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Android\Phase4aEnforcementSwitch;
use App\Support\Android\Phase4aPilotScopeResolutionReport;
use Illuminate\Console\Command;

/**
 * PHASE4A-DOCTOR-ANDROID-PILOT-ACTIVATION-1 — the canonical way to arm or
 * disarm the pilot, so the change is accountable.
 *
 * The runbook requires `pilot_enforcement_scope_changed` in the audit trail and
 * nothing produced it: arming was a host file edit plus a cache rebuild, so no
 * application code ran and no row could be written. Renaming a device label
 * produced a full before/after with a named actor; denying a clinician their
 * browser produced nothing at all.
 *
 * `status` is read-only. `arm` and `disarm` require a reason and an actor, and
 * `arm` additionally refuses every configuration that would make the change a
 * fleet lockout or a silent no-op.
 */
class AndroidPhase4aPilotEnforcementCommand extends Command
{
    protected $signature = 'android:phase4a-pilot-enforcement
        {action : status, arm or disarm}
        {--actor= : user id of the person accountable for the change}
        {--reason= : why the change is being made, recorded in the audit trail}
        {--json : Output as JSON}';

    protected $description = 'Report, arm or disarm Phase 4A pilot-scoped doctor device enforcement. Every change is audited with actor, reason and post-change verification.';

    public function handle(Phase4aEnforcementSwitch $switch, Phase4aPilotScopeResolutionReport $report): int
    {
        $action = strtolower(trim((string) $this->argument('action')));

        if ($action === 'status') {
            return $this->renderStatus($switch, $report);
        }

        if (! in_array($action, ['arm', 'disarm'], true)) {
            $this->error('Unknown action. Use: status, arm or disarm.');

            return 1;
        }

        $actor = $this->resolveActor();

        if ($actor === null) {
            return 1;
        }

        $preChange = $report->build();

        try {
            $result = $action === 'arm'
                ? $switch->arm($actor, (string) $this->option('reason'), $preChange)
                : $switch->disarm($actor, (string) $this->option('reason'), $preChange);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return 1;
        }

        $this->info(sprintf(
            'Pilot enforcement %s. armed %s -> %s, post-change verdict %s.',
            $action === 'arm' ? 'armed' : 'disarmed',
            $result['before_armed'] ? 'true' : 'false',
            $result['after_armed'] ? 'true' : 'false',
            $result['post_change']['verdict'] ?? 'UNKNOWN',
        ));

        return 0;
    }

    private function resolveActor(): ?User
    {
        $id = (int) $this->option('actor');

        if ($id <= 0) {
            $this->error('An --actor user id is required: a change to who may reach patients has to be attributable.');

            return null;
        }

        $actor = User::query()->find($id);

        if ($actor === null) {
            $this->error('The --actor user id does not exist.');

            return null;
        }

        // Same authority that approves a device pairing.
        if (! $actor->hasAnyRole(['Super Admin', 'Supervisor RME'])) {
            $this->error('The --actor is not authorised to change pilot enforcement.');

            return null;
        }

        return $actor;
    }

    private function renderStatus(Phase4aEnforcementSwitch $switch, Phase4aPilotScopeResolutionReport $report): int
    {
        $status = $switch->status();
        $scope = $report->build();

        if ($this->option('json')) {
            $this->line((string) json_encode($status + ['scope' => $scope], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return 0;
        }

        foreach ($status as $key => $value) {
            $this->line(strtoupper($key).'='.(is_bool($value) ? ($value ? 'true' : 'false') : ($value ?? 'none')));
        }

        $this->line('SCOPE_VERDICT='.$scope['verdict']);

        return 0;
    }
}
