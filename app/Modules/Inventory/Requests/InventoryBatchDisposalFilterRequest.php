<?php

namespace App\Modules\Inventory\Requests;

use App\Modules\Inventory\Models\InventoryBatchDisposalRequest;
use Illuminate\Foundation\Http\FormRequest;

class InventoryBatchDisposalFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', InventoryBatchDisposalRequest::class) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['nullable', 'string'],
            'request_type' => ['nullable', 'string'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return $this->only(['status', 'request_type', 'search']);
    }

    public function perPage(): int
    {
        return (int) ($this->input('per_page') ?: 15);
    }
}
