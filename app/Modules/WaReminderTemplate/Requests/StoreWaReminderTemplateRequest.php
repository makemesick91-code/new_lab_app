<?php

namespace App\Modules\WaReminderTemplate\Requests;

use App\Modules\WaReminderTemplate\Models\WaReminderTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWaReminderTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $raw = $this->input('available_variables_raw', '');
        $variables = array_values(array_filter(
            array_map('trim', explode("\n", (string) $raw)),
            fn ($v) => $v !== ''
        ));

        $this->merge([
            'code' => strtoupper(trim($this->input('code', ''))),
            'is_active' => $this->boolean('is_active'),
            'available_variables' => $variables ?: null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('mst_wa_reminder_templates', 'code'),
            ],
            'name' => ['required', 'string', 'max:150'],
            'trigger_type' => [
                'required',
                'string',
                Rule::in(WaReminderTemplate::triggerTypes()),
            ],
            'audience_type' => [
                'required',
                'string',
                Rule::in(WaReminderTemplate::audienceTypes()),
            ],
            'message_body' => ['required', 'string', 'max:5000'],
            'available_variables' => ['nullable', 'array'],
            'available_variables.*' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['boolean'],
        ];
    }
}
