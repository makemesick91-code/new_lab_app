<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Modules\Patient\Services\KtpCameraOcrPilotGate;
use App\Modules\Patient\Support\KtpOcrConsent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * PHASE-1-PATIENT-KTP-CAMERA-OCR-SUPERVISED-PILOT — read-only pilot posture.
 *
 * Reports whether KTP camera OCR is switched on, who the pilot covers and why
 * a configuration covers nobody, so the armed state on a host is MEASURED
 * rather than inferred. It has no --arm/--apply: arming is a supervised owner
 * ceremony (environment + config cache), never a command side effect.
 *
 * --user evaluates one operator. Resolving a working branch can lazily expire a
 * stale online-context row, so that probe runs inside a transaction that is
 * ALWAYS rolled back — the command writes nothing.
 *
 * PHASE-3-PATIENT-KTP-ROI-OCR-CLINICAL-PILOT-1 — also reports which consent
 * wording (decision D7) is deployed: its version and whether it is usable, never
 * the text itself. With the flag on, unusable wording means OCR can run for
 * nobody, so --strict treats it as an unusable configuration.
 *
 * Exit codes: 0 = consistent; 2 = --strict and the configuration is unusable
 * while the flag is on, or contains errors.
 */
class PatientKtpOcrPilotStatusCommand extends Command
{
    protected $signature = 'patient:ktp-ocr-pilot-status
                            {--user= : users.id to evaluate against the gate (no session, so a required device reads as not bound)}
                            {--json : Output as JSON}
                            {--strict : Exit 2 when the pilot configuration is unusable or contains errors}';

    protected $description = 'Read-only posture of the supervised KTP camera OCR pilot (flag + server-side pilot scope)';

    public function handle(KtpCameraOcrPilotGate $gate): int
    {
        $posture = $gate->posture();
        $verdict = $this->verdict($posture);

        $report = [
            'verdict' => $verdict,
            'posture' => $posture,
            'everyone_mode_exists' => false,
            'consent' => KtpOcrConsent::summary(),
            'user_decision' => $this->option('user') !== null ? $this->evaluate($gate, (string) $this->option('user')) : null,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line('KTP_OCR_PILOT_VERDICT='.$verdict);
            $this->line('FLAG_ENABLED='.($posture['flag_enabled'] ? 'true' : 'false'));
            $this->line('OPERATORS=['.implode(',', $posture['operator_user_ids']).']');
            $this->line('BRANCH='.($posture['branch_code'] ?? '-').' (id '.($posture['branch_id'] ?? '-').')');
            $this->line('PERIOD='.($posture['starts_on'] ?? '-').'..'.($posture['ends_on'] ?? '-').' (clinical calendar, inclusive)');
            $this->line('REQUIRE_BOUND_DEVICE='.($posture['require_bound_device'] ? 'true' : 'false'));
            $this->line('DEVICES=['.implode(',', $posture['device_ids']).']');
            $this->line('FRONT_OFFICE_DEVICE_LOCK_ENABLED='.($posture['front_office_device_lock_enabled'] ? 'true' : 'false'));
            $this->line('ERRORS=['.implode(',', $posture['errors']).']');
            $this->line('CONSENT_VERSION='.($report['consent']['version'] !== '' ? $report['consent']['version'] : 'NONE'));
            $this->line('CONSENT_USABLE='.($report['consent']['usable'] ? 'true' : 'false'));
            if ($report['user_decision'] !== null) {
                $this->line('USER_DECISION='.$report['user_decision']['reason']);
            }
            $this->line('Read-only. This command never arms the pilot.');
        }

        $unusable = $posture['errors'] !== []
            || $verdict === 'ARMED_UNREACHABLE'
            || ($posture['flag_enabled'] && ! $report['consent']['usable']);

        return ($this->option('strict') && $unusable && $verdict !== 'INERT') ? 2 : self::SUCCESS;
    }

    /**
     * INERT              flag off and nothing configured (the safe resting state)
     * INERT_CONFIGURED   flag off, a cohort is staged (and may contain errors)
     * ARMED              flag on, configuration valid
     * ARMED_UNREACHABLE  flag on, valid, but a bound device is required while the
     *                    front-office device lock that binds one is off
     * MISCONFIGURED      flag on, configuration invalid: covers NOBODY
     *
     * @param  array<string, mixed>  $posture
     */
    private function verdict(array $posture): string
    {
        $staged = $posture['operator_user_ids'] !== [] || $posture['branch_code'] !== null;

        if (! $posture['flag_enabled']) {
            return $staged ? 'INERT_CONFIGURED' : 'INERT';
        }

        if ($posture['errors'] !== []) {
            return 'MISCONFIGURED';
        }

        if ($posture['require_bound_device'] && ! $posture['front_office_device_lock_enabled']) {
            return 'ARMED_UNREACHABLE';
        }

        return 'ARMED';
    }

    /**
     * @return array<string, mixed>
     */
    private function evaluate(KtpCameraOcrPilotGate $gate, string $userId): array
    {
        if (! ctype_digit($userId)) {
            return ['allowed' => false, 'reason' => 'invalid_user_option'];
        }

        $user = User::query()->find((int) $userId);

        DB::beginTransaction();

        try {
            return $gate->decide($user)->toArray();
        } finally {
            DB::rollBack();
        }
    }
}
