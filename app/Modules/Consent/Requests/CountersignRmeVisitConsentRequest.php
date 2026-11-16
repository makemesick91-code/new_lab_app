<?php

namespace App\Modules\Consent\Requests;

use App\Modules\Consent\Models\RmeVisitConsent;
use App\Modules\Prescription\Services\PrescriptionCanvasDecoder;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * DMS memo M1.1–M1.3, PR1 (rule 241 §1) — the treating doctor's countersignature.
 *
 * Authorization first (R8A-2 pattern: the policy's response, so a refused
 * account gets its 403 whatever it sends), then one field: the drawn signature,
 * bounded at the edge in characters of the data URI. No doctor, account, branch
 * or status is accepted — the service derives every one of them from the locked
 * visit, the consent and the signed-in account.
 */
class CountersignRmeVisitConsentRequest extends FormRequest
{
    public function authorize(): Response
    {
        $consent = $this->route('consent');

        if (! $consent instanceof RmeVisitConsent) {
            return Response::denyAsNotFound();
        }

        return Gate::forUser($this->user())->inspect('countersign', $consent);
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'doctor_signature' => ['required', 'string', 'max:'.PrescriptionCanvasDecoder::MAX_SIGNATURE_PAYLOAD_LENGTH],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'doctor_signature.required' => 'Tanda tangan dokter wajib diisi.',
            'doctor_signature.max' => 'Ukuran gambar tanda tangan melebihi batas yang diizinkan.',
        ];
    }
}
