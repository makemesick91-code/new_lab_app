<?php

namespace App\Modules\Satusehat\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * SATUSEHAT-4C — branch readiness threshold override input.
 *
 * The service allowlists + bounds every key; this request enforces shape +
 * a mandatory reason. Authorization is enforced in the controller (the
 * `configure` policy ability). No PII fields.
 */
class ReadinessThresholdRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            'thresholds' => ['nullable', 'array'],
            'thresholds.*' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ];
    }
}
