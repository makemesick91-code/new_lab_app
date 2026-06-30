<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StressSeedRmeHistoryCommand extends Command
{
    protected $signature = 'stress:seed-rme-history
        {--patients=50000 : Number of stress patients to use}
        {--visits=150000 : Total stress visits target}
        {--chunk=500 : Visit chunk size}
        {--branch-code=TST : Stress branch code}';

    protected $description = 'Seed dummy RME visit history for stress testing. Only runs in APP_ENV=stress.';

    public function handle(): int
    {
        if (! app()->environment('stress')) {
            $this->error('This command only runs in APP_ENV=stress.');
            return self::FAILURE;
        }

        $patientsTarget = max(1, (int) $this->option('patients'));
        $visitsTarget = max(1, (int) $this->option('visits'));
        $requestedChunk = max(50, (int) $this->option('chunk'));
        $chunkSize = min($requestedChunk, 500);
        $branchCode = (string) $this->option('branch-code');

        if ($requestedChunk !== $chunkSize) {
            $this->warn("Requested chunk [{$requestedChunk}] was reduced to safe chunk [{$chunkSize}].");
        }

        $branchId = (int) DB::table('mst_branches')
            ->where('code', $branchCode)
            ->value('id');

        if (! $branchId) {
            $this->error("Branch [{$branchCode}] not found. Run stress:seed-foundation first.");
            return self::FAILURE;
        }

        $adminUserId = (int) DB::table('users')
            ->where('email', 'stress.admin001@daengtisia.test')
            ->value('id');

        $cashierUserId = (int) DB::table('users')
            ->where('email', 'stress.cashier001@daengtisia.test')
            ->value('id');

        if (! $adminUserId || ! $cashierUserId) {
            $this->error('Required stress users not found. Run stress:seed-foundation first.');
            return self::FAILURE;
        }

        $patientIds = DB::table('mst_patients')
            ->where('medical_record_number', 'like', "DG-{$branchCode}-2026-%")
            ->orderBy('id')
            ->limit($patientsTarget)
            ->pluck('id')
            ->values()
            ->all();

        if (count($patientIds) < $patientsTarget) {
            $this->error("Only found ".count($patientIds)." stress patients, but --patients={$patientsTarget}. Run stress:seed-patients first.");
            return self::FAILURE;
        }

        $doctorIds = DB::table('mst_doctors')
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->orderBy('id')
            ->pluck('id')
            ->values()
            ->all();

        $roomIds = DB::table('mst_clinic_rooms')
            ->where('branch_id', $branchId)
            ->where('status', 'active')
            ->orderBy('id')
            ->pluck('id')
            ->values()
            ->all();

        $treatments = DB::table('mst_treatments')
            ->where('is_active', true)
            ->orderBy('id')
            ->get(['id', 'name', 'requires_lab'])
            ->values();

        $tariffsByTreatment = DB::table('mst_tariffs')
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->pluck('price', 'treatment_id')
            ->all();

        $paymentMethodIds = DB::table('mst_payment_methods')
            ->where('is_active', true)
            ->orderBy('id')
            ->pluck('id')
            ->values()
            ->all();

        if (empty($doctorIds) || empty($roomIds) || $treatments->isEmpty() || empty($paymentMethodIds)) {
            $this->error('Required doctors, rooms, treatments, tariffs, or payment methods are missing. Run stress:seed-foundation first.');
            return self::FAILURE;
        }

        $existing = DB::table('trx_clinic_visits')
            ->where('visit_number', 'like', "TST-VIS-2026-%")
            ->count();

        $this->info("RME history target visits : {$visitsTarget}");
        $this->info("Existing stress visits   : {$existing}");
        $this->info("Patients used             : {$patientsTarget}");
        $this->info("Chunk size                : {$chunkSize}");
        $this->info("Branch ID                 : {$branchId}");

        if ($existing >= $visitsTarget) {
            $this->info('Target already reached. No new RME history inserted.');
            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($visitsTarget - $existing);
        $bar->start();

        $start = $existing + 1;

        for ($from = $start; $from <= $visitsTarget; $from += $chunkSize) {
            $to = min($from + $chunkSize - 1, $visitsTarget);

            $visitRows = [];
            $visitNumbers = [];
            $now = now();

            for ($i = $from; $i <= $to; $i++) {
                $seq = str_pad((string) $i, 9, '0', STR_PAD_LEFT);
                $visitNumber = "TST-VIS-2026-{$seq}";
                $visitNumbers[] = $visitNumber;

                $patientId = $patientIds[($i - 1) % count($patientIds)];
                $doctorId = $doctorIds[($i - 1) % count($doctorIds)];
                $roomId = $roomIds[($i - 1) % count($roomIds)];
                $visitDate = now()->subDays($i % 540)->toDateString();

                $billingMode = $this->billingMode($i);
                $visitStatus = $billingMode === 'UNPAID' ? 'cashier_pending' : 'completed';

                $checkedInAt = now()->subDays($i % 540)->setTime(8 + ($i % 8), $i % 60, 0);
                $startedAt = $checkedInAt->copy()->addMinutes(10);
                $completedAt = $visitStatus === 'completed'
                    ? $startedAt->copy()->addMinutes(30)
                    : null;

                $visitRows[] = [
                    'visit_number' => $visitNumber,
                    'branch_id' => $branchId,
                    'clinic_id' => null,
                    'patient_id' => $patientId,
                    'doctor_id' => $doctorId,
                    'clinic_room_id' => $roomId,
                    'visit_date' => $visitDate,
                    'queue_number' => intdiv($i - 1, 540) + 1000,
                    'status' => $visitStatus,
                    'chief_complaint' => 'Dummy keluhan stress test visit ' . $seq,
                    'check_in_at' => $checkedInAt,
                    'started_at' => $startedAt,
                    'completed_at' => $completedAt,
                    'created_by' => $adminUserId,
                    'cancelled_at' => null,
                    'initial_treatment_id' => $treatments[($i - 1) % $treatments->count()]->id,
                    'initial_service_note' => 'Dummy initial note for stress test.',
                    'consent_signed_by_patient' => true,
                    'consent_signed_by_doctor' => true,
                    'consent_verified_at' => $startedAt,
                    'consent_verified_by' => $cashierUserId,
                    'visit_type' => 'new',
                    'follow_up_of_visit_id' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::table('trx_clinic_visits')->insertOrIgnore($visitRows);

            $visits = DB::table('trx_clinic_visits')
                ->whereIn('visit_number', $visitNumbers)
                ->get([
                    'id',
                    'visit_number',
                    'branch_id',
                    'patient_id',
                    'doctor_id',
                    'clinic_room_id',
                    'visit_date',
                    'created_by',
                    'created_at',
                    'updated_at',
                ])
                ->keyBy('visit_number');

            $medicalRecordRows = [];
            $assignmentRows = [];

            foreach ($visitNumbers as $visitNumber) {
                $visit = $visits[$visitNumber] ?? null;

                if (! $visit) {
                    continue;
                }

                $visitSeq = (int) substr($visitNumber, -9);
                $finalizedAt = now()->subDays($visitSeq % 540)->setTime(9 + ($visitSeq % 8), $visitSeq % 60, 0);

                $medicalRecordRows[] = [
                    'clinic_visit_id' => $visit->id,
                    'branch_id' => $visit->branch_id,
                    'patient_id' => $visit->patient_id,
                    'doctor_id' => $visit->doctor_id,
                    'subjective' => 'Dummy subjective stress test.',
                    'objective' => 'Dummy objective stress test.',
                    'assessment' => 'Dummy assessment stress test.',
                    'plan' => 'Dummy plan stress test.',
                    'notes' => 'Dummy finalized medical record for stress testing.',
                    'status' => 'final',
                    'recorded_by' => $visit->doctor_id,
                    'finalized_at' => $finalizedAt,
                    'finalized_by' => $visit->doctor_id,
                    'canonical_visit_id' => $visit->id,
                    'source_visit_id' => $visit->id,
                    'sheet_number' => intdiv($visitSeq - 1, count($patientIds)) + 1,
                    'created_at' => $visit->created_at,
                    'updated_at' => $visit->updated_at,
                ];

                $assignmentRows[] = [
                    'patient_id' => $visit->patient_id,
                    'doctor_id' => $visit->doctor_id,
                    'from_doctor_id' => null,
                    'branch_id' => $visit->branch_id,
                    'source_visit_id' => $visit->id,
                    'assigned_by' => $adminUserId,
                    'assigned_at' => $visit->created_at,
                    'unassigned_at' => null,
                    'assignment_type' => 'auto_visit',
                    'reason' => 'Dummy auto doctor assignment for stress testing.',
                    'notes' => null,
                    'created_at' => $visit->created_at,
                    'updated_at' => $visit->updated_at,
                ];
            }

            DB::table('trx_medical_records')->insertOrIgnore($medicalRecordRows);
            DB::table('trx_rme_patient_doctor_assignments')->insertOrIgnore($assignmentRows);

            $visitIds = $visits->pluck('id')->all();

            $medicalRecords = DB::table('trx_medical_records')
                ->whereIn('clinic_visit_id', $visitIds)
                ->get(['id', 'clinic_visit_id', 'branch_id', 'patient_id', 'doctor_id', 'created_at', 'updated_at'])
                ->keyBy('clinic_visit_id');

            $handwritingRows = [];
            $odontogramRows = [];
            $invoiceRows = [];

            foreach ($visits as $visit) {
                $record = $medicalRecords[$visit->id] ?? null;

                if (! $record) {
                    continue;
                }

                $visitSeq = (int) substr($visit->visit_number, -9);
                $invoiceNumber = 'TST-RME-INV-2026-' . str_pad((string) $visitSeq, 9, '0', STR_PAD_LEFT);

                $invoiceTotal = $this->invoiceTotal($visitSeq, $treatments, $tariffsByTreatment);
                $invoiceStatus = $this->billingMode($visitSeq);

                $handwritingRows[] = [
                    'medical_record_id' => $record->id,
                    'clinic_visit_id' => $visit->id,
                    'branch_id' => $visit->branch_id,
                    'doctor_id' => $visit->doctor_id,
                    'page_number' => 1,
                    'handwriting_path' => "stress/rme/handwriting/{$visit->visit_number}.png",
                    'handwriting_hash' => sha1($visit->visit_number),
                    'saved_at' => $visit->updated_at,
                    'created_by' => $visit->doctor_id,
                    'updated_by' => $visit->doctor_id,
                    'created_at' => $visit->created_at,
                    'updated_at' => $visit->updated_at,
                ];

                $odontogramRows[] = [
                    'clinic_visit_id' => $visit->id,
                    'branch_id' => $visit->branch_id,
                    'medical_record_id' => $record->id,
                    'status' => 'final',
                    'summary_notes' => 'Dummy odontogram summary for stress testing.',
                    'tooth_map_payload' => json_encode([
                        'source' => 'stress_test',
                        'teeth' => [
                            '11' => ['condition' => 'normal'],
                            '21' => ['condition' => 'normal'],
                            '36' => ['condition' => 'caries'],
                        ],
                    ]),
                    'created_by' => $visit->doctor_id,
                    'updated_by' => $visit->doctor_id,
                    'finalized_at' => $visit->updated_at,
                    'finalized_by' => $visit->doctor_id,
                    'additional_conditions' => 'Dummy additional odontogram condition.',
                    'created_at' => $visit->created_at,
                    'updated_at' => $visit->updated_at,
                ];

                $invoiceRows[] = [
                    'branch_id' => $visit->branch_id,
                    'clinic_visit_id' => $visit->id,
                    'patient_id' => $visit->patient_id,
                    'medical_record_id' => $record->id,
                    'cashier_id' => $cashierUserId,
                    'invoice_number' => $invoiceNumber,
                    'status' => $invoiceStatus,
                    'subtotal' => $invoiceTotal,
                    'discount_total' => 0,
                    'grand_total' => $invoiceTotal,
                    'notes' => 'Dummy RME invoice for stress testing.',
                    'created_at' => $visit->created_at,
                    'updated_at' => $visit->updated_at,
                ];
            }

            DB::table('trx_medical_record_handwriting_pages')->insertOrIgnore($handwritingRows);
            DB::table('trx_odontograms')->insertOrIgnore($odontogramRows);
            DB::table('trx_rme_invoices')->insertOrIgnore($invoiceRows);

            $invoiceNumbers = array_map(function ($visitNumber) {
                $visitSeq = (int) substr($visitNumber, -9);
                return 'TST-RME-INV-2026-' . str_pad((string) $visitSeq, 9, '0', STR_PAD_LEFT);
            }, $visitNumbers);

            $invoices = DB::table('trx_rme_invoices')
                ->whereIn('invoice_number', $invoiceNumbers)
                ->get(['id', 'branch_id', 'clinic_visit_id', 'patient_id', 'medical_record_id', 'cashier_id', 'invoice_number', 'status', 'grand_total', 'created_at', 'updated_at'])
                ->keyBy('invoice_number');

            $invoiceItemRows = [];
            $paymentRows = [];
            $followUpRows = [];

            foreach ($invoices as $invoice) {
                $visitSeq = (int) substr($invoice->invoice_number, -9);
                $visit = $visits->firstWhere('id', $invoice->clinic_visit_id);

                if (! $visit) {
                    continue;
                }

                for ($itemIndex = 1; $itemIndex <= 3; $itemIndex++) {
                    $treatment = $treatments[($visitSeq + $itemIndex - 2) % $treatments->count()];
                    $unitPrice = (float) ($tariffsByTreatment[$treatment->id] ?? 100000);
                    $description = "Stress item {$itemIndex} {$visit->visit_number} - {$treatment->name}";

                    $invoiceItemRows[] = [
                        'rme_invoice_id' => $invoice->id,
                        'treatment_id' => $treatment->id,
                        'description' => $description,
                        'qty' => 1,
                        'unit_price' => $unitPrice,
                        'discount' => 0,
                        'subtotal' => $unitPrice,
                        'doctor_id' => $visit->doctor_id,
                        'created_at' => $invoice->created_at,
                        'updated_at' => $invoice->updated_at,
                    ];
                }

                if (in_array($invoice->status, ['PAID', 'PARTIAL'], true)) {
                    $paidAmount = $invoice->status === 'PAID'
                        ? (float) $invoice->grand_total
                        : round(((float) $invoice->grand_total) * 0.5, 2);

                    $paymentRows[] = [
                        'branch_id' => $invoice->branch_id,
                        'rme_invoice_id' => $invoice->id,
                        'clinic_visit_id' => $invoice->clinic_visit_id,
                        'patient_id' => $invoice->patient_id,
                        'cashier_id' => $invoice->cashier_id,
                        'payment_method_id' => $paymentMethodIds[($visitSeq - 1) % count($paymentMethodIds)],
                        'payment_number' => 'TST-RME-PAY-2026-' . str_pad((string) $visitSeq, 9, '0', STR_PAD_LEFT),
                        'amount' => $paidAmount,
                        'paid_at' => $invoice->updated_at,
                        'reference_number' => 'STRESS-REF-' . str_pad((string) $visitSeq, 9, '0', STR_PAD_LEFT),
                        'notes' => 'Dummy RME payment for stress testing.',
                        'payment_batch_uuid' => (string) Str::uuid(),
                        'created_at' => $invoice->created_at,
                        'updated_at' => $invoice->updated_at,
                    ];
                }

                if (in_array($invoice->status, ['PARTIAL', 'UNPAID'], true) && in_array($visitSeq % 10, [8, 9], true)) {
                    $followUpRows[] = [
                        'rme_invoice_id' => $invoice->id,
                        'branch_id' => $invoice->branch_id,
                        'user_id' => $invoice->cashier_id,
                        'status' => 'contacted',
                        'channel' => 'manual',
                        'contacted_at' => $invoice->updated_at,
                        'next_follow_up_date' => now()->addDays(7 + ($visitSeq % 14))->toDateString(),
                        'note' => 'Dummy receivable follow-up for stress testing.',
                        'created_at' => $invoice->created_at,
                        'updated_at' => $invoice->updated_at,
                    ];
                }
            }

            DB::table('trx_rme_invoice_items')->insertOrIgnore($invoiceItemRows);
            DB::table('trx_rme_payments')->insertOrIgnore($paymentRows);
            DB::table('trx_rme_receivable_follow_ups')->insertOrIgnore($followUpRows);

            $this->seedLabCandidates($invoices, $visits, $medicalRecords, $branchId, $adminUserId);

            $bar->advance($to - $from + 1);
        }

        $bar->finish();
        $this->newLine(2);

        $this->table(
            ['table', 'count'],
            [
                ['mst_patients', DB::table('mst_patients')->count()],
                ['trx_clinic_visits', DB::table('trx_clinic_visits')->count()],
                ['trx_medical_records', DB::table('trx_medical_records')->count()],
                ['trx_medical_record_handwriting_pages', DB::table('trx_medical_record_handwriting_pages')->count()],
                ['trx_odontograms', DB::table('trx_odontograms')->count()],
                ['trx_rme_invoices', DB::table('trx_rme_invoices')->count()],
                ['trx_rme_invoice_items', DB::table('trx_rme_invoice_items')->count()],
                ['trx_rme_payments', DB::table('trx_rme_payments')->count()],
                ['trx_rme_receivable_follow_ups', DB::table('trx_rme_receivable_follow_ups')->count()],
                ['trx_lab_case_candidates', DB::table('trx_lab_case_candidates')->count()],
            ]
        );

        return self::SUCCESS;
    }

    private function billingMode(int $visitSeq): string
    {
        $mod = $visitSeq % 10;

        if ($mod <= 6) {
            return 'PAID';
        }

        if ($mod <= 8) {
            return 'PARTIAL';
        }

        return 'UNPAID';
    }

    private function invoiceTotal(int $visitSeq, $treatments, array $tariffsByTreatment): float
    {
        $total = 0;

        for ($itemIndex = 1; $itemIndex <= 3; $itemIndex++) {
            $treatment = $treatments[($visitSeq + $itemIndex - 2) % $treatments->count()];
            $total += (float) ($tariffsByTreatment[$treatment->id] ?? 100000);
        }

        return $total;
    }

    private function seedLabCandidates($invoices, $visits, $medicalRecords, int $branchId, int $adminUserId): void
    {
        $invoiceIds = $invoices->pluck('id')->all();

        if (empty($invoiceIds)) {
            return;
        }

        $labTreatmentIds = DB::table('mst_treatments')
            ->where('requires_lab', true)
            ->pluck('id')
            ->all();

        if (empty($labTreatmentIds)) {
            return;
        }

        $items = DB::table('trx_rme_invoice_items')
            ->whereIn('rme_invoice_id', $invoiceIds)
            ->whereIn('treatment_id', $labTreatmentIds)
            ->get(['id', 'rme_invoice_id', 'treatment_id', 'description', 'qty', 'unit_price']);

        if ($items->isEmpty()) {
            return;
        }

        $invoiceById = $invoices->keyBy('id');
        $medicalRecordByVisitId = $medicalRecords->keyBy('clinic_visit_id');

        $rows = [];

        foreach ($items as $item) {
            $invoice = $invoiceById[$item->rme_invoice_id] ?? null;

            if (! $invoice) {
                continue;
            }

            $visit = $visits->firstWhere('id', $invoice->clinic_visit_id);
            $record = $medicalRecordByVisitId[$invoice->clinic_visit_id] ?? null;

            if (! $visit || ! $record) {
                continue;
            }

            $rows[] = [
                'branch_id' => $branchId,
                'clinic_visit_id' => $invoice->clinic_visit_id,
                'rme_invoice_id' => $invoice->id,
                'rme_invoice_item_id' => $item->id,
                'patient_id' => $invoice->patient_id,
                'doctor_id' => $visit->doctor_id,
                'treatment_id' => $item->treatment_id,
                'medical_record_id' => $record->id,
                'source_description' => $item->description,
                'quantity' => $item->qty,
                'estimated_price' => $item->unit_price,
                'status' => 'pending_review',
                'converted_lab_order_id' => null,
                'reviewed_by' => null,
                'reviewed_at' => null,
                'notes' => 'Dummy lab candidate generated for stress testing.',
                'metadata' => json_encode(['source' => 'stress_test']),
                'created_by' => $adminUserId,
                'created_at' => $invoice->created_at,
                'updated_at' => $invoice->updated_at,
            ];
        }

        if (! empty($rows)) {
            DB::table('trx_lab_case_candidates')->insertOrIgnore($rows);
        }
    }
}
