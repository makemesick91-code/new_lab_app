<?php

namespace App\Modules\Satusehat\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ExcludeSatusehatCandidateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('review_satusehat_submissions') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'exclusion_reason' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }
}
