<?php

namespace App\Modules\Satusehat\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AssignSatusehatIssueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // policy-checked in the controller
    }

    public function rules(): array
    {
        return [
            'assigned_to' => ['required', 'integer', 'exists:users,id'],
        ];
    }
}
