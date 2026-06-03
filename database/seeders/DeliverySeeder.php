<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Delivery\Models\Delivery;
use App\Modules\Delivery\Services\DeliveryService;
use App\Modules\Delivery\Services\DeliveryWorkflowService;
use App\Modules\LabOrder\Models\Attachment;
use App\Modules\LabOrder\Models\LabOrder;
use Illuminate\Database\Seeder;

class DeliverySeeder extends Seeder
{
    public function run(): void
    {
        if (Delivery::count() > 0) {
            return;
        }

        $admin = User::where('email', 'admin@asiadentallab.com')->first();

        if (! $admin) {
            return;
        }

        $courier = User::role('Courier')->first()
            ?? User::factory()->create(['name' => 'Demo Courier', 'email' => 'courier@asiadentallab.com'])->assignRole('Courier');

        $readyOrder = LabOrder::query()->where('status', LabOrder::STATUS_QC_PASSED)->first()
            ?? LabOrder::factory()->create(['status' => LabOrder::STATUS_QC_PASSED, 'created_by' => $admin->id]);

        app(DeliveryService::class)->create($readyOrder->refresh(), $courier->id, 'Seeded delivery', $admin);

        $deliveredOrder = LabOrder::factory()->create(['status' => LabOrder::STATUS_QC_PASSED, 'created_by' => $admin->id]);
        $delivered = app(DeliveryService::class)->create($deliveredOrder->refresh(), $courier->id, 'Seeded completed delivery', $admin);
        app(DeliveryWorkflowService::class)->start($delivered->refresh(), 'Seeded start', $admin);

        $delivered->refresh()->update([
            'receiver_name' => 'Budi Santoso',
            'receiver_signature_path' => 'deliveries/seed/signature.png',
            'receiver_photo_path' => 'deliveries/seed/receiver.png',
            'received_at' => now(),
        ]);

        Attachment::create([
            'entity_type' => Delivery::ENTITY_TYPE,
            'entity_id' => $delivered->id,
            'category' => 'POD_SIGNATURE',
            'file_name' => 'signature.png',
            'file_path' => 'deliveries/seed/signature.png',
            'mime_type' => 'image/png',
            'file_size' => 1,
            'uploaded_by' => $admin->id,
            'uploaded_at' => now(),
        ]);

        Attachment::create([
            'entity_type' => Delivery::ENTITY_TYPE,
            'entity_id' => $delivered->id,
            'category' => 'POD_RECEIVER_PHOTO',
            'file_name' => 'receiver.png',
            'file_path' => 'deliveries/seed/receiver.png',
            'mime_type' => 'image/png',
            'file_size' => 1,
            'uploaded_by' => $admin->id,
            'uploaded_at' => now(),
        ]);

        app(DeliveryWorkflowService::class)->markDelivered($delivered->refresh(), ['delivery_notes' => 'Seeded POD'], $admin);
        app(DeliveryWorkflowService::class)->complete($delivered->refresh(), 'Seeded complete', $admin);
    }
}
