<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LabOrder\Models\LabWorkflowEvidence;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LabWorkflowEvidence>
 */
class LabWorkflowEvidenceFactory extends Factory
{
    protected $model = LabWorkflowEvidence::class;

    public function definition(): array
    {
        return [
            'lab_order_id' => LabOrder::factory(),
            'branch_id' => null,
            'type' => LabWorkflowEvidence::TYPE_SPK_PHOTO,
            'file_path' => 'lab-workflow-evidence/0/test-'.Str::random(8).'.png',
            'mime_type' => 'image/png',
            'file_size' => 1024,
            'checksum' => hash('sha256', Str::random(16)),
            'uploaded_by' => User::factory(),
            'captured_at' => now(),
        ];
    }
}
