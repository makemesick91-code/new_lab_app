<?php

declare(strict_types=1);

namespace App\Modules\RmeAmendment\Requests;

use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Services\HandwritingPayloadGuard;
use App\Modules\Odontogram\Models\Odontogram;
use App\Modules\RmeAmendment\Models\RmeAmendment;
use App\Modules\RmeAmendment\Services\RmeAmendmentService;
use App\Modules\RmeAmendment\Services\RmeRecordVersionService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

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
    /**
     * Runs before validation: an account that may not see the record (store) or
     * the amendment (decisions) gets 404 here, never a validation answer that
     * would confirm what exists (CG-05). The abilities themselves are decided
     * by the policy and the service.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }

        $target = $this->route('amendment') ?? $this->route('medicalRecord') ?? $this->route('odontogram');
        // Rule 243 §2.11 — a record is asked for its CONTENT (exactly `view`
        // unless the doctor encounter scope applies).
        $ability = $target instanceof MedicalRecord || $target instanceof Odontogram
            ? RmeRecordVersionService::readAbility($target, $user)
            : 'view';
        abort_unless(is_object($target) && Gate::forUser($user)->allows($ability, $target), 404);

        return true;
    }

    /**
     * The chart editor posts its tooth map as a JSON string (as on a chart save).
     * An unreadable string is kept as it came, so the `array` rule refuses it —
     * it never becomes an empty chart.
     */
    protected function prepareForValidation(): void
    {
        $proposed = $this->input('proposed');

        if (is_array($proposed) && is_string($proposed['tooth_map_payload'] ?? null)) {
            $decoded = json_decode($proposed['tooth_map_payload'], true);
            if (is_array($decoded)) {
                $proposed['tooth_map_payload'] = $decoded;
                $this->merge(['proposed' => $proposed]);
            }
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $bounded = ['string', 'min:'.RmeAmendment::REASON_MIN, 'max:'.RmeAmendment::REASON_MAX];

        // Rule 240 §8 — an optional handwriting correction page (bounded at the
        // edge like a page save, rule 237 §5; decoded and validated by the
        // service) and the page of the book it corrects.
        if ($this->routeIs('rme.amendments.store-sheet')) {
            return [
                'reason' => ['required', ...$bounded],
                'proposed' => ['required', 'array'],
                'handwriting_data' => ['nullable', 'string', 'max:'.HandwritingPayloadGuard::MAX_HANDWRITING_PAYLOAD_LENGTH],
                'corrects_page_number' => ['nullable', 'integer', 'min:1', 'max:2147483647'],
            ];
        }

        // The chart form always posts the editor's tooth map; empty or unreadable
        // means the editor failed, and is refused rather than read as "no teeth".
        if ($this->routeIs('rme.amendments.store-chart')) {
            return [
                'reason' => ['required', ...$bounded],
                'proposed' => ['required', 'array'],
                'proposed.tooth_map_payload' => ['required', 'array'],
            ];
        }

        if ($this->routeIs('rme.amendments.reject')) {
            return ['decision_reason' => ['required', ...$bounded]];
        }

        // Approve-and-apply (rule 240 §7): an optional note, bounded like a reason.
        if ($this->routeIs('rme.amendments.approve')) {
            return ['decision_reason' => ['nullable', 'string', 'max:'.RmeAmendment::REASON_MAX]];
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
            'handwriting_data.string' => HandwritingPayloadGuard::MESSAGE_INVALID,
            'handwriting_data.max' => HandwritingPayloadGuard::MESSAGE_TOO_LARGE,
            'corrects_page_number.integer' => RmeAmendmentService::MESSAGE_CORRECTS_PAGE,
            'corrects_page_number.min' => RmeAmendmentService::MESSAGE_CORRECTS_PAGE,
            'corrects_page_number.max' => RmeAmendmentService::MESSAGE_CORRECTS_PAGE,
            'proposed.tooth_map_payload.required' => RmeAmendmentService::MESSAGE_TOOTH_MAP,
            'proposed.tooth_map_payload.array' => RmeAmendmentService::MESSAGE_TOOTH_MAP,
            'decision_reason.required' => 'Alasan penolakan wajib diisi.',
            'decision_reason.min' => 'Alasan penolakan minimal '.RmeAmendment::REASON_MIN.' karakter.',
            'decision_reason.max' => $this->routeIs('rme.amendments.approve')
                ? RmeAmendmentService::MESSAGE_DECISION_NOTE
                : 'Alasan penolakan maksimal '.RmeAmendment::REASON_MAX.' karakter.',
        ];
    }

    /**
     * The whole proposal as posted (with the chart editor's tooth map decoded).
     * Never `validated()`: with nested rules present (the chart's tooth map),
     * Laravel's excludeUnvalidatedArrayKeys would return only the validated
     * keys and silently drop the notes (re-review HIGH-R1). The proposal's
     * content is validated by RmeAmendmentService, the single authority.
     *
     * @return array<string, mixed>
     */
    public function proposal(): array
    {
        $proposed = $this->input('proposed', []);

        return is_array($proposed) ? $proposed : [];
    }

    /**
     * Rule 240 §8 — the drawn correction page as posted (the canvas data URI), or
     * null when the doctor drew none. RmeAmendmentService decodes and validates it.
     */
    public function correctionPage(): ?string
    {
        $canvas = $this->input('handwriting_data');

        return is_string($canvas) && $canvas !== '' ? $canvas : null;
    }

    /** Rule 240 §8 — the page of the book the correction names, validated as an integer above. */
    public function correctsPageNumber(): ?int
    {
        $page = $this->validated('corrects_page_number');

        return $page !== null ? (int) $page : null;
    }
}
