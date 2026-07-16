<?php

namespace App\Modules\Satusehat\Requests;

use Illuminate\Foundation\Http\FormRequest;

class WaiveSatusehatIssueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // policy-checked in the controller
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'waiver_expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }
}
