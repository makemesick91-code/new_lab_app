<?php

namespace Database\Factories;

use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LabOrder\Models\LabOrderItem;
use App\Modules\LabService\Models\LabService;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LabOrderItem>
 */
class LabOrderItemFactory extends Factory
{
    protected $model = LabOrderItem::class;

    public function definition(): array
    {
        $quantity = fake()->numberBetween(1, 4);
        $unitPrice = fake()->randomFloat(2, 100000, 3000000);

        return [
            'lab_order_id' => LabOrder::factory(),
            'lab_service_id' => LabService::factory(),
            'tooth_number' => (string) fake()->numberBetween(11, 48),
            'shade_color_text' => fake()->randomElement(['A1', 'A2', 'A3', 'B1', 'B2']),
            'material_text' => fake()->randomElement(['Zirconia', 'PFM', 'Emax', 'Acrylic']),
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'subtotal' => round($quantity * $unitPrice, 2),
            'notes' => fake()->optional()->sentence(),
        ];
    }
}
