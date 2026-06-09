<?php

namespace App\Modules\WaReminderTemplate\Services;

use App\Modules\WaReminderTemplate\Interfaces\WaReminderTemplateRepositoryInterface;
use App\Modules\WaReminderTemplate\Models\WaReminderTemplate;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class WaReminderTemplateService
{
    public function __construct(
        private readonly WaReminderTemplateRepositoryInterface $templates,
    ) {}

    public function paginate(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->templates->paginate($filters, $perPage);
    }

    public function listActive(): Collection
    {
        return $this->templates->listActive();
    }

    public function create(array $data): WaReminderTemplate
    {
        return DB::transaction(fn () => $this->templates->create($data));
    }

    public function update(WaReminderTemplate $template, array $data): WaReminderTemplate
    {
        return DB::transaction(fn () => $this->templates->update($template, $data));
    }

    public function delete(WaReminderTemplate $template): bool
    {
        return DB::transaction(fn () => $this->templates->delete($template));
    }
}
