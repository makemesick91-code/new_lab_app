<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\LabOrder\Services\ExternalLabProvisioningService;
use Illuminate\Console\Command;

/**
 * LAB-OPS-READINESS-1 — governed external lab (vendor) upsert.
 *
 * Creates or updates a `mst_external_labs` master, idempotent by unique --name.
 * Dry-run by default; --apply required to persist. Only allowlisted fields are
 * accepted (no arbitrary JSON, no raw SQL). Transactional + row-locked +
 * audit-logged; NEVER deletes. Data must be real/owner-approved — nothing is
 * fabricated. Fills the edit/deactivate gap left by the index+store-only UI.
 */
final class LabExternalLabUpsertCommand extends Command
{
    protected $signature = 'lab:external-lab-upsert
        {--name= : Vendor name (required, unique key)}
        {--phone= : Contact phone (optional)}
        {--email= : Contact email (optional)}
        {--address= : Address (optional)}
        {--notes= : Operational notes / capabilities (optional)}
        {--active=true : Active state (true|false), default true}
        {--dry-run : Preview only (default)}
        {--apply : Persist the upsert}
        {--json}';

    protected $description = 'Create/update an external lab vendor (dry-run by default; idempotent by name, no delete).';

    public function handle(ExternalLabProvisioningService $service): int
    {
        $name = (string) ($this->option('name') ?? '');
        if (trim($name) === '') {
            $this->error('--name="<vendor>" is required.');

            return self::INVALID;
        }

        $apply = (bool) $this->option('apply');
        if ($apply && $this->option('dry-run')) {
            $this->error('Pass either --dry-run or --apply, not both.');

            return self::INVALID;
        }

        $active = filter_var($this->option('active'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($active === null) {
            $this->error('--active must be true or false.');

            return self::INVALID;
        }

        // Only pass fields the operator actually provided (null = "leave as-is").
        $attributes = ['name' => trim($name), 'is_active' => $active];
        foreach (['phone', 'email', 'address', 'notes'] as $field) {
            $value = $this->option($field);
            if ($value !== null && $value !== '') {
                $attributes[$field] = (string) $value;
            }
        }

        try {
            $result = $service->upsert($attributes, $apply);
        } catch (\RuntimeException $e) {
            if ($this->option('json')) {
                $this->line((string) json_encode(['error' => $e->getMessage()], JSON_PRETTY_PRINT));
            } else {
                $this->error($e->getMessage());
            }

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($result['idempotent_no_op']) {
            $this->info('No change — vendor already matches (idempotent).');
        } elseif ($result['created']) {
            $this->info('APPLIED — external lab vendor created (active='.($result['after']['is_active'] ? 'true' : 'false').').');
        } elseif ($result['applied']) {
            $this->info('APPLIED — external lab vendor updated.');
        } else {
            $this->comment('DRY-RUN — no change written. Re-run with --apply to persist.');
        }

        $after = $result['after'];
        $this->table(
            ['field', 'value'],
            [
                ['id', $after['id'] ?? '(new)'],
                ['name', $after['name']],
                ['phone', $after['phone'] ?? '—'],
                ['email', $after['email'] ?? '—'],
                ['address', $after['address'] ?? '—'],
                ['notes', $after['notes'] ?? '—'],
                ['is_active', $after['is_active'] ? 'yes' : 'no'],
            ],
        );

        return self::SUCCESS;
    }
}
