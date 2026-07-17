<?php

namespace App\Modules\Satusehat\Services\Pilot;

use App\Models\User;
use App\Modules\Satusehat\Models\SatusehatAuditLog;
use App\Modules\Satusehat\Models\SatusehatIncidentDrillRun;
use App\Modules\Satusehat\Services\SatusehatAuditLogger;
use Illuminate\Validation\ValidationException;

/**
 * SATUSEHAT-4D — hermetic incident-drill recorder + safety-invariant checks.
 *
 * Read/observe only. Never touches production, sends external traffic, or
 * mutates readiness. Automatable safety-invariant drills verify the kill
 * switches stay off (SATUSEHAT disabled, external send disabled, production
 * blocked); documented operational drills (IDOR, wave enrollment, nginx,
 * co-tenant, rollback…) are recorded with their observed outcome.
 */
class SatusehatIncidentDrillService
{
    public function __construct(
        private readonly SatusehatAuditLogger $audit,
    ) {}

    private function env(): string
    {
        return (string) config('satusehat.environment');
    }

    /**
     * Run the automatable safety-invariant drills. All must PASS — a failure
     * means a kill switch is off and is surfaced (never silently green).
     *
     * @return array{overall:string, drills:list<array<string,mixed>>}
     */
    public function runSafetyInvariants(?User $actor = null): array
    {
        $checks = [
            [
                'code' => 'external_send_flag_tampering',
                'title' => 'Kill switch: SATUSEHAT external send disabled',
                'trigger' => 'send_enabled flag flipped on',
                'expected' => 'send disabled',
                'pass' => config('satusehat.send_enabled', false) === false,
            ],
            [
                'code' => 'production_flag_tampering',
                'title' => 'Kill switch: production blocked',
                'trigger' => 'production_enabled/approved flag flipped on',
                'expected' => 'production blocked',
                'pass' => config('satusehat.production_enabled', false) === false
                    && config('satusehat.production_approved', false) === false,
            ],
            [
                'code' => 'integration_disabled',
                'title' => 'Kill switch: SATUSEHAT integration disabled',
                'trigger' => 'enabled flag flipped on',
                'expected' => 'integration disabled',
                'pass' => config('satusehat.enabled', false) === false,
            ],
        ];

        $drills = [];
        $allPass = true;
        foreach ($checks as $c) {
            $outcome = $c['pass'] ? SatusehatIncidentDrillRun::OUTCOME_PASS : SatusehatIncidentDrillRun::OUTCOME_FAIL;
            $allPass = $allPass && $c['pass'];
            $drills[] = $this->record($c['code'], $c['title'], $c['trigger'], $c['expected'],
                $c['pass'] ? 'safe' : 'UNSAFE', $outcome, $actor)->only([
                    'drill_code', 'outcome', 'expected_state', 'actual_state',
                ]);
        }

        return ['overall' => $allPass ? 'PASS' : 'FAIL', 'drills' => $drills];
    }

    /** Record a documented drill run (hermetic; scalar/PII-free). */
    public function record(
        string $code,
        string $title,
        string $trigger,
        string $expectedState,
        ?string $actualState,
        string $outcome,
        ?User $actor = null,
        array $extra = [],
    ): SatusehatIncidentDrillRun {
        if (! in_array($code, (array) config('satusehat_pilot.incident_drills', []), true)) {
            throw ValidationException::withMessages(['drill_code' => 'Kode incident drill tidak dikenal.']);
        }
        if (! in_array($outcome, [
            SatusehatIncidentDrillRun::OUTCOME_PASS,
            SatusehatIncidentDrillRun::OUTCOME_FAIL,
            SatusehatIncidentDrillRun::OUTCOME_PENDING,
        ], true)) {
            throw ValidationException::withMessages(['outcome' => 'Hasil drill tidak valid.']);
        }

        $run = SatusehatIncidentDrillRun::create([
            'environment' => $this->env(),
            'drill_code' => $code,
            'title' => mb_substr($title, 0, 200),
            'trigger' => mb_substr($trigger, 0, 500),
            'expected_state' => mb_substr($expectedState, 0, 300),
            'actual_state' => $actualState !== null ? mb_substr($actualState, 0, 300) : null,
            'outcome' => $outcome,
            'diagnostic_command' => isset($extra['diagnostic_command']) ? mb_substr((string) $extra['diagnostic_command'], 0, 300) : null,
            'escalation_owner' => isset($extra['escalation_owner']) ? mb_substr((string) $extra['escalation_owner'], 0, 120) : null,
            'rollback' => isset($extra['rollback']) ? mb_substr((string) $extra['rollback'], 0, 500) : null,
            'evidence_reference' => isset($extra['evidence_reference']) ? mb_substr((string) $extra['evidence_reference'], 0, 500) : null,
            'executed_by' => $actor?->id,
            'executed_at' => now(),
            'created_at' => now(),
        ]);

        $this->audit->log(
            'satusehat_incident_drill',
            (int) $run->id,
            SatusehatAuditLog::EVENT_INCIDENT_DRILL_RUN,
            'Incident drill dijalankan: '.$code,
            ['drill_code' => $code, 'outcome' => $outcome],
            null,
            $actor,
        );

        return $run;
    }
}
