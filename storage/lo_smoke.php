<?php

use App\Models\User;
use App\Modules\Clinic\Models\Clinic;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\LabService\Models\LabService;
use App\Modules\Patient\Models\Patient;
use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\LabOrder\Models\LabOrderStatusLog;
use App\Modules\LabOrder\Services\LabOrderService;

$admin = User::where('email', 'admin@asiadentallab.com')->first();
$clinic = Clinic::first();
$doctor = Doctor::where('clinic_id', $clinic->id)->first();
$patient = Patient::first();
$service = LabService::first();

$svc = app(LabOrderService::class);
$order = $svc->create([
    'clinic_id' => $clinic->id,
    'doctor_id' => $doctor->id,
    'patient_id' => $patient->id,
    'due_date' => now()->addDays(5)->toDateString(),
    'priority' => 'NORMAL',
    'items' => [
        ['lab_service_id' => $service->id, 'quantity' => 2, 'unit_price' => 1500000],
    ],
], $admin);

echo 'OrderNumber=' . $order->order_number . PHP_EOL;
echo 'Status=' . $order->status . PHP_EOL;
echo 'Items=' . $order->items()->count() . ' Subtotal=' . $order->items()->first()->subtotal . PHP_EOL;
echo 'StatusLogs=' . LabOrderStatusLog::where('lab_order_id', $order->id)->count() . PHP_EOL;
echo 'AuditCreate=' . AuditLog::where('entity_type', 'trx_lab_orders')->where('entity_id', $order->id)->where('action', 'CREATE')->count() . PHP_EOL;

$svc->cancel($order, 'Smoke test cancel reason', $admin);
echo 'AfterCancelStatus=' . $order->refresh()->status . PHP_EOL;
echo 'AuditCancel=' . AuditLog::where('entity_id', $order->id)->where('action', 'CANCEL')->count() . PHP_EOL;
echo 'MorphAttachEntityType=' . $order->getMorphClass() . PHP_EOL;
