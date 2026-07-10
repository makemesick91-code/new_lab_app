<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LabOrder\Models\LabPickupTask;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LabPickupTask>
 */
class LabPickupTaskFactory extends Factory
{
    protected $model = LabPickupTask::class;

    public function definition(): array
    {
        return [
            'lab_order_id' => LabOrder::factory(),
            'branch_id' => Branch::factory(),
            'status' => LabPickupTask::STATUS_PENDING,
            'courier_id' => null,
            'created_by' => User::factory(),
        ];
    }
}
