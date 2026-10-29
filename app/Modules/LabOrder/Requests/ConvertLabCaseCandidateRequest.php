<?php

namespace App\Modules\LabOrder\Requests;

use App\Support\Clinical\ClinicalClock;
use Illuminate\Foundation\Http\FormRequest;

class ConvertLabCaseCandidateRequest extends FormRequest
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
            'lab_service_id' => ['required', 'integer', 'exists:mst_lab_services,id'],
            'due_date' => ['required', 'date', 'after_or_equal:'.app(ClinicalClock::class)->todayString()],
            'notes' => ['nullable', 'string', 'max:2000'],
            'quantity' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lab_service_id.required' => 'Layanan lab wajib dipilih.',
            'due_date.required' => 'Tenggat wajib diisi.',
            'due_date.after_or_equal' => 'Tenggat tidak boleh sebelum hari ini.',
        ];
    }
}
