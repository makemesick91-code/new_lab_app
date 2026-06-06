<?php

namespace App\Modules\Delivery\Requests;

use App\Modules\Delivery\Requests\Concerns\ValidatesPodSignature;
use Illuminate\Foundation\Http\FormRequest;

class UploadPodRequest extends FormRequest
{
    use ValidatesPodSignature;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return $this->podSignatureRules();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->podSignatureMessages();
    }
}
