<?php

namespace App\Modules\ClinicVisit\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateClinicVisitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * AUDIT-RME-CLINIC-VISIT-BRANCH-AUTHORITY-1 — `clinic_room_id` and
     * `doctor_id` are NOT editable here. They were accepted since Sprint 20
     * (checked only for existence), before room assignment gained its rules
     * (same-branch active room, not on a terminal visit, the doctor derived from
     * the room — Sprint 58.6 / 60.8 / 66.1.4). The edit form never sent them, so
     * the only use was a crafted request bypassing every one of those rules; an
     * arbitrary `doctor_id` also widened that doctor's patient scope. Room and
     * doctor are written ONLY by ClinicVisitService::assignRoom()
     * (`rme.visits.assign-room`), and the service strips both here too.
     */
    public function rules(): array
    {
        return [
            'chief_complaint' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'initial_treatment_id' => ['sometimes', 'nullable', 'integer', Rule::exists('mst_treatments', 'id')->where('is_active', true)],
            'initial_service_note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
