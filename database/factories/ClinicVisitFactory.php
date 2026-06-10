<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Clinic\Models\Clinic;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\Patient\Models\Patient;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClinicVisit>
 */
class ClinicVisitFactory extends Factory
{
    protected $model = ClinicVisit::class;

    public function definition(): array
    {
        $queueNumber = fake()->unique()->numberBetween(1, 999);
        $visitDate = Carbon::today();

        return [
            'visit_number' => 'VIS-'.$visitDate->format('Ymd').'-'.str_pad((string) $queueNumber, 3, '0', STR_PAD_LEFT),
            'branch_id' => Branch::factory(),
            'clinic_id' => Clinic::factory(),
            'patient_id' => Patient::factory(),
            'doctor_id' => Doctor::factory(),
            'clinic_room_id' => null,
            'visit_date' => $visitDate->toDateString(),
            'queue_number' => $queueNumber,
            'status' => ClinicVisit::STATUS_REGISTERED,
            'chief_complaint' => fake()->optional()->sentence(),
            'initial_treatment_id' => null,
            'initial_service_note' => null,
            'check_in_at' => null,
            'started_at' => null,
            'completed_at' => null,
            'created_by' => User::factory(),
        ];
    }

    public function inProgress(): static
    {
        return $this->state(fn () => [
            'status' => ClinicVisit::STATUS_IN_PROGRESS,
            'check_in_at' => now(),
            'started_at' => now(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => ClinicVisit::STATUS_COMPLETED,
            'check_in_at' => now()->subHour(),
            'started_at' => now()->subHour(),
            'completed_at' => now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => [
            'status' => ClinicVisit::STATUS_CANCELLED,
            'cancelled_at' => now(),
        ]);
    }

    public function cashierPending(): static
    {
        return $this->state(fn () => [
            'status' => ClinicVisit::STATUS_CASHIER_PENDING,
            'check_in_at' => now()->subHour(),
            'started_at' => now()->subHour(),
        ]);
    }
}
