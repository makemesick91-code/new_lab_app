<?php

use App\Models\User;
use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LabOrder\Models\LabOrderStatusLog;
use App\Modules\QualityControl\Models\QualityControl;
use App\Modules\QualityControl\Models\QualityControlChecklist;
use App\Modules\QualityControl\Models\RemakeRequest;
use App\Modules\QualityControl\Services\QualityControlService;
use App\Modules\QualityControl\Services\QualityWorkflowService;

$admin = User::where('email', 'admin@asiadentallab.com')->first();
$qcSvc = app(QualityControlService::class);
$flow = app(QualityWorkflowService::class);

// PASS path
$o1 = LabOrder::factory()->create(['status' => 'QC_PENDING']);
$review = $qcSvc->start($o1, 'review start', $admin);
echo 'StartChecklists=' . QualityControlChecklist::where('quality_control_id', $review->id)->count() . PHP_EOL;
$flow->pass($o1->refresh(), 'looks good', $admin);
echo 'PASS status=' . $o1->refresh()->status . ' qcResult=' . QualityControl::find($review->id)->result . PHP_EOL;

// REJECT path
$o2 = LabOrder::factory()->create(['status' => 'QC_PENDING']);
$flow->reject($o2->refresh(), 'REJECTED', 'FIT_ISSUE', 'margins off', $admin);
echo 'REJECT status=' . $o2->refresh()->status . ' remakes=' . RemakeRequest::where('lab_order_id', $o2->id)->count() . PHP_EOL;

// Additional remake request (order in REMAKE)
$flow->requestRemake($o2->refresh(), 'OTHER', 'extra remake note', $admin);
echo 'AfterExtraRemake remakes=' . RemakeRequest::where('lab_order_id', $o2->id)->count() . PHP_EOL;

echo 'O1 audit=' . AuditLog::where('entity_id', $o1->id)->pluck('action')->unique()->implode(',') . PHP_EOL;
echo 'O2 audit=' . AuditLog::where('entity_id', $o2->id)->pluck('action')->unique()->implode(',') . PHP_EOL;
echo 'O1 statusLogs=' . LabOrderStatusLog::where('lab_order_id', $o1->id)->count() . ' O2 statusLogs=' . LabOrderStatusLog::where('lab_order_id', $o2->id)->count() . PHP_EOL;
