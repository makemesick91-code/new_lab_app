<?php

namespace App\Modules\ClinicVisit\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * REVISION-NEW-VISIT-GLOBAL-PATIENT-LOOKUP-1 — input contract for the
 * "Kunjungan Baru" patient combobox.
 *
 * Deliberately a one-field request. There is no `branch_id` and no `limit`.
 * The lookup is now global across the RME patient registry, which makes the
 * absent `branch_id` more important rather than less: there is no branch input
 * to widen, to re-point, or to confuse with the branch the visit will be
 * created at. The result ceiling stays a server constant. Anything else a
 * caller appends is simply never read.
 */
class PatientSearchRequest extends FormRequest
{
    /** Route + controller policy own authorization. */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function term(): string
    {
        return trim((string) $this->input('q', ''));
    }
}
