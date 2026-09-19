<?php

declare(strict_types=1);

namespace App\Modules\DoctorDevice\Services;

use App\Modules\DoctorDevice\Interfaces\DoctorWebAuthnLiveProofRepositoryInterface;
use App\Modules\DoctorDevice\Models\DoctorDevice;
use App\Modules\DoctorDevice\Models\DoctorDeviceWebAuthnCredential;
use App\Modules\DoctorDevice\Support\WebAuthnDeviceBinding;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Throwable;

/**
 * FIX-DOCTOR-WEBAUTHN-READINESS-LIVE-PROOF-1 — does the browser leg actually
 * work, as opposed to merely being switched on?
 *
 * THE DEFECT THIS REMOVES
 *
 * `webauthn:readiness` reported VERDICT=ARMED from a feature flag and a
 * COUNT(*) over the credential table. Neither input can change when a ceremony
 * stops working, so between 2026-09-09 and 2026-09-19 it reported ARMED every
 * single day while the browser leg produced no successful assertion at all. The
 * Android path kept working throughout, which is why nobody noticed.
 *
 * Configuration is not liveness, and a row is not a proof. This service answers
 * the second question and leaves the first where it was.
 *
 * WHAT COUNTS, AND WHAT MUST NEVER COUNT
 *
 * A proof counts only when ALL of the following hold RIGHT NOW — the state at
 * the time of the assertion is irrelevant, because readiness is a statement
 * about the present:
 *
 *   - the device is ACTIVE                     (a revoked tablet proves nothing)
 *   - the credential is un-revoked             (a withdrawn key proves nothing)
 *   - its device-binding verdict is acceptable (a policy tightened since the
 *                                               assertion invalidates it)
 *   - the assertion was recorded AGAINST that credential and that device
 *   - the assertion was a WEBAUTHN assertion
 *
 * That last one is not a formality. Android Keystore login success is a real,
 * server-side, cryptographically verified proof — of a completely different
 * mechanism, sharing no code path with this one. Letting it satisfy a WebAuthn
 * liveness question is the precise false green this class exists to prevent,
 * and it is prevented structurally: the repository names ONE action.
 *
 * FAIL CLOSED, ALWAYS
 *
 * Every failure mode resolves to UNVERIFIED and never to PASS. A query that
 * throws, a timestamp that will not parse, a population that is empty — none of
 * those are evidence that anything works, and a readiness engine that reads
 * "I could not tell" as "fine" is the failure mode this whole programme keeps
 * finding.
 */
final class DoctorWebAuthnLiveProofService
{
    /** A qualifying assertion exists and is inside the freshness window. */
    public const PROOF_PASS = 'PASS';

    /** A qualifying assertion exists but nobody has exercised the leg lately. */
    public const PROOF_STALE = 'STALE';

    /** Nothing has ever proved this path with a currently-usable credential. */
    public const PROOF_NEVER_PROVEN = 'NEVER_PROVEN';

    /** We could not tell. Never a pass. */
    public const PROOF_UNVERIFIED = 'UNVERIFIED';

    public const READINESS_READY = 'READY';

    public const READINESS_NOT_READY = 'NOT_READY';

    public const READINESS_UNVERIFIED = 'UNVERIFIED';

    public const FRESHNESS_FRESH = 'FRESH';

    public const FRESHNESS_STALE = 'STALE';

    public const FRESHNESS_NEVER_PROVEN = 'NEVER_PROVEN';

    public const FRESHNESS_UNVERIFIED = 'UNVERIFIED';

    public function __construct(
        private readonly DoctorWebAuthnLiveProofRepositoryInterface $proofs,
    ) {}

    /**
     * The configured freshness window, or null when no opinion is configured.
     *
     * A null, zero or negative value is NOT "never expires". It means nobody
     * chose a policy, and freshness is then UNVERIFIED rather than silently
     * passing — being untold is not being reassured.
     */
    public function freshnessWindowDays(): ?int
    {
        $days = config('doctor_webauthn_live_proof.freshness_window_days');

        if (! is_numeric($days)) {
            return null;
        }

        $days = (int) $days;

        return $days > 0 ? $days : null;
    }

    /**
     * The whole estate, one device at a time, plus the rolled-up verdict.
     *
     * @return array{
     *     freshness_window_days:int|null,
     *     population:int,
     *     devices:list<array<string,mixed>>,
     *     live_assertion_proof:string,
     *     proof_freshness:string,
     *     proof_source:string,
     *     last_qualifying_proof_utc:string|null,
     *     last_qualifying_proof_local:string|null,
     *     scope_coverage:string,
     *     effective_readiness:string,
     *     unverified_reason:string|null
     * }
     */
    public function report(?CarbonImmutable $now = null): array
    {
        $now = ($now ?? CarbonImmutable::now())->utc();
        $window = $this->freshnessWindowDays();

        try {
            $devices = $this->activeDevicesWithCredentials();
        } catch (Throwable) {
            // A readiness engine that cannot read the estate has not learned
            // that the estate is fine.
            return $this->unverified($window, 'device_estate_unreadable');
        }

        /*
         * THE POPULATION, NAMED OUT LOUD.
         *
         * Active devices — the tablets that could serve a ceremony today. A
         * revoked device is excluded because it is not part of the estate any
         * more, not because it passes.
         *
         * An empty population is UNVERIFIED and never READY. Worst-of over an
         * empty set is vacuously true, and this codebase has already shipped a
         * gate that reported PASS over zero usable tablets exactly that way.
         */
        if ($devices->isEmpty()) {
            return $this->unverified($window, 'no_active_devices_to_measure');
        }

        $credentialIds = $devices
            ->flatMap(fn (DoctorDevice $device): array => $this
                ->usableCredentials($device)
                ->map(fn (DoctorDeviceWebAuthnCredential $c): int => (int) $c->id)
                ->all())
            ->unique()
            ->values()
            ->all();

        try {
            $proofs = $this->proofs->latestWebAuthnProofForCredentials($credentialIds);
        } catch (Throwable) {
            // §40 — a measurement query that failed is UNVERIFIED, never PASS.
            return $this->unverified($window, 'proof_query_failed');
        }

        $rows = $devices
            ->map(fn (DoctorDevice $device): array => $this->describeDevice($device, $proofs, $now, $window))
            ->values()
            ->all();

        return $this->rollUp($rows, $window, $now);
    }

    /**
     * Active devices only, each with its credentials eager-loaded.
     *
     * Eager-loaded so that adding a tablet does not add a query — the estate is
     * read once, the same discipline the sibling readiness engines were fixed
     * to follow after a duplicate read hid behind a constant query count.
     *
     * @return Collection<int, DoctorDevice>
     */
    private function activeDevicesWithCredentials(): Collection
    {
        return DoctorDevice::query()
            ->where('status', DoctorDevice::STATUS_ACTIVE)
            ->with('webAuthnCredentials')
            ->orderBy('id')
            ->get();
    }

    /**
     * Credentials on this device that could serve a ceremony RIGHT NOW.
     *
     * Two independent filters, and both matter:
     *
     *   revoked_at IS NULL   — revocation is terminal, and a revoked credential
     *                          keeps its historical assertions. Without this
     *                          filter a withdrawn key would keep the gate green
     *                          off the strength of logins it can no longer
     *                          perform. Production holds exactly that shape:
     *                          credential 1 on device 3 is revoked and carries
     *                          two success rows.
     *
     *   binding acceptable   — re-evaluated against TODAY's policy, not the one
     *                          in force when the credential was stored. A
     *                          credential admitted under a loose policy must
     *                          stop counting when the policy tightens, which is
     *                          the same rule the per-request revalidation path
     *                          already enforces for live sessions.
     *
     * The relation is unfiltered, so the filtering happens here rather than
     * being assumed.
     *
     * @return Collection<int, DoctorDeviceWebAuthnCredential>
     */
    private function usableCredentials(DoctorDevice $device): Collection
    {
        return $device->webAuthnCredentials
            ->filter(fn (DoctorDeviceWebAuthnCredential $c): bool => $c->revoked_at === null)
            ->filter(fn (DoctorDeviceWebAuthnCredential $c): bool => WebAuthnDeviceBinding::isAcceptable(
                (string) $c->device_bound_verdict,
            ))
            ->values();
    }

    /**
     * @param  Collection<int, array{last_at:string,count:int,device_ids:list<int>}>  $proofs
     * @return array<string,mixed>
     */
    private function describeDevice(
        DoctorDevice $device,
        Collection $proofs,
        CarbonImmutable $now,
        ?int $window,
    ): array {
        $deviceId = (int) $device->id;
        $usable = $this->usableCredentials($device);

        /*
         * ID, NEVER NAME.
         *
         * `DoctorDeviceWebAuthnReadinessCommand` carries a tested privacy
         * contract: its output must not contain a credential id, a public key,
         * a device NAME or a doctor's name, because the device estate is not
         * something a console report needs to enumerate. An earlier draft of
         * this engine reported `device_name` and
         * DoctorPwaWebAuthnTest::"reports the relying party and the estate
         * without leaking identities" caught it.
         *
         * The id is enough to act on: an operator resolves it through the
         * permission-gated device registry, which is where the estate is
         * allowed to be enumerated.
         */
        $row = [
            'device_id' => $deviceId,
            'device_status' => (string) $device->status,
            // A COUNT, never a credential id.
            'credentials_usable' => $usable->count(),
            'last_proof_utc' => null,
            'last_proof_local' => null,
            // Raw ISO-8601, carried so the roll-up compares instants rather
            // than re-parsing a string it formatted for a human.
            'last_proof_iso' => null,
            'proof_age_days' => null,
            'live_assertion_proof' => self::PROOF_NEVER_PROVEN,
            'reason' => null,
        ];

        if ($usable->isEmpty()) {
            // Whatever this device proved in the past, it proved with something
            // that can no longer sign. That is not liveness.
            $row['reason'] = 'no_usable_credential';

            return $row;
        }

        $latest = null;

        foreach ($usable as $credential) {
            $proof = $proofs->get((int) $credential->id);

            if ($proof === null) {
                continue;
            }

            /*
             * WRONG-DEVICE CORRELATION.
             *
             * The assertion recorded the device it was performed on. If that is
             * not this device, the proof belongs to somewhere else and must not
             * be borrowed. A credential is bound to one device, so this should
             * never fire — which is exactly why it is asserted rather than
             * assumed: the cheap check is the one that catches the migration
             * that quietly re-pointed a row.
             */
            if (! in_array($deviceId, $proof['device_ids'], true)) {
                continue;
            }

            try {
                $at = CarbonImmutable::parse($proof['last_at'])->utc();
            } catch (Throwable) {
                // A proof we cannot date cannot be aged, and an undateable
                // proof must not be treated as a fresh one.
                $row['live_assertion_proof'] = self::PROOF_UNVERIFIED;
                $row['reason'] = 'proof_timestamp_unparseable';

                return $row;
            }

            if ($latest === null || $at->greaterThan($latest)) {
                $latest = $at;
            }
        }

        if ($latest === null) {
            $row['reason'] = 'no_qualifying_assertion';

            return $row;
        }

        $row['last_proof_utc'] = $latest->toDateTimeString().' UTC';
        $row['last_proof_local'] = $this->local($latest);
        $row['last_proof_iso'] = $latest->toIso8601String();
        // Fractional, so a proof that is 9.4 days old does not read as 9 and
        // sit one rounding away from the window it has already left.
        $row['proof_age_days'] = round($latest->diffInRealSeconds($now) / 86400, 2);

        if ($window === null) {
            // No policy was configured, so no freshness claim can be made.
            $row['live_assertion_proof'] = self::PROOF_UNVERIFIED;
            $row['reason'] = 'no_freshness_policy_configured';

            return $row;
        }

        $fresh = $latest->greaterThanOrEqualTo($now->subDays($window));

        $row['live_assertion_proof'] = $fresh ? self::PROOF_PASS : self::PROOF_STALE;

        if (! $fresh) {
            $row['reason'] = 'proof_older_than_freshness_window';
        }

        return $row;
    }

    /**
     * Worst-of across the named population.
     *
     * The order is deliberate and is the order of honesty: an UNVERIFIED
     * anywhere outranks everything, because a report containing something we
     * could not measure is not a report that anything is ready.
     *
     * @param  list<array<string,mixed>>  $rows
     * @return array<string,mixed>
     */
    private function rollUp(array $rows, ?int $window, CarbonImmutable $now): array
    {
        $statuses = array_map(static fn (array $r): string => (string) $r['live_assertion_proof'], $rows);

        $proof = match (true) {
            in_array(self::PROOF_UNVERIFIED, $statuses, true) => self::PROOF_UNVERIFIED,
            in_array(self::PROOF_NEVER_PROVEN, $statuses, true) => self::PROOF_NEVER_PROVEN,
            in_array(self::PROOF_STALE, $statuses, true) => self::PROOF_STALE,
            default => self::PROOF_PASS,
        };

        $freshness = match ($proof) {
            self::PROOF_PASS => self::FRESHNESS_FRESH,
            self::PROOF_STALE => self::FRESHNESS_STALE,
            self::PROOF_NEVER_PROVEN => self::FRESHNESS_NEVER_PROVEN,
            default => self::FRESHNESS_UNVERIFIED,
        };

        $readiness = match ($proof) {
            self::PROOF_PASS => self::READINESS_READY,
            self::PROOF_UNVERIFIED => self::READINESS_UNVERIFIED,
            default => self::READINESS_NOT_READY,
        };

        $passing = count(array_filter($statuses, static fn (string $s): bool => $s === self::PROOF_PASS));

        $dated = array_filter(array_map(
            static fn (array $r): ?string => $r['last_proof_iso'],
            $rows,
        ));

        // The most recent qualifying proof anywhere in the estate. Reported for
        // orientation only — it is NOT the verdict, because one fresh tablet
        // says nothing about the other three.
        $latest = null;

        foreach ($rows as $row) {
            if ($row['last_proof_iso'] === null) {
                continue;
            }

            $at = CarbonImmutable::parse((string) $row['last_proof_iso'])->utc();

            if ($latest === null || $at->greaterThan($latest)) {
                $latest = $at;
            }
        }

        return [
            'freshness_window_days' => $window,
            'population' => count($rows),
            'devices' => $rows,
            'live_assertion_proof' => $proof,
            'proof_freshness' => $freshness,
            'proof_source' => 'sys_audit_logs:'.DoctorWebAuthnLiveProofRepositoryInterface::WEBAUTHN_PROOF_ACTION,
            'last_qualifying_proof_utc' => $latest === null ? null : $latest->toDateTimeString().' UTC',
            'last_qualifying_proof_local' => $latest === null ? null : $this->local($latest),
            'scope_coverage' => $passing.'/'.count($rows).' active devices with a fresh WebAuthn assertion',
            'effective_readiness' => $readiness,
            'unverified_reason' => null,
            'dated_devices' => count($dated),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function unverified(?int $window, string $reason): array
    {
        return [
            'freshness_window_days' => $window,
            'population' => 0,
            'devices' => [],
            'live_assertion_proof' => self::PROOF_UNVERIFIED,
            'proof_freshness' => self::FRESHNESS_UNVERIFIED,
            'proof_source' => 'sys_audit_logs:'.DoctorWebAuthnLiveProofRepositoryInterface::WEBAUTHN_PROOF_ACTION,
            'last_qualifying_proof_utc' => null,
            'last_qualifying_proof_local' => null,
            'scope_coverage' => '0/0 active devices with a fresh WebAuthn assertion',
            'effective_readiness' => self::READINESS_UNVERIFIED,
            'unverified_reason' => $reason,
            'dated_devices' => 0,
        ];
    }

    /**
     * Clinic-local, ALWAYS with its label attached.
     *
     * Internal authority is UTC and every comparison above was made in UTC.
     * This exists so an operator at a branch does not do the arithmetic, and it
     * is never emitted bare — an unlabelled timestamp that could be read as
     * local time is a reporting defect in this programme, not a style choice.
     */
    private function local(CarbonImmutable $at): string
    {
        $tz = (string) config('doctor_webauthn_live_proof.display_timezone', 'Asia/Makassar');
        $label = (string) config('doctor_webauthn_live_proof.display_timezone_label', 'WITA (UTC+8)');

        return $at->setTimezone($tz)->toDateTimeString().' '.$label;
    }
}
