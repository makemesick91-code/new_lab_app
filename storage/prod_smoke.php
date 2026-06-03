<?php

use App\Models\User;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\LabOrder\Models\LabOrderStatusLog;
use App\Modules\Production\Models\LabOrderAssignment;
use App\Modules\Production\Models\ProductionStep;
use App\Modules\Production\Models\WorkLog;
use App\Modules\Production\Services\AssignmentService;
use App\Modules\Production\Services\ProductionWorkflowService;
use App\Modules\Technician\Models\Technician;

$admin = User::where('email', 'admin@asiadentallab.com')->first();
$order = LabOrder::factory()->create(['status' => 'RECEIVED']);
$tech = Technician::first();
$tech2 = Technician::skip(1)->first();

$assignSvc = app(AssignmentService::class);
$flow = app(ProductionWorkflowService::class);

$assignment = $assignSvc->assign($order, $tech->id, 'Please handle', $admin);
echo 'AfterAssign status=' . $order->refresh()->status . ' assignment=' . $assignment->status . ' steps=' . ProductionStep::where('lab_order_id', $order->id)->count() . PHP_EOL;

$re = $assignSvc->reassign($order->refresh(), $tech2->id, 'Tech unavailable', $admin);
echo 'Reassign newStatus=' . $re->status . ' oldNow=' . LabOrderAssignment::find($assignment->id)->status . PHP_EOL;

$flow->startWork($order->refresh(), 'starting', $admin);
echo 'AfterStart=' . $order->refresh()->status . PHP_EOL;
$flow->pauseWork($order->refresh(), 'waiting material', 'WAITING_MATERIAL', $admin);
echo 'AfterPause=' . $order->refresh()->status . PHP_EOL;
$flow->resumeWork($order->refresh(), 'resumed', $admin);
echo 'AfterResume=' . $order->refresh()->status . PHP_EOL;
$flow->completeWork($order->refresh(), 'done', $admin);
echo 'AfterCompleteLatestAssignment=' . LabOrderAssignment::where('lab_order_id', $order->id)->orderByDesc('id')->first()->status . PHP_EOL;
$flow->sendToQc($order->refresh(), 'to qc', $admin);
echo 'AfterSendToQc=' . $order->refresh()->status . PHP_EOL;

echo 'WorkLogs=' . WorkLog::whereHas('assignment', fn($q) => $q->where('lab_order_id', $order->id))->count() . PHP_EOL;
echo 'StatusLogs=' . LabOrderStatusLog::where('lab_order_id', $order->id)->count() . PHP_EOL;
echo 'AuditActions=' . AuditLog::where('entity_id', $order->id)->pluck('action')->unique()->implode(',') . PHP_EOL;
