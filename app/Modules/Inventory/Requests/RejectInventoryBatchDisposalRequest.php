<?php

namespace App\Modules\Inventory\Requests;

use App\Modules\Inventory\Models\InventoryBatchDisposalRequest;
use Illuminate\Foundation\Http\FormRequest;

class RejectInventoryBatchDisposalRequest extends FormRequest
{
    public function authorize(): bool
    {
        $request = $this->route('batchDisposalRequest');

        return $request instanceof InventoryBatchDisposalRequest
            && $this->user()?->can('reject', $request) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'rejection_reason' => ['required', 'string', 'min:3', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rejection_reason.required' => 'Alasan penolakan wajib diisi.',
            'rejection_reason.min' => 'Alasan penolakan minimal 3 karakter.',
        ];
    }
}
