<?php

namespace App\Modules\MedicalRecord\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FinalizeMedicalRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [];
    }
}
