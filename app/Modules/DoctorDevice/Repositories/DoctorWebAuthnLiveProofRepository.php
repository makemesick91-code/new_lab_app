<?php

declare(strict_types=1);

namespace App\Modules\DoctorDevice\Repositories;

use App\Modules\DoctorDevice\Interfaces\DoctorWebAuthnLiveProofRepositoryInterface;
use App\Modules\LabOrder\Models\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * FIX-DOCTOR-WEBAUTHN-READINESS-LIVE-PROOF-1 — see the interface for why the
 * audit trail is the source and `last_used_at` is not.
 */
class DoctorWebAuthnLiveProofRepository implements DoctorWebAuthnLiveProofRepositoryInterface
{
    /**
     * ONE query for the whole estate.
     *
     * `action` and `entity_id` are both indexed (sys_audit_logs migration), so
     * this stays an index scan as the trail grows. It is deliberately not a
     * per-credential lookup: a national estate would be thousands of queries,
     * and a query-budget test pins that this stays constant as the estate
     * grows.
     *
     * @param  list<int>  $credentialIds
     * @return Collection<int, array{count:int,last_at_by_device:array<int,string>}>
     */
    public function latestWebAuthnProofForCredentials(array $credentialIds): Collection
    {
        if ($credentialIds === []) {
            return collect();
        }

        return AuditLog::query()
            ->select(['entity_id', 'performed_at', 'created_at', 'new_values'])
            ->where('action', self::WEBAUTHN_PROOF_ACTION)
            // The credential row is the audit entity, so this is also the
            // correlation: a proof belongs to the credential that signed it and
            // to no other.
            ->where('entity_type', 'trx_doctor_device_webauthn_credentials')
            ->whereIn('entity_id', $credentialIds)
            ->orderBy('id')
            ->get()
            ->groupBy(fn (AuditLog $row): int => (int) $row->entity_id)
            ->map(fn (Collection $rows): array => $this->summarise($rows))
            // A credential none of whose rows yielded a usable (device,
            // timestamp) pair must be ABSENT, not present with an empty map —
            // the caller distinguishes "never proven" from "proven at an
            // unreadable time" and cannot do that if both arrive the same way.
            ->reject(fn (array $summary): bool => $summary['last_at_by_device'] === []);
    }

    /**
     * @param  Collection<int, AuditLog>  $rows
     * @return array{count:int,last_at_by_device:array<int,string>}
     */
    private function summarise(Collection $rows): array
    {
        /** @var array<int, CarbonImmutable> $latestByDevice */
        $latestByDevice = [];

        foreach ($rows as $row) {
            /*
             * new_values is an `array` cast over jsonb. Decoded in PHP rather
             * than extracted in SQL on purpose: `->>` and json_extract are
             * spelled differently on PostgreSQL and SQLite, and the production
             * gate runs on one while the local suite runs on the other.
             */
            $payload = $row->new_values;

            if (! is_array($payload) || ! isset($payload['doctor_device_id'])) {
                // A row that does not say which device it happened on cannot
                // prove any device. It is dropped rather than folded into a
                // neighbour's timestamp — that fold was the defect.
                continue;
            }

            $deviceId = (int) $payload['doctor_device_id'];

            /*
             * `performed_at` is the business timestamp and `created_at` the
             * row's own. Preferring performed_at and falling back keeps a login
             * readable whichever the writer populated.
             */
            $at = $row->performed_at ?? $row->created_at;

            if ($at === null) {
                continue;
            }

            $candidate = $at->toImmutable()->utc();

            if (! isset($latestByDevice[$deviceId]) || $candidate->greaterThan($latestByDevice[$deviceId])) {
                $latestByDevice[$deviceId] = $candidate;
            }
        }

        ksort($latestByDevice);

        return [
            'count' => $rows->count(),
            'last_at_by_device' => array_map(
                static fn ($at): string => $at->toIso8601String(),
                $latestByDevice,
            ),
        ];
    }
}
