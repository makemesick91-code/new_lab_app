<?php

namespace App\Modules\Delivery\Requests;

class ReassignCourierRequest extends AssignCourierRequest
{
    public function rules(): array
    {
        return [
            'courier_id' => ['required', 'exists:users,id'],
            'notes' => ['required', 'string', 'max:1000'],
        ];
    }
}
