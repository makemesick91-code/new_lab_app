<?php

namespace App\Modules\ClinicRoom\Requests;

use App\Modules\Branch\Services\BranchContext;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreClinicRoomRequest extends FormRequest
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
        $branchId = app(BranchContext::class)->requireId();

        return [
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('mst_clinic_rooms', 'code')->where('branch_id', $branchId),
            ],
            'name' => [
                'required',
                'string',
                'max:150',
                Rule::unique('mst_clinic_rooms', 'name')->where('branch_id', $branchId),
            ],
            'type' => ['required', 'string', Rule::in(ClinicRoom::TYPES)],
            'status' => ['required', 'string', Rule::in(ClinicRoom::STATUSES)],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
