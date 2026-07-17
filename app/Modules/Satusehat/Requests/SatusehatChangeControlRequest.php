<?php

namespace App\Modules\Satusehat\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * SATUSEHAT-4D — change-control write inputs. Route `permission:` middleware is
 * the authorization boundary; the service enforces category allowlist, blocked
 * categories, separation of duties, and reason length.
 */
class SatusehatChangeControlRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'category' => ['sometimes', 'string', 'max:60'],
            'reason' => ['sometimes', 'string', 'max:1000'],
            'scope' => ['sometimes', 'string', 'max:500'],
            'risk' => ['nullable', 'string', 'max:500'],
            'effective_date' => ['nullable', 'date'],
            'rollback_plan' => ['nullable', 'string', 'max:1000'],
            'payload' => ['nullable', 'array'],
        ];
    }
}
