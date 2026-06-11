<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\LabOrder\Models\LabCaseCandidate;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmeInvoiceItem;
use App\Modules\Treatment\Models\Treatment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LabCaseCandidate>
 */
class LabCaseCandidateFactory extends Factory
{
    protected $model = LabCaseCandidate::class;

    public function definition(): array
    {
        $visit = ClinicVisit::factory()->cashierPending()->create();
        $invoice = RmeInvoice::factory()->paid()->create([
            'branch_id' => $visit->branch_id,
            'clinic_visit_id' => $visit->id,
            'patient_id' => $visit->patient_id,
        ]);
        $treatment = Treatment::factory()->requiresLab()->create();
        $item = RmeInvoiceItem::create([
            'rme_invoice_id' => $invoice->id,
            'treatment_id' => $treatment->id,
            'description' => fake()->sentence(3),
            'qty' => 1,
            'unit_price' => 500000,
            'discount' => 0,
            'subtotal' => 500000,
        ]);

        return [
            'branch_id' => $visit->branch_id,
            'clinic_visit_id' => $visit->id,
            'rme_invoice_id' => $invoice->id,
            'rme_invoice_item_id' => $item->id,
            'patient_id' => $visit->patient_id,
            'doctor_id' => $visit->doctor_id,
            'treatment_id' => $treatment->id,
            'medical_record_id' => null,
            'source_description' => $item->description,
            'quantity' => 1,
            'estimated_price' => 500000,
            'status' => LabCaseCandidate::STATUS_PENDING_REVIEW,
            'metadata' => null,
            'created_by' => User::factory(),
        ];
    }

    public function converted(): static
    {
        return $this->state(fn () => [
            'status' => LabCaseCandidate::STATUS_CONVERTED_TO_LAB_ORDER,
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn () => [
            'status' => LabCaseCandidate::STATUS_REJECTED,
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => [
            'status' => LabCaseCandidate::STATUS_CANCELLED,
        ]);
    }
}
