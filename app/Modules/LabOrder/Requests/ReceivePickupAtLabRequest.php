<?php

namespace App\Modules\LabOrder\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * LAB-WORKFLOW-V2 — lab-side receive confirmation.
 */
class ReceivePickupAtLabRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware + state machine actor guard

    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'discrepancy_note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
