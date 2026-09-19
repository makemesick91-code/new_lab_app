<?php

declare(strict_types=1);

namespace App\Modules\DoctorDevice\Repositories;

use App\Modules\DoctorDevice\Interfaces\DoctorWebAuthnLiveProofRepositoryInterface;
use App\Modules\LabOrder\Models\AuditLog;
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
     * @return Collection<int, array{last_at:string,count:int,device_ids:list<int>}>
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
            // A credential whose rows carried no readable timestamp must be
            // ABSENT, not present with a null — the caller distinguishes
            // "never proven" from "proven at an unreadable time" and cannot do
            // that if both arrive as a null.
            ->reject(fn (array $summary): bool => $summary['last_at'] === '');
    }

    /**
     * @param  Collection<int, AuditLog>  $rows
     * @return array{last_at:string,count:int,device_ids:list<int>}
     */
    private function summarise(Collection $rows): array
    {
        $deviceIds = [];
        $latest = null;

        foreach ($rows as $row) {
            /*
             * new_values is an `array` cast over jsonb. Decoded in PHP rather
             * than extracted in SQL on purpose: `->>` and json_extract are
             * spelled differently on PostgreSQL and SQLite, and the production
             * gate runs on one while the local suite runs on the other. A row
             * whose payload is missing or malformed contributes no device id
             * rather than crashing the report.
             */
            $payload = $row->new_values;

            if (is_array($payload) && isset($payload['doctor_device_id'])) {
                $deviceIds[] = (int) $payload['doctor_device_id'];
            }

            /*
             * `performed_at` is the business timestamp and `created_at` the
             * row's own. Preferring performed_at and falling back keeps a login
             * readable whichever the writer populated.
             */
            $at = $row->performed_at ?? $row->created_at;

            if ($at === null) {
                continue;
            }

            $candidate = $at->utc();

            if ($latest === null || $candidate->greaterThan($latest)) {
                $latest = $candidate;
            }
        }

        $deviceIds = array_values(array_unique($deviceIds));
        sort($deviceIds);

        return [
            'last_at' => $latest?->toIso8601String() ?? '',
            'count' => $rows->count(),
            'device_ids' => $deviceIds,
        ];
    }
}
