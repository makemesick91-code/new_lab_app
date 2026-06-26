<?php

namespace App\Modules\Patient\Requests;

use App\Modules\Patient\Models\Patient;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Sprint 61.1 — validates a base64 KTP scan uploaded from the registration UI
 * before it is parked under a temp token. Authorization mirrors patient
 * creation (manage patients) so doctors/cashiers cannot reach it.
 */
class StoreKtpScanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Patient::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $maxKb = (int) config('scanner.ktp.max_input_kb', 6144);

        return [
            'document_type' => ['required', 'string', 'in:ktp'],
            // base64 payload — bound the raw string length to ~4/3 of the byte
            // ceiling so an oversized blob is rejected before decoding.
            'image_base64' => ['required', 'string', 'max:'.($maxKb * 1024 * 2)],
            'mime_type' => ['nullable', 'string', 'in:image/jpeg,image/png,image/webp'],
            'filename' => ['nullable', 'string', 'max:255'],
        ];
    }
}
