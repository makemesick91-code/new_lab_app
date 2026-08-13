<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * LEGACY-RME-PDF-ROLL-4 — a governance action on a wave or one of its branches.
 *
 * The ACTION is a closed list here so an unknown verb never reaches the service.
 * Whether the wave may actually make that transition is decided by the status
 * machine inside the service, under a row lock — a FormRequest reads a snapshot
 * that is already stale by the time the transaction opens.
 *
 * The reason is length-checked in the service too, because the CLI calls the
 * same methods and must not be able to skip it.
 */
class TransitionLegacyRmeMigrationWaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The controller re-authorizes against the policy for the specific wave;
        // this only keeps unauthenticated traffic out of validation.
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $min = (int) config('legacy_rme_operations.min_reason_length', 10);

        return [
            'action' => ['required', 'string', 'in:pause,resume,drain,cancel,complete'],
            // Required for every action that stops or ends something. `resume`
            // is the one action that restores normal operation, so it does not
            // demand a justification.
            'reason' => ['required_unless:action,resume', 'nullable', 'string', 'min:'.max(1, $min), 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.min' => 'Alasan wajib diisi minimal :min karakter.',
            'action.in' => 'Tindakan gelombang tidak dikenali.',
        ];
    }
}
