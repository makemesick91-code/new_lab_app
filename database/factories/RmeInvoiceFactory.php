<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RmeInvoice>
 */
class RmeInvoiceFactory extends Factory
{
    protected $model = RmeInvoice::class;

    public function definition(): array
    {
        $visit = ClinicVisit::factory()->cashierPending()->create();
        $month = now()->format('Ym');

        return [
            'branch_id' => $visit->branch_id,
            'clinic_visit_id' => $visit->id,
            'patient_id' => $visit->patient_id,
            'medical_record_id' => null,
            'cashier_id' => User::factory(),
            'invoice_number' => 'RME-'.$month.'-'.str_pad((string) fake()->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT),
            'status' => RmeInvoice::STATUS_DRAFT,
            'subtotal' => 0,
            'discount_total' => 0,
            'grand_total' => 0,
            'notes' => null,
        ];
    }

    public function unpaid(): static
    {
        return $this->state(fn () => [
            'status' => RmeInvoice::STATUS_UNPAID,
        ]);
    }

    public function partial(): static
    {
        return $this->state(fn () => [
            'status' => RmeInvoice::STATUS_PARTIAL,
        ]);
    }

    public function paid(): static
    {
        return $this->state(fn () => [
            'status' => RmeInvoice::STATUS_PAID,
        ]);
    }

    public function void(): static
    {
        return $this->state(fn () => [
            'status' => RmeInvoice::STATUS_VOID,
        ]);
    }

    public function withMedicalRecord(MedicalRecord $record): static
    {
        return $this->state(fn () => [
            'medical_record_id' => $record->id,
        ]);
    }
}
