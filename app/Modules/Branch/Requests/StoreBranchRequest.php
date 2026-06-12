<?php

namespace App\Modules\Branch\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Branch code is entered manually (never auto-generated). Normalize to a
     * trimmed, uppercase token so it stays stable as a future patient-ID
     * component. Booleans are coerced so unchecked checkboxes persist as false.
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
        return [
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Z0-9-]+$/', 'unique:mst_branches,code'],
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
