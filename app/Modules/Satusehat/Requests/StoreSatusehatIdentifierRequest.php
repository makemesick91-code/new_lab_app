<?php

namespace App\Modules\Satusehat\Requests;

use App\Modules\Satusehat\Models\SatusehatEntityIdentifier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSatusehatIdentifierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage_satusehat_settings') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'environment' => ['required', Rule::in((array) config('satusehat.allowed_environments', ['sandbox', 'production']))],
            'entity_type' => ['required', Rule::in(SatusehatEntityIdentifier::ENTITY_TYPES)],
            'local_entity_type' => ['required', 'string', 'max:60'],
            'local_entity_id' => ['required', 'integer', 'min:1'],
            // Format-only (no external lookup). Bounded, safe character set.
            'remote_identifier' => ['required', 'string', 'max:191', 'regex:/^[A-Za-z0-9._:\-]+$/'],
            'identifier_system' => ['nullable', 'string', 'max:191'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
