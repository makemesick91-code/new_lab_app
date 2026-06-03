<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\Production\Models\LabOrderAssignment;
use App\Modules\Technician\Models\Technician;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LabOrderAssignment>
 */
class LabOrderAssignmentFactory extends Factory
{
    protected $model = LabOrderAssignment::class;

    public function definition(): array
    {
        return [
            'lab_order_id' => LabOrder::factory(),
            'technician_id' => Technician::factory(),
            'assigned_by' => User::factory(),
            'assigned_at' => now(),
            'started_at' => null,
            'completed_at' => null,
            'status' => LabOrderAssignment::STATUS_ASSIGNED,
            'notes' => null,
        ];
    }

    public function inProgress(): static
    {
        return $this->state(fn () => ['status' => LabOrderAssignment::STATUS_IN_PROGRESS, 'started_at' => now()]);
    }

    public function done(): static
    {
        return $this->state(fn () => ['status' => LabOrderAssignment::STATUS_DONE, 'started_at' => now(), 'completed_at' => now()]);
    }
}
