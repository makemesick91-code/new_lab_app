<?php

declare(strict_types=1);

namespace App\Modules\ClinicOversight\Requests;

use App\Modules\RmeInvoice\Support\RmeZeroValueCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * HARDENING-RME-RP0-CLOSURE-OWNER-DECISIONS-AND-PAID-TREATMENT-1 — filters of the
 * read-only "Tanpa Biaya (Rp0)" oversight list. Authorization is the area gate in
 * the controller; a branch filter can only NARROW the authorized set
 * (ClinicOversightScope::narrow), never widen it.
 */
class OversightZeroValueClosureFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'category' => ['nullable', Rule::in(RmeZeroValueCategory::ALL)],
            'treatment_id' => ['nullable', 'integer', 'min:1'],
            'doctor_id' => ['nullable', 'integer', 'min:1'],
            'closer_id' => ['nullable', 'integer', 'min:1'],
            'creator_id' => ['nullable', 'integer', 'min:1'],
            'invoice_number' => ['nullable', 'string', 'max:64'],
            'visit_number' => ['nullable', 'string', 'max:64'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    /** @return array<string, int|string|null> */
    public function filters(): array
    {
        $int = fn (string $key): ?int => $this->filled($key) ? (int) $this->validated($key) : null;
        $str = fn (string $key): ?string => $this->filled($key) ? trim((string) $this->validated($key)) : null;

        return [
            'branch_id' => $int('branch_id'),
            'category' => $str('category'),
            'treatment_id' => $int('treatment_id'),
            'doctor_id' => $int('doctor_id'),
            'closer_id' => $int('closer_id'),
            'creator_id' => $int('creator_id'),
            'invoice_number' => $str('invoice_number'),
            'visit_number' => $str('visit_number'),
        ];
    }
}
