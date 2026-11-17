<?php

declare(strict_types=1);

namespace App\Modules\RmeAmendment\Requests;

use App\Modules\RmeAmendment\Models\RmeAmendment;
use Illuminate\Foundation\Http\FormRequest;

/**
 * DMS-RME-COMPREHENSIVE-CLOSURE-1 memo M3.3 (rule 240 §6) — the input of an
 * amendment action, one class for the request (`reason` + `proposed`) and the
 * decisions (`decision_reason`), like DecideRmeInvoiceCorrectionRequest. Only
 * the envelope is checked here; RmeAmendmentService validates the proposal
 * itself against the locked record (the single authority) and re-checks the
 * reason bounds. Authorization is the policy's and the service's.
 */
class RmeAmendmentActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** The chart editor posts its tooth map as a JSON string (as on a chart save). */
    protected function prepareForValidation(): void
    {
        $proposed = $this->input('proposed');

        if (is_array($proposed) && is_string($proposed['tooth_map_payload'] ?? null)) {
            $decoded = json_decode($proposed['tooth_map_payload'], true);
            $proposed['tooth_map_payload'] = is_array($decoded) ? $decoded : null;
            $this->merge(['proposed' => $proposed]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $bounded = ['string', 'min:'.RmeAmendment::REASON_MIN, 'max:'.RmeAmendment::REASON_MAX];

        if ($this->routeIs('rme.amendments.store-sheet', 'rme.amendments.store-chart')) {
            return [
                'reason' => ['required', ...$bounded],
                'proposed' => ['required', 'array'],
            ];
        }

        if ($this->routeIs('rme.amendments.reject')) {
            return ['decision_reason' => ['required', ...$bounded]];
        }

        return [];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reason.required' => 'Alasan amandemen wajib diisi.',
            'reason.min' => 'Alasan amandemen minimal '.RmeAmendment::REASON_MIN.' karakter.',
            'reason.max' => 'Alasan amandemen maksimal '.RmeAmendment::REASON_MAX.' karakter.',
            'proposed.required' => 'Usulan perubahan wajib diisi.',
            'decision_reason.required' => 'Alasan penolakan wajib diisi.',
            'decision_reason.min' => 'Alasan penolakan minimal '.RmeAmendment::REASON_MIN.' karakter.',
            'decision_reason.max' => 'Alasan penolakan maksimal '.RmeAmendment::REASON_MAX.' karakter.',
        ];
    }

    /** @return array<string, mixed> */
    public function proposal(): array
    {
        return (array) ($this->validated()['proposed'] ?? []);
    }
}
