<?php

namespace App\Modules\Inventory\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InventoryActivityLogFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['nullable', 'integer'],
            'action' => ['nullable'],
            'subject_type' => ['nullable', 'string', 'max:150'],
            'subject_id' => ['nullable', 'integer'],
            'correlation_id' => ['nullable', 'uuid'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $action = $this->input('action');

        if (is_string($action) && str_contains($action, ',')) {
            $this->merge([
                'action' => array_values(array_filter(array_map('trim', explode(',', $action)))),
            ]);
        }
    }

    public function withValidator($validator): void
    {
        $validator->sometimes('action', ['string', 'max:100'], fn () => is_string($this->input('action')));
        $validator->sometimes('action', ['array'], fn () => is_array($this->input('action')));
        $validator->sometimes('action.*', ['string', 'max:100'], fn () => is_array($this->input('action')));
    }

    /**
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        $validated = $this->validated();

        return collect([
            'user_id' => isset($validated['user_id']) ? (int) $validated['user_id'] : null,
            'action' => $validated['action'] ?? null,
            'subject_type' => $validated['subject_type'] ?? null,
            'subject_id' => isset($validated['subject_id']) ? (int) $validated['subject_id'] : null,
            'correlation_id' => $validated['correlation_id'] ?? null,
            'date_from' => $validated['date_from'] ?? null,
            'date_to' => $validated['date_to'] ?? null,
            'search' => $validated['search'] ?? null,
            'per_page' => isset($validated['per_page']) ? (int) $validated['per_page'] : null,
        ])->filter(fn ($value) => $value !== null && $value !== '')->all();
    }
}
