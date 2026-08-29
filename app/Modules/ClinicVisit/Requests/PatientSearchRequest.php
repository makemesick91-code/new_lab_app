<?php

namespace App\Modules\ClinicVisit\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * REVISION-NEW-VISIT-PATIENT-SEARCH-COMBOBOX-1 — input contract for the
 * "Kunjungan Baru" patient combobox.
 *
 * Deliberately a one-field request. There is no `branch_id` and no `limit`:
 * branch scope comes from the canonical server-side working-branch authority and
 * the result ceiling is a server constant, so neither can be influenced from the
 * query string. Anything else a caller appends is simply never read.
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
