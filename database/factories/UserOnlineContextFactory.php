<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use App\Modules\RmeOnlineContext\Models\UserOnlineContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserOnlineContext>
 */
class UserOnlineContextFactory extends Factory
{
    protected $model = UserOnlineContext::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'branch_id' => Branch::factory()->create(['is_rme_enabled' => true])->id,
            'clinic_room_id' => null,
            'role_context' => UserOnlineContext::ROLE_ADMIN_CLINIC,
            'status' => UserOnlineContext::STATUS_ONLINE,
            'online_since' => now(),
            'last_seen_at' => now(),
            'offline_at' => null,
        ];
    }

    public function doctorOnline(?ClinicRoom $room = null): static
    {
        return $this->state(function (array $attributes) use ($room) {
            $branchId = $attributes['branch_id'] ?? Branch::factory()->create(['is_rme_enabled' => true])->id;
            $room ??= ClinicRoom::factory()->create(['branch_id' => $branchId]);

            return [
                'branch_id' => $branchId,
                'clinic_room_id' => $room->id,
                'role_context' => UserOnlineContext::ROLE_DOCTOR,
                'status' => UserOnlineContext::STATUS_ONLINE,
                'online_since' => now(),
                'last_seen_at' => now(),
            ];
        });
    }
}
