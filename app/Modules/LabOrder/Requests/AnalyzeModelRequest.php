<?php

namespace App\Modules\LabOrder\Requests;

use App\Modules\LabOrder\Models\LabModelAnalysis;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** LAB-WORKFLOW-V2 — model analysis decision (internal vs external). */
class AnalyzeModelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware + state machine guards
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in(LabModelAnalysis::DECISIONS)],
            'reason' => ['required', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'external_lab_id' => [
                'required_if:decision,'.LabModelAnalysis::DECISION_EXTERNAL,
                'nullable', 'integer', 'exists:mst_external_labs,id',
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reason.required' => 'Alasan keputusan analisa wajib diisi.',
            'external_lab_id.required_if' => 'Lab eksternal tujuan wajib dipilih untuk jalur eksternal.',
        ];
    }
}
