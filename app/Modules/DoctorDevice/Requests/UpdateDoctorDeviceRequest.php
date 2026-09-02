<?php

namespace App\Modules\DoctorDevice\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDoctorDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('doctorDevice')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'device_name' => ['required', 'string', 'max:150'],
            'branch_id' => ['required', 'integer'],
            'platform' => ['nullable', 'string', 'max:40'],
            'device_model' => ['nullable', 'string', 'max:120'],
            'os_version' => ['nullable', 'string', 'max:60'],
            'app_version' => ['nullable', 'string', 'max:60'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
