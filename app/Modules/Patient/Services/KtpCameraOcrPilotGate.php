<?php

namespace App\Modules\Patient\Services;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Branch\Services\BranchService;
use App\Modules\Branch\Support\BranchCodeAlias;
use App\Modules\DoctorDevice\Interfaces\DoctorDeviceRepositoryInterface;
use App\Modules\DoctorDevice\Models\DoctorDevice;
use App\Modules\FrontOfficeDevice\Services\FrontOfficeBranchDeviceLockService;
use App\Modules\FrontOfficeDevice\Support\FrontOfficeDeviceLockDecision;
use App\Modules\Patient\Support\KtpCameraOcrPilotDecision;
use App\Modules\RmeOnlineContext\Services\RmeWorkingBranchScope;
use App\Services\Foundation\FeatureFlagService;
use App\Support\Clinical\ClinicalClock;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Throwable;

/**
 * PHASE-1-PATIENT-KTP-CAMERA-OCR-SUPERVISED-PILOT — the ONE place that decides
 * whether an operator may use KTP camera OCR.
 *
 * Two independent gates, both required:
 *
 *   1. GLOBAL capability flag `patient.ktp_camera_ocr`.
 *   2. SERVER-VERIFIED pilot eligibility (config/patient_ktp_ocr_pilot.php):
 *      the operator is in the explicit cohort, works at the single approved
 *      branch (resolved server-side by RmeWorkingBranchScope — never a request
 *      branch_id), today is inside the pilot period on the clinical calendar,
 *      and — unless the owner explicitly waived it — the session is bound to an
 *      approved clinic tablet.
 *
 * Every consumer (the parse endpoint and the Blade camera UI) calls this class;
 * none re-derives any part of it. There is no "everyone" mode: an empty or
 * invalid configuration covers nobody (fail closed).
 *
 * It writes nothing of its own, logs nothing and audits nothing (rule 177 §7).
 * One honest caveat: resolving the working branch goes through
 * UserOnlineContextService, which lazily marks an EXPIRED online context
 * inactive — the same expiry any request by that operator applies. That is the
 * canonical service's behaviour, not a decision made here, and it only ever
 * narrows (an expired context yields no branch, so the gate denies).
 * posture() never touches the online context and writes nothing at all.
 *
 * The device check deliberately has NO definition of its own: it requires the
 * front-office trusted-device lock to approve the request
 * (FrontOfficeBranchDeviceLockService::evaluate — enforcement on, account in
 * that cohort, device active + key-proven + enrolment verified, device at the
 * account's branch, all re-read every request), and then narrows further to
 * the pilot's own device allowlist and branch.
 */
class KtpCameraOcrPilotGate
{
    public const FLAG = 'patient.ktp_camera_ocr';

    public const FRONT_OFFICE_DEVICE_LOCK_FLAG = 'front_office.branch_device_lock';

    public function __construct(
        private readonly FeatureFlagService $flags,
        private readonly RmeWorkingBranchScope $workingBranch,
        private readonly BranchService $branches,
        private readonly DoctorDeviceRepositoryInterface $devices,
        private readonly ClinicalClock $clock,
        private readonly FrontOfficeBranchDeviceLockService $deviceLock,
    ) {}

    public function allows(?User $user, ?Request $request = null): bool
    {
        return $this->decide($user, $request)->allowed();
    }

    public function decide(?User $user, ?Request $request = null): KtpCameraOcrPilotDecision
    {
        if (! $this->flags->enabled(self::FLAG)) {
            return KtpCameraOcrPilotDecision::deny(KtpCameraOcrPilotDecision::FEATURE_DISABLED);
        }

        if ($user === null) {
            return KtpCameraOcrPilotDecision::deny(KtpCameraOcrPilotDecision::NOT_AUTHENTICATED);
        }

        $userId = (int) $user->getKey();
        $posture = $this->posture();

        if ($posture['errors'] !== []) {
            return KtpCameraOcrPilotDecision::deny(KtpCameraOcrPilotDecision::PILOT_NOT_CONFIGURED, $userId);
        }

        if (! in_array($userId, $posture['operator_user_ids'], true)) {
            return KtpCameraOcrPilotDecision::deny(KtpCameraOcrPilotDecision::OPERATOR_NOT_IN_PILOT, $userId);
        }

        // Trusted branch authority. Only a branch-context-bound operator has ONE
        // working branch; a governance role spans every RME branch, which is
        // ambiguous for a single-branch pilot and therefore refused.
        $branchId = $this->workingBranch->activeBranchId($user);

        if ($branchId === null) {
            return KtpCameraOcrPilotDecision::deny(KtpCameraOcrPilotDecision::NO_WORKING_BRANCH, $userId);
        }

        if ($branchId !== $posture['branch_id']) {
            return KtpCameraOcrPilotDecision::deny(KtpCameraOcrPilotDecision::BRANCH_NOT_IN_PILOT, $userId, $branchId);
        }

        $today = $this->clock->todayString();

        if ($today < $posture['starts_on'] || $today > $posture['ends_on']) {
            return KtpCameraOcrPilotDecision::deny(KtpCameraOcrPilotDecision::OUTSIDE_PILOT_PERIOD, $userId, $branchId);
        }

        $deviceId = null;

        if ($posture['require_bound_device']) {
            $lock = $request !== null ? $this->deviceLock->evaluate($user, $request) : null;

            // Out of the lock's scope (flag off, or the account is not in its
            // cohort) or no proof in the session: nothing is bound.
            if ($lock === null || ! $lock->inScope() || $lock->outcome === FrontOfficeDeviceLockDecision::DENY_UNKNOWN_DEVICE) {
                return KtpCameraOcrPilotDecision::deny(KtpCameraOcrPilotDecision::DEVICE_NOT_BOUND, $userId, $branchId);
            }

            $deviceId = $lock->deviceId;

            if ($lock->outcome !== FrontOfficeDeviceLockDecision::ALLOW
                || $deviceId === null
                || $lock->requiredBranchId !== $branchId
                || ! in_array($deviceId, $posture['device_ids'], true)
                || ! $this->deviceEligible($this->devices->findById($deviceId), $branchId)) {
                return KtpCameraOcrPilotDecision::deny(KtpCameraOcrPilotDecision::DEVICE_NOT_APPROVED, $userId, $branchId);
            }
        }

        return KtpCameraOcrPilotDecision::allow($userId, $branchId, $deviceId);
    }

    /**
     * The resolved pilot configuration plus every reason it is unusable.
     * Non-empty `errors` means the pilot covers NOBODY.
     *
     * @return array{
     *     flag_enabled: bool,
     *     front_office_device_lock_enabled: bool,
     *     operator_user_ids: list<int>,
     *     branch_id: ?int,
     *     branch_code: ?string,
     *     device_ids: list<int>,
     *     starts_on: ?string,
     *     ends_on: ?string,
     *     require_bound_device: bool,
     *     errors: list<string>
     * }
     */
    public function posture(): array
    {
        $errors = [];
        $config = (array) config('patient_ktp_ocr_pilot', []);

        $operators = $this->parseIds((string) ($config['operator_user_ids'] ?? ''), 'operator', $errors);
        if ($operators === []) {
            $errors[] = 'no_operators_configured';
        } elseif (count($operators) > (int) ($config['max_operators'] ?? 0)) {
            $errors[] = 'too_many_operators';
        }

        [$branchId, $branchCode] = $this->resolveBranch((string) ($config['branch_codes'] ?? ''), (int) ($config['max_branches'] ?? 0), $errors);

        [$startsOn, $endsOn] = $this->resolvePeriod($config['starts_on'] ?? null, $config['ends_on'] ?? null, (int) ($config['max_period_days'] ?? 0), $errors);

        $requireDevice = ($config['require_bound_device'] ?? true) !== false;
        $deviceIds = $this->parseIds((string) ($config['device_ids'] ?? ''), 'device', $errors);

        if (count($deviceIds) > (int) ($config['max_devices'] ?? 0)) {
            $errors[] = 'too_many_devices';
        }

        if ($requireDevice && $deviceIds === []) {
            $errors[] = 'device_required_but_none_approved';
        }

        foreach ($deviceIds as $id) {
            if ($branchId !== null && ! $this->deviceEligible($this->devices->findById($id), $branchId)) {
                $errors[] = 'device_not_eligible:'.$id;
            }
        }

        return [
            'flag_enabled' => $this->flags->enabled(self::FLAG),
            'front_office_device_lock_enabled' => $this->flags->enabled(self::FRONT_OFFICE_DEVICE_LOCK_FLAG),
            'operator_user_ids' => $operators,
            'branch_id' => $branchId,
            'branch_code' => $branchCode,
            'device_ids' => $deviceIds,
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'require_bound_device' => $requireDevice,
            'errors' => array_values(array_unique($errors)),
        ];
    }

    /**
     * An approved pilot tablet: active, cryptographically verified, and
     * registered at the pilot branch.
     */
    private function deviceEligible(?DoctorDevice $device, int $branchId): bool
    {
        // Same three model predicates the front-office lock composes, plus the
        // pilot branch. Never a weaker definition of "approved".
        return $device !== null
            && $device->isActive()
            && $device->isCryptographicallyVerified()
            && $device->isEnrollmentVerified()
            && (int) $device->branch_id === $branchId;
    }

    /**
     * @param  list<string>  $errors
     * @return list<int>
     */
    private function parseIds(string $raw, string $label, array &$errors): array
    {
        $ids = [];

        foreach (explode(',', $raw) as $token) {
            $token = trim($token);

            if ($token === '') {
                continue;
            }

            if (preg_match('/^[1-9][0-9]{0,9}$/D', $token) !== 1) {
                $errors[] = 'invalid_'.$label.'_id';

                continue;
            }

            $ids[] = (int) $token;
        }

        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    /**
     * @param  list<string>  $errors
     * @return array{0: ?int, 1: ?string}
     */
    private function resolveBranch(string $raw, int $max, array &$errors): array
    {
        $codes = [];

        foreach (explode(',', $raw) as $token) {
            $canonical = BranchCodeAlias::canonicalize(trim($token));

            if ($canonical !== null && $canonical !== '') {
                $codes[] = $canonical;
            }
        }

        $codes = array_values(array_unique($codes));

        if ($codes === []) {
            $errors[] = 'no_branch_configured';

            return [null, null];
        }

        if (count($codes) > $max) {
            $errors[] = 'too_many_branches';

            return [null, null];
        }

        $code = $codes[0];

        if ($code === Branch::MAIN_CODE) {
            $errors[] = 'branch_main_not_permitted';

            return [null, null];
        }

        // Active + RME-enabled only (MAIN is never RME-enabled).
        $branch = $this->branches->listRmeEnabled()
            ->first(fn ($b) => BranchCodeAlias::canonicalize((string) $b->code) === $code);

        if ($branch === null) {
            $errors[] = 'branch_not_active_rme';

            return [null, $code];
        }

        return [(int) $branch->id, $code];
    }

    /**
     * Strict Y-m-d, real calendar dates, inclusive, bounded length.
     *
     * @param  list<string>  $errors
     * @return array{0: ?string, 1: ?string}
     */
    private function resolvePeriod(mixed $starts, mixed $ends, int $maxDays, array &$errors): array
    {
        $start = $this->strictDate($starts);
        $end = $this->strictDate($ends);

        if ($start === null || $end === null) {
            $errors[] = 'pilot_period_invalid';

            return [null, null];
        }

        if ($end->lessThan($start)) {
            $errors[] = 'pilot_period_reversed';

            return [null, null];
        }

        if ($start->diffInDays($end) + 1 > $maxDays) {
            $errors[] = 'pilot_period_too_long';

            return [null, null];
        }

        return [$start->toDateString(), $end->toDateString()];
    }

    private function strictDate(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            return null;
        }

        $timezone = $this->clock->timezoneObject();

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, $timezone);
        } catch (Throwable) {
            return null;
        }

        // Round-trip rejects rolled-over dates (2026-02-31 -> 2026-03-03).
        return ($date !== false && $date->format('Y-m-d') === $value) ? $date : null;
    }
}
