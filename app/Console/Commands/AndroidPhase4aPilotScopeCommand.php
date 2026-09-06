<?php

namespace App\Console\Commands;

use App\Support\Android\Phase4aPilotScopeResolutionReport;
use Illuminate\Console\Command;

/**
 * PHASE4A-DOCTOR-ANDROID-PILOT-ACTIVATION-1 — the check an operator runs
 * immediately before arming enforcement, and again immediately after.
 *
 * Its whole job is to name the doctors the configured scope covers, because
 * `android:phase4a-pilot-readiness` can only say whether the scope is USABLE
 * and a scope pointed at the wrong doctor is perfectly usable.
 *
 * Read-only and credential-free: it asks the login gate what it would decide
 * for a request carrying no device session, which is what a browser carries.
 * It never logs anyone in, needs no password, writes nothing and arms nothing.
 *
 * Safe on production. It prints user ids and display names — the operator has
 * to be able to read the doctor's name to confirm the right person — and
 * deliberately prints no email, no hash, no token and no identity number.
 */
class AndroidPhase4aPilotScopeCommand extends Command
{
    protected $signature = 'android:phase4a-pilot-scope
        {--json : Output the report as JSON}
        {--strict : Exit non-zero unless the verdict is GO}';

    protected $description = 'Report which doctor accounts the configured Phase 4A enforcement scope actually covers, and which keep browser login. Read-only, credential-free, no secrets.';

    public function handle(Phase4aPilotScopeResolutionReport $report): int
    {
        $result = $report->build();

        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->render($result);
        }

        if ($result['verdict'] === Phase4aPilotScopeResolutionReport::VERDICT_FAIL) {
            return 1;
        }

        if ($this->option('strict') && $result['verdict'] !== Phase4aPilotScopeResolutionReport::VERDICT_GO) {
            return 1;
        }

        return 0;
    }

    /**
     * @param  array<string,mixed>  $result
     */
    private function render(array $result): void
    {
        $this->info('Phase 4A pilot enforcement scope — PHASE4A-DOCTOR-ANDROID-PILOT-ACTIVATION-1');
        $this->newLine();

        if ($result['covered_doctors'] !== []) {
            $this->table(
                ['Covered user id', 'Doctor'],
                array_map(
                    static fn (array $row): array => [$row['user_id'], $row['name']],
                    $result['covered_doctors'],
                ),
            );
        } else {
            $this->line('No doctor account is inside the configured enforcement scope.');
        }

        $this->newLine();

        foreach ($result['findings'] as $finding) {
            $this->warn('finding: '.$finding);
        }

        $this->line('ENFORCEMENT_FLAG_ARMED='.$this->bool($result['enforcement_flag_armed']));
        $this->line('ENFORCEMENT_SCOPE_MODE='.$result['enforcement_scope_mode']);
        $this->line('ENFORCEMENT_SCOPE_USABLE='.$this->bool($result['enforcement_scope_usable']));
        $this->line('GLOBAL_ENFORCEMENT_ACTIVE='.$this->bool($result['global_enforcement_active']));
        $this->line('DECLARED_PILOT_DOCTOR_USER_ID='.($result['declared_pilot_doctor_user_id'] ?? 'none'));
        $this->line('DECLARED_PILOT_BRANCH_CODE='.($result['declared_pilot_branch_code'] ?? 'none'));
        $this->line('DOCTOR_ROLE_ACCOUNT_COUNT='.$result['doctor_role_account_count']);
        $this->line('COVERED_DOCTOR_COUNT='.$result['covered_doctor_count']);
        $this->line('COVERED_DOCTOR_USER_IDS='.(
            $result['covered_doctor_user_ids'] === []
                ? 'none'
                : implode(',', $result['covered_doctor_user_ids'])
        ));
        $this->line('BROWSER_DENIED_DOCTOR_COUNT='.$result['browser_denied_doctor_count']);
        $this->line('BROWSER_ALLOWED_DOCTOR_COUNT='.$result['browser_allowed_doctor_count']);
        $this->line('SCOPE_VERDICT='.$result['verdict']);
    }

    private function bool(mixed $value): string
    {
        if ($value === null) {
            return 'unknown';
        }

        return $value ? 'true' : 'false';
    }
}
