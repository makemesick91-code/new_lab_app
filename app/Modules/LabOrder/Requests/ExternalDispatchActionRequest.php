<?php

namespace App\Modules\LabOrder\Requests;

use App\Modules\LabOrder\Models\LabExternalDispatch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * LAB-WORKFLOW-V2 — external lab dispatch actions (create/sent/review share
 * one request; per-action requiredness is route-aware, and the services
 * re-assert every business rule server-side).
 */
class ExternalDispatchActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware + service guards
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $reviewing = $this->routeIs('lab-v2-orders.external-review');

        return [
            'external_lab_id' => ['nullable', 'integer', 'exists:mst_external_labs,id'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'expected_return_date' => ['nullable', 'date'],
            'shipping_method' => ['nullable', 'string', 'max:100'],
            'reference_number' => ['nullable', 'string', 'max:100'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'result' => [
                $reviewing ? 'required' : 'nullable',
                Rule::in([LabExternalDispatch::RESULT_ACCEPTED, LabExternalDispatch::RESULT_REJECTED]),
            ],
        ];
    }
}
