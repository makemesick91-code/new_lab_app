<?php

namespace App\Modules\Branch\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize the manually entered code (trim + uppercase) and coerce
     * checkbox booleans so unchecked flags persist as false. See StoreBranchRequest.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => strtoupper(trim((string) $this->input('code'))),
            'is_active' => $this->boolean('is_active'),
            'is_rme_enabled' => $this->boolean('is_rme_enabled'),
            'is_inventory_enabled' => $this->boolean('is_inventory_enabled'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $branchId = $this->route('branch')?->id;

        return [
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Z0-9-]+$/', Rule::unique('mst_branches', 'code')->ignore($branchId)],
            'name' => ['required', 'string', 'max:150'],
            'address' => ['nullable', 'string', 'max:1000'],
            'phone' => ['nullable', 'string', 'max:50'],
            'is_active' => ['boolean'],
            'is_rme_enabled' => ['boolean'],
            'is_inventory_enabled' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.regex' => 'Kode cabang hanya boleh berisi huruf, angka, dan tanda hubung.',
        ];
    }
}
