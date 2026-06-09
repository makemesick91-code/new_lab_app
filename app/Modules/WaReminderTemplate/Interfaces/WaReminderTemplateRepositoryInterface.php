<?php

namespace App\Modules\WaReminderTemplate\Interfaces;

use App\Modules\WaReminderTemplate\Models\WaReminderTemplate;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface WaReminderTemplateRepositoryInterface
{
    /**
     * @param  array{search?: string|null, trigger_type?: string|null, audience_type?: string|null, is_active?: bool|null}  $filters
     */
    public function paginate(array $filters = [], int $perPage = 15): LengthAwarePaginator;

    public function listActive(): Collection;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): WaReminderTemplate;

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(WaReminderTemplate $template, array $data): WaReminderTemplate;

    public function delete(WaReminderTemplate $template): bool;
}
