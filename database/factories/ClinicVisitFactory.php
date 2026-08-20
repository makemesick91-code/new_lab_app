<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Clinic\Models\Clinic;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\Patient\Models\Patient;
use App\Support\Clinical\ClinicalClock;
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
        // FIX-06 — mirror production: visit_date is a clinical calendar date.
        $visitDate = Carbon::parse(app(ClinicalClock::class)->todayString());

        return [
            'visit_number' => 'VIS-'.$visitDate->format('Ymd').'-'.str_pad((string) $queueNumber, 3, '0', STR_PAD_LEFT),
            'branch_id' => Branch::factory(),
            'clinic_id' => Clinic::factory(),
            'patient_id' => Patient::factory(),
            'doctor_id' => Doctor::factory(),
            // Hotfix Sprint 60.8 — visits default to an assigned treatment room so
            // the room-assignment exam gate is satisfied by default. Tests that
            // exercise the roomless/queue state pass `clinic_room_id => null`
            // explicitly, which overrides this default.
            'clinic_room_id' => ClinicRoom::factory(),
            'visit_date' => $visitDate->toDateString(),
            'queue_number' => $queueNumber,
            'status' => ClinicVisit::STATUS_REGISTERED,
            'visit_type' => ClinicVisit::VISIT_TYPE_NEW,
            'follow_up_of_visit_id' => null,
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
