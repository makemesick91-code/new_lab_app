<?php

namespace App\Modules\ClinicVisit\Requests;

use App\Modules\ClinicVisit\Models\ClinicVisit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransitionStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Sprint 62.1 — the manual transition surface can move a visit toward
            // the cashier ("Selesai Pemeriksaan" = cashier_pending) but can NEVER
            // request `completed` ("Selesai Visit"); completion is payment-driven.
            'status' => ['required', 'string', Rule::in([
                ClinicVisit::STATUS_WAITING,
                ClinicVisit::STATUS_IN_PROGRESS,
                ClinicVisit::STATUS_CASHIER_PENDING,
                ClinicVisit::STATUS_CANCELLED,
            ])],
        ];
    }
}
