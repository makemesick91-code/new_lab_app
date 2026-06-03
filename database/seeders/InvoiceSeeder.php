<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Invoice\Models\Invoice;
use App\Modules\Invoice\Services\InvoiceService;
use App\Modules\Invoice\Services\InvoiceWorkflowService;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LabService\Models\LabService;
use Illuminate\Database\Seeder;

class InvoiceSeeder extends Seeder
{
    public function run(): void
    {
        if (Invoice::count() > 0) {
            return;
        }

        $admin = User::where('email', 'admin@asiadentallab.com')->first();

        if (! $admin) {
            return;
        }

        $order = LabOrder::query()
            ->where('status', LabOrder::STATUS_COMPLETED)
            ->whereDoesntHave('invoiceItems.invoice', fn ($invoice) => $invoice->where('status', '!=', Invoice::STATUS_VOID))
            ->with('clinic')
            ->first();

        if (! $order) {
            $order = LabOrder::factory()->create([
                'status' => LabOrder::STATUS_COMPLETED,
                'created_by' => $admin->id,
            ]);
        }

        if ($order->items()->count() === 0) {
            $order->items()->create([
                'lab_service_id' => LabService::query()->value('id') ?? LabService::factory()->create()->id,
                'quantity' => 1,
                'unit_price' => 1500000,
                'subtotal' => 1500000,
                'notes' => 'Seeded invoice item source',
            ]);
        }

        $invoice = app(InvoiceService::class)->create([
            'clinic_id' => $order->clinic_id,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(14)->toDateString(),
            'lab_order_ids' => [$order->id],
            'notes' => 'Seeded invoice',
        ], $admin);

        app(InvoiceWorkflowService::class)->issue($invoice->refresh(), 'Seeded issue', $admin);
    }
}
