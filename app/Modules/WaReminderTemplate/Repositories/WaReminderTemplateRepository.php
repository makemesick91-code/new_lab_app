<?php

namespace App\Modules\WaReminderTemplate\Repositories;

use App\Modules\WaReminderTemplate\Interfaces\WaReminderTemplateRepositoryInterface;
use App\Modules\WaReminderTemplate\Models\WaReminderTemplate;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class WaReminderTemplateRepository implements WaReminderTemplateRepositoryInterface
{
    public function paginate(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $search = $filters['search'] ?? null;

        return WaReminderTemplate::query()
            ->when($search, function ($query, $search) {
                $term = '%'.mb_strtolower($search).'%';
                $query->where(function ($q) use ($term) {
                    $q->whereRaw('LOWER(code) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(name) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(message_body) LIKE ?', [$term]);
                });
            })
            ->when(! empty($filters['trigger_type']),
                fn ($q) => $q->where('trigger_type', $filters['trigger_type']))
            ->when(! empty($filters['audience_type']),
                fn ($q) => $q->where('audience_type', $filters['audience_type']))
            ->when(array_key_exists('is_active', $filters) && $filters['is_active'] !== null,
                fn ($q) => $q->where('is_active', $filters['is_active']))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function listActive(): Collection
    {
        return WaReminderTemplate::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    public function create(array $data): WaReminderTemplate
    {
        return WaReminderTemplate::create($data);
    }

    public function update(WaReminderTemplate $template, array $data): WaReminderTemplate
    {
        $template->update($data);

        return $template->refresh();
    }

    public function delete(WaReminderTemplate $template): bool
    {
        return (bool) $template->delete();
    }
}
