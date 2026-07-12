<?php

declare(strict_types=1);

namespace App\Modules\LabOrder\Services;

use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\LabOrder\Models\ExternalLab;
use Illuminate\Support\Facades\DB;

/**
 * LAB-OPS-READINESS-1 — governed external lab (vendor) provisioning.
 *
 * {@see upsert()} creates or updates a `mst_external_labs` master, idempotent by
 * its unique `name`, from an explicit allowlist of fields only (name, phone,
 * email, address, notes, is_active). Dry-run unless $apply. Transactional,
 * row-locked, fail-closed, audit-logged. NEVER deletes; a soft-deleted vendor of
 * the same name is restored rather than duplicated. Fills the create/edit/
 * deactivate gap the index+store-only UI cannot cover.
 *
 * Data must be real/owner-approved — this service persists exactly the values it
 * is given and fabricates nothing.
 */
final class ExternalLabProvisioningService
{
    /** Fields an operator may set — arbitrary keys are ignored (no mass assignment). */
    private const ALLOWED = ['name', 'phone', 'email', 'address', 'notes', 'is_active'];

    public function __construct(private readonly AuditLogService $auditLogs) {}

    /**
     * @param  array<string,mixed>  $attributes
     * @return array<string,mixed> before/after snapshot
     *
     * @throws \RuntimeException on missing name
     */
    public function upsert(array $attributes, bool $apply): array
    {
        $name = trim((string) ($attributes['name'] ?? ''));
        if ($name === '') {
            throw new \RuntimeException('An external lab --name is required.');
        }

        // Explicit allowlist — silently drop anything else.
        $payload = [];
        foreach (self::ALLOWED as $field) {
            if (array_key_exists($field, $attributes) && $attributes[$field] !== null) {
                $payload[$field] = $attributes[$field];
            }
        }
        $payload['name'] = $name;
        $payload['is_active'] = array_key_exists('is_active', $attributes)
            ? (bool) $attributes['is_active']
            : true;

        return DB::transaction(function () use ($name, $payload, $apply): array {
            // Match by unique name, including soft-deleted so we restore instead of
            // hitting the unique constraint or leaving a hidden duplicate.
            $existing = ExternalLab::withTrashed()
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                ->lockForUpdate()
                ->first();

            $isCreate = $existing === null;

            $before = $existing === null ? null : [
                'id' => $existing->id,
                'name' => $existing->name,
                'phone' => $existing->phone,
                'email' => $existing->email,
                'address' => $existing->address,
                'notes' => $existing->notes,
                'is_active' => (bool) $existing->is_active,
                'trashed' => $existing->trashed(),
            ];

            $model = $existing ?? new ExternalLab;
            $model->fill($payload);

            $projectedAfter = [
                'name' => $model->name,
                'phone' => $model->phone,
                'email' => $model->email,
                'address' => $model->address,
                'notes' => $model->notes,
                'is_active' => (bool) $model->is_active,
            ];

            $noChange = ! $isCreate
                && ! $existing->trashed()
                && ! $model->isDirty();

            if ($apply && ! $noChange) {
                if ($existing !== null && $existing->trashed()) {
                    $model->restore(); // NEVER a delete — undo a prior soft-delete.
                }
                $model->save();
                $this->auditLogs->log(
                    'mst_external_labs',
                    $model->id,
                    $isCreate ? AuditLog::ACTION_CREATE : AuditLog::ACTION_UPDATE,
                    $before,
                    array_merge(['id' => $model->id], $projectedAfter),
                );
                $model = $model->fresh();
            }

            return [
                'applied' => $apply && ! $noChange,
                'created' => $apply && $isCreate,
                'idempotent_no_op' => $noChange,
                'before' => $before,
                'after' => [
                    'id' => $model->id,
                    'name' => $model->name,
                    'phone' => $model->phone,
                    'email' => $model->email,
                    'address' => $model->address,
                    'notes' => $model->notes,
                    'is_active' => (bool) $model->is_active,
                ],
            ];
        });
    }
}
