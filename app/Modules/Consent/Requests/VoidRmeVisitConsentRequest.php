<?php

namespace App\Modules\Consent\Requests;

use App\Modules\Consent\Models\RmeVisitConsent;
use App\Modules\Consent\Services\RmeVisitConsentService;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * DMS-RME-COMPREHENSIVE-CLOSURE-1 (OD-5-8) — the supervised consent void.
 *
 * Authorization first (the policy: dedicated permission, branch and clinical
 * scope, not yet voided), then a written reason. Maker ≠ checker and the
 * examination window are decided in the service under the visit lock.
 */
class VoidRmeVisitConsentRequest extends FormRequest
{
    public function authorize(): Response
    {
        $consent = $this->route('consent');

        if (! $consent instanceof RmeVisitConsent) {
            return Response::denyAsNotFound();
        }

        return Gate::forUser($this->user())->inspect('void', $consent);
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'void_reason' => ['required', 'string', 'min:'.RmeVisitConsentService::VOID_REASON_MIN, 'max:'.RmeVisitConsentService::VOID_REASON_MAX],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'void_reason.required' => 'Alasan pembatalan wajib diisi.',
            'void_reason.min' => 'Alasan pembatalan minimal '.RmeVisitConsentService::VOID_REASON_MIN.' karakter.',
            'void_reason.max' => 'Alasan pembatalan maksimal '.RmeVisitConsentService::VOID_REASON_MAX.' karakter.',
        ];
    }
}
