<?php

namespace App\Modules\ClinicVisit\Requests;

use App\Modules\ClinicVisit\Models\ClinicVisit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransitionStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in([
                ClinicVisit::STATUS_WAITING,
                ClinicVisit::STATUS_IN_PROGRESS,
                ClinicVisit::STATUS_COMPLETED,
                ClinicVisit::STATUS_CANCELLED,
            ])],
        ];
    }
}
