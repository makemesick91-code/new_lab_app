<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\LabOrder\Models\LabDeliveryTask;
use App\Modules\LabOrder\Models\LabOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LabDeliveryTask>
 */
class LabDeliveryTaskFactory extends Factory
{
    protected $model = LabDeliveryTask::class;

    public function definition(): array
    {
        return [
            'lab_order_id' => LabOrder::factory(),
            'branch_id' => Branch::factory(),
            'status' => LabDeliveryTask::STATUS_PENDING,
            'courier_id' => null,
            'created_by' => User::factory(),
        ];
    }
}
