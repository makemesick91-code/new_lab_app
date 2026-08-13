<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Requests;

use App\Modules\LegacyRme\Models\LegacyRmeMigrationWave;
use Illuminate\Foundation\Http\FormRequest;

/**
 * LEGACY-RME-PDF-ROLL-4 — register a migration wave.
 *
 * SHAPE ONLY. The branch set is accepted here as well-formed strings and is
 * AUTHORIZED in LegacyRmeWaveGovernanceService, against both the deployment's
 * approval and the live RME-enabled branch list. Validating membership in a
 * FormRequest would put an authorization decision in the request layer, where a
 * second caller (the CLI) would bypass it entirely.
 */
class StoreLegacyRmeMigrationWaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', LegacyRmeMigrationWave::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $max = (int) config('legacy_rme_operations.quota.max_declarable_daily', 500);

        return [
            'code' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9\-_]+$/'],
            'name' => ['required', 'string', 'max:150'],
            'branch_codes' => ['required', 'array', 'min:1'],
            'branch_codes.*' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9\-_]+$/'],
            // Nullable is meaningful: "no ceiling declared", not zero.
            'daily_quota' => ['nullable', 'integer', 'min:0', 'max:'.max(1, $max)],
            'per_branch_daily_quota' => ['nullable', 'integer', 'min:0', 'max:'.max(1, $max)],
            'planned_start_date' => ['nullable', 'date'],
            'planned_end_date' => ['nullable', 'date', 'after_or_equal:planned_start_date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.regex' => 'Kode gelombang hanya boleh berisi huruf, angka, tanda hubung dan garis bawah.',
            'branch_codes.required' => 'Pilih minimal satu cabang untuk gelombang ini.',
        ];
    }
}
