<?php

declare(strict_types=1);

namespace App\Modules\DoctorAccess\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RevokeDoctorBreakGlassGrantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('grant_doctor_break_glass_access') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Revocation carries a written reason for the same purpose granting
            // does: an emergency window nobody can explain opening or closing
            // is not an operational action.
            'reason' => [
                'required',
                'string',
                'min:'.(int) config('doctor_access.reason.min_length', 10),
                'max:'.(int) config('doctor_access.reason.max_length', 1000),
            ],
        ];
    }
}
