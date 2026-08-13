<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * LEGACY-RME-PDF-ROLL-4 — assign or revoke a migration operator.
 *
 * SHAPE ONLY, AND THAT IS DELIBERATE. `wave_branch_id` is validated to exist,
 * but whether it belongs to THIS wave is re-checked in the service — the IDOR
 * boundary belongs where the CLI passes through too, not only on the HTTP path.
 * The same is true of the operator's own permission.
 */
class AssignLegacyRmeWaveOperatorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'wave_branch_id' => ['required', 'integer', 'exists:ops_rme_legacy_wave_branches,id'],
        ];
    }
}
