<?php

declare(strict_types=1);

namespace App\Modules\DoctorAccess\Repositories;

use App\Modules\DoctorAccess\Interfaces\DoctorFleetReadinessRepositoryInterface;
use App\Modules\LabOrder\Models\AuditLog;
use Illuminate\Support\Collection;

/**
 * DOCTOR-ACCESS-FLEET-ROLLOUT-READINESS-1 — see the interface for why the audit
 * trail is the source and the two scalar columns are not.
 */
class DoctorFleetReadinessRepository implements DoctorFleetReadinessRepositoryInterface
{
    /**
     * ONE query for the whole fleet.
     *
     * `action` and `performed_by` are both indexed (sys_audit_logs migration
     * :29, :31), so this is an index scan even once the trail is large. It is
     * deliberately not a per-doctor lookup: fifteen doctors would be fifteen
     * queries today and a national estate would be thousands, and the query
     * budget test pins that this stays constant as the fleet grows.
     *
     * @param  list<int>  $userIds
     * @return Collection<int, array{count:int,last_at:string|null,actions:list<string>,device_ids:list<int>}>
     */
    public function deviceLoginProofForUsers(array $userIds): Collection
    {
        if ($userIds === []) {
            return collect();
        }

        return AuditLog::query()
            ->select(['action', 'performed_by', 'performed_at', 'created_at', 'new_values'])
            ->whereIn('action', self::PROOF_ACTIONS)
            ->whereIn('performed_by', $userIds)
            ->orderBy('id')
            ->get()
            ->groupBy(fn (AuditLog $row): int => (int) $row->performed_by)
            ->map(fn (Collection $rows): array => $this->summarise($rows));
    }

    /**
     * @param  Collection<int, AuditLog>  $rows
     * @return array{count:int,last_at:string|null,actions:list<string>,device_ids:list<int>}
     */
    private function summarise(Collection $rows): array
    {
        $deviceIds = [];

        foreach ($rows as $row) {
            /*
             * new_values is an `array` cast over jsonb. A row whose payload is
             * null, or which predates the key, contributes NO device — it must
             * never contribute device 0, which would look like a real id in a
             * coverage tally.
             */
            $payload = $row->new_values;

            if (! is_array($payload)) {
                continue;
            }

            $deviceId = $payload['doctor_device_id'] ?? null;

            if (is_int($deviceId) || (is_string($deviceId) && ctype_digit($deviceId))) {
                $deviceIds[(int) $deviceId] = true;
            }
        }

        $deviceIds = array_keys($deviceIds);
        sort($deviceIds);

        $actions = $rows
            ->map(fn (AuditLog $row): string => (string) $row->action)
            ->unique()
            ->sort()
            ->values()
            ->all();

        return [
            'count' => $rows->count(),
            'last_at' => $this->latestTimestamp($rows),
            'actions' => $actions,
            'device_ids' => $deviceIds,
        ];
    }

    /**
     * `performed_at` is the business timestamp and `created_at` the row's own.
     * They are normally equal; when a writer passed no explicit time only the
     * latter exists. Preferring performed_at and falling back keeps a login
     * from reading as "never" because one nullable column was not set.
     *
     * @param  Collection<int, AuditLog>  $rows
     */
    private function latestTimestamp(Collection $rows): ?string
    {
        $latest = null;

        foreach ($rows as $row) {
            $at = $row->performed_at ?? $row->created_at;

            if ($at === null) {
                continue;
            }

            if ($latest === null || $at->greaterThan($latest)) {
                $latest = $at;
            }
        }

        return $latest?->toIso8601String();
    }
}
