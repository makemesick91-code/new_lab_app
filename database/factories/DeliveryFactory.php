<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Delivery\Models\Delivery;
use App\Modules\LabOrder\Models\LabOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Delivery>
 */
class DeliveryFactory extends Factory
{
    protected $model = Delivery::class;

    public function definition(): array
    {
        $year = now()->format('Y');

        return [
            'lab_order_id' => LabOrder::factory()->create(['status' => LabOrder::STATUS_READY_FOR_DELIVERY]),
            'delivery_number' => 'DLV-'.$year.'-'.str_pad((string) fake()->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT),
            'courier_id' => User::factory(),
            'status' => Delivery::STATUS_READY_FOR_DELIVERY,
            'delivery_notes' => fake()->optional()->sentence(),
            'receiver_name' => null,
            'receiver_signature_path' => null,
            'receiver_photo_path' => null,
            'received_at' => null,
            'started_at' => null,
            'completed_at' => null,
            'created_by' => User::factory(),
        ];
    }

    public function inDelivery(): static
    {
        return $this->state(fn () => [
            'status' => Delivery::STATUS_IN_DELIVERY,
            'started_at' => now(),
        ]);
    }

    public function delivered(): static
    {
        return $this->state(fn () => [
            'status' => Delivery::STATUS_DELIVERED,
            'receiver_name' => 'Budi Santoso',
            'receiver_signature_path' => 'deliveries/demo/signature.png',
            'receiver_photo_path' => 'deliveries/demo/photo.png',
            'received_at' => now(),
            'started_at' => now()->subHour(),
        ]);
    }

    public function completed(): static
    {
        return $this->delivered()->state(fn () => [
            'status' => Delivery::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);
    }
}
