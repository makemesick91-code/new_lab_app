<?php

namespace Database\Factories;

use App\Modules\WaReminderTemplate\Models\WaReminderTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WaReminderTemplate>
 */
class WaReminderTemplateFactory extends Factory
{
    protected $model = WaReminderTemplate::class;

    public function definition(): array
    {
        $triggerType = fake()->randomElement(WaReminderTemplate::triggerTypes());
        $audienceType = fake()->randomElement(WaReminderTemplate::audienceTypes());

        return [
            'code' => 'TPL-'.strtoupper(Str::random(6)),
            'name' => fake()->unique()->words(3, true).' '.strtoupper(Str::random(3)),
            'trigger_type' => $triggerType,
            'audience_type' => $audienceType,
            'message_body' => 'Halo {{patient_name}}, '.fake()->sentence(),
            'available_variables' => ['patient_name', 'clinic_name'],
            'description' => fake()->optional()->sentence(),
            'sort_order' => fake()->numberBetween(0, 100),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
