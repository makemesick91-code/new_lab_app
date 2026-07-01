<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StressSeedRmeHistoryCommand extends Command
{
    protected $signature = 'stress:seed-rme-history
        {--target= : Total stress visits target (preferred)}
        {--visits=150000 : Deprecated alias for --target}
        {--patients= : Max stress patients to cycle (default: min(target, available))}
        {--chunk-size= : Visit chunk size; clamped to a PostgreSQL-safe maximum}
        {--chunk=500 : Deprecated alias for --chunk-size}
        {--branch-code=TST : Stress branch code}
        {--from-date=2026-01-01 : Visit date range start (Y-m-d)}
        {--to-date=2026-12-31 : Visit date range end (Y-m-d)}
        {--paid-ratio=70 : Percentage of invoices marked PAID}
        {--partial-ratio=20 : Percentage of invoices marked PARTIAL}
        {--unpaid-ratio=10 : Percentage of invoices marked UNPAID}
        {--dry-run : Validate the plan without writing to the database}
        {--force : Required when --target exceeds 100000}';

    protected $description = 'Seed dummy RME visit history for stress testing. Only runs in local/stress/testing.';

    private const ALLOWED_ENVIRONMENTS = ['local', 'stress', 'testing'];

    private const PARAMETER_BUDGET = 60000;

    private const DEFAULT_CHUNK_SIZE = 1000;

    private const HIGH_TARGET_THRESHOLD = 100000;

    /** @var array{paid: int, partial: int, unpaid: int} */
    private array $ratioBuckets = ['paid' => 70, 'partial' => 20, 'unpaid' => 10];

    public function handle(): int
    {
        if (! app()->environment(self::ALLOWED_ENVIRONMENTS)) {
            $this->error('This command only runs in local, stress, or testing environments (never production/pilot).');

            return self::FAILURE;
        }

        $target = max(1, $this->resolveTarget());
        $dryRun = (bool) $this->option('dry-run');
        $branchCode = (string) $this->option('branch-code');
        $force = (bool) $this->option('force');

        if ($target > self::HIGH_TARGET_THRESHOLD && ! $force) {
            $this->error("Target [{$target}] exceeds ".self::HIGH_TARGET_THRESHOLD.'. Re-run with --force to acknowledge large growth.');

            return self::FAILURE;
        }

        $ratios = $this->resolveRatios();
        if ($ratios === null) {
            return self::FAILURE;
        }
        $this->ratioBuckets = $ratios;

        try {
            $fromDate = $this->parseDateOption('from-date', '2026-01-01');
            $toDate = $this->parseDateOption('to-date', '2026-12-31');
        } catch (\InvalidArgumentException) {
            return self::FAILURE;
        }

        if ($fromDate->gt($toDate)) {
            $this->error('--from-date must be on or before --to-date.');

            return self::FAILURE;
        }

        $visitColumnCount = count($this->sampleVisitRow());
        $invoiceItemColumnCount = count($this->sampleInvoiceItemRow());
        $safeVisitChunk = max(1, (int) floor(self::PARAMETER_BUDGET / max(1, $visitColumnCount)));
        $safeItemChunk = max(1, (int) floor(self::PARAMETER_BUDGET / max(1, $invoiceItemColumnCount * 3)));
        $safeChunkSize = min($safeVisitChunk, $safeItemChunk);

        $requestedChunkSize = max(1, $this->resolveRequestedChunkSize());
        $chunkSize = min($requestedChunkSize, $safeChunkSize);

        $dbName = (string) config('database.connections.'.config('database.default').'.database');

        $this->info('APP_ENV                 : '.app()->environment());
        $this->info('DB name                 : '.$dbName);
        $this->info('RME history target      : '.$target);
        $this->info('Visit columns per row   : '.$visitColumnCount);
        $this->info('Safe max visit chunk    : '.$safeChunkSize.' (budget '.self::PARAMETER_BUDGET.')');
        $this->info('Requested chunk         : '.$requestedChunkSize);
        $this->info('Effective chunk size    : '.$chunkSize);
        $this->info('Params per visit chunk  : '.($chunkSize * $visitColumnCount));
        $this->info('Date range              : '.$fromDate->toDateString().' → '.$toDate->toDateString());
        $this->info('Invoice ratios (%)      : PAID '.$this->ratioBuckets['paid'].', PARTIAL '.$this->ratioBuckets['partial'].', UNPAID '.$this->ratioBuckets['unpaid']);

        if ($requestedChunkSize !== $chunkSize) {
            $this->warn("Requested chunk [{$requestedChunkSize}] clamped to safe chunk [{$chunkSize}] to stay under the PostgreSQL parameter limit.");
        }

        $branchId = (int) DB::table('mst_branches')
            ->where('code', $branchCode)
            ->value('id');

        if (! $branchId) {
            if ($dryRun) {
                $this->warn("Branch [{$branchCode}] not found. Run php artisan stress:seed-foundation first (dry-run continues).");

                return self::SUCCESS;
            }

            $this->error("Branch [{$branchCode}] not found. Run stress:seed-foundation first.");

            return self::FAILURE;
        }

        $foundation = $this->resolveFoundation($branchId);
        if ($foundation === null) {
            if ($dryRun) {
                $this->warn('Required stress foundation data is missing (dry-run continues).');

                return self::SUCCESS;
            }

            return self::FAILURE;
        }

        $availablePatients = DB::table('mst_patients')
            ->where('medical_record_number', 'like', "DG-{$branchCode}-2026-%")
            ->count();

        $patientsTarget = $this->resolvePatientsTarget($target, $availablePatients);

        if ($availablePatients < 1) {
            $this->error('No stress patients found. Run stress:seed-patients first.');

            return self::FAILURE;
        }

        if ($patientsTarget > $availablePatients) {
            $this->error("Only found {$availablePatients} stress patients, but --patients={$patientsTarget}. Run stress:seed-patients first.");

            return self::FAILURE;
        }

        $patientIds = DB::table('mst_patients')
            ->where('medical_record_number', 'like', "DG-{$branchCode}-2026-%")
            ->orderBy('id')
            ->limit($patientsTarget)
            ->pluck('id')
            ->values()
            ->all();

        $existingCount = DB::table('trx_clinic_visits')
            ->where('visit_number', 'like', 'TST-VIS-2026-%')
            ->count();

        $existingMaxSeq = (int) DB::table('trx_clinic_visits')
            ->where('visit_number', 'like', 'TST-VIS-2026-%')
            ->selectRaw('COALESCE(MAX(CAST(RIGHT(visit_number, 9) AS INTEGER)), 0) AS max_seq')
            ->value('max_seq');

        $remaining = max(0, $target - $existingMaxSeq);

        $this->info('Branch ID               : '.$branchId);
        $this->info('Existing stress visits  : '.$existingCount.' (max seq '.$existingMaxSeq.')');
        $this->info('Remaining to generate   : '.$remaining);
        $this->info('Patients used           : '.$patientsTarget);

        if ($existingMaxSeq >= $target) {
            $this->info('Target already reached. No new RME history inserted.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->info('[dry-run] Plan validated. No rows written.');

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($remaining);
        $bar->start();

        $start = $existingMaxSeq + 1;
        $attempted = 0;
        $insertedVisits = 0;

        for ($from = $start; $from <= $target; $from += $chunkSize) {
            $to = min($from + $chunkSize - 1, $target);

            $visitRows = [];
            $visitNumbers = [];
            $now = now();

            for ($i = $from; $i <= $to; $i++) {
                $seq = str_pad((string) $i, 9, '0', STR_PAD_LEFT);
                $visitNumber = "TST-VIS-2026-{$seq}";
                $visitNumbers[] = $visitNumber;

                $patientId = $patientIds[($i - 1) % count($patientIds)];
                $doctorId = $foundation['doctorIds'][($i - 1) % count($foundation['doctorIds'])];
                $roomId = $foundation['roomIds'][($i - 1) % count($foundation['roomIds'])];
                $visitDate = $this->visitDateForSeq($i, $fromDate, $toDate);

                $billingMode = $this->billingMode($i);
                $visitStatus = $billingMode === 'UNPAID' ? 'cashier_pending' : 'completed';

                $checkedInAt = $visitDate->copy()->setTime(8 + ($i % 8), $i % 60, 0);
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
                    'visit_date' => $visitDate->toDateString(),
                    'queue_number' => $i,
                    'status' => $visitStatus,
                    'chief_complaint' => 'Dummy keluhan stress test visit '.$seq,
                    'check_in_at' => $checkedInAt,
                    'started_at' => $startedAt,
                    'completed_at' => $completedAt,
                    'created_by' => $foundation['adminUserId'],
                    'cancelled_at' => null,
                    'initial_treatment_id' => $foundation['treatments'][($i - 1) % count($foundation['treatments'])]->id,
                    'initial_service_note' => 'Dummy initial note for stress test.',
                    'consent_signed_by_patient' => true,
                    'consent_signed_by_doctor' => true,
                    'consent_verified_at' => $startedAt,
                    'consent_verified_by' => $foundation['cashierUserId'],
                    'visit_type' => 'new',
                    'follow_up_of_visit_id' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            $attempted += count($visitRows);
            $insertedVisits += DB::table('trx_clinic_visits')->insertOrIgnore($visitRows);

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
                $finalizedAt = $this->visitDateForSeq($visitSeq, $fromDate, $toDate)->setTime(9 + ($visitSeq % 8), $visitSeq % 60, 0);
                $doctorUserId = (int) ($foundation['doctorUserById'][$visit->doctor_id] ?? $foundation['adminUserId']);

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
                    'recorded_by' => $doctorUserId,
                    'finalized_at' => $finalizedAt,
                    'finalized_by' => $doctorUserId,
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
                    'assigned_by' => $foundation['adminUserId'],
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
                $invoiceNumber = 'TST-RME-INV-2026-'.str_pad((string) $visitSeq, 9, '0', STR_PAD_LEFT);

                $invoiceTotal = $this->invoiceTotal($visitSeq, $foundation['treatments'], $foundation['tariffsByTreatment']);
                $invoiceStatus = $this->billingMode($visitSeq);
                $doctorUserId = (int) ($foundation['doctorUserById'][$visit->doctor_id] ?? $foundation['adminUserId']);

                $handwritingRows[] = [
                    'medical_record_id' => $record->id,
                    'clinic_visit_id' => $visit->id,
                    'branch_id' => $visit->branch_id,
                    'doctor_id' => $visit->doctor_id,
                    'page_number' => 1,
                    'handwriting_path' => "stress/rme/handwriting/{$visit->visit_number}.png",
                    'handwriting_hash' => sha1($visit->visit_number),
                    'saved_at' => $visit->updated_at,
                    'created_by' => $doctorUserId,
                    'updated_by' => $doctorUserId,
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
                    'created_by' => $doctorUserId,
                    'updated_by' => $doctorUserId,
                    'finalized_at' => $visit->updated_at,
                    'finalized_by' => $doctorUserId,
                    'additional_conditions' => 'Dummy additional odontogram condition.',
                    'created_at' => $visit->created_at,
                    'updated_at' => $visit->updated_at,
                ];

                $invoiceRows[] = [
                    'branch_id' => $visit->branch_id,
                    'clinic_visit_id' => $visit->id,
                    'patient_id' => $visit->patient_id,
                    'medical_record_id' => $record->id,
                    'cashier_id' => $foundation['cashierUserId'],
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

                return 'TST-RME-INV-2026-'.str_pad((string) $visitSeq, 9, '0', STR_PAD_LEFT);
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
                    $treatment = $foundation['treatments'][($visitSeq + $itemIndex - 2) % count($foundation['treatments'])];
                    $unitPrice = (float) ($foundation['tariffsByTreatment'][$treatment->id] ?? 100000);
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
                        'payment_method_id' => $foundation['paymentMethodIds'][($visitSeq - 1) % count($foundation['paymentMethodIds'])],
                        'payment_number' => 'TST-RME-PAY-2026-'.str_pad((string) $visitSeq, 9, '0', STR_PAD_LEFT),
                        'amount' => $paidAmount,
                        'paid_at' => $invoice->updated_at,
                        'reference_number' => 'STRESS-REF-'.str_pad((string) $visitSeq, 9, '0', STR_PAD_LEFT),
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

            $this->seedLabCandidates($invoices, $visits, $medicalRecords, $branchId, $foundation['adminUserId']);

            $bar->advance($to - $from + 1);
        }

        $bar->finish();
        $this->newLine(2);

        $skipped = max(0, $attempted - $insertedVisits);
        $this->info("Attempted visits : {$attempted}");
        $this->info("Inserted visits  : {$insertedVisits}");
        $this->info("Skipped visits   : {$skipped} (existing / duplicate)");

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

    private function resolveTarget(): int
    {
        $target = $this->option('target');
        if ($target !== null && $target !== '') {
            return (int) $target;
        }

        $this->warn('--visits is deprecated; use --target.');

        return (int) $this->option('visits');
    }

    private function resolveRequestedChunkSize(): int
    {
        $chunkSize = $this->option('chunk-size');
        if ($chunkSize !== null && $chunkSize !== '') {
            return (int) $chunkSize;
        }

        $legacy = $this->option('chunk');
        if ($legacy !== null && $legacy !== '') {
            $this->warn('--chunk is deprecated; use --chunk-size.');

            return max(1, (int) $legacy);
        }

        return self::DEFAULT_CHUNK_SIZE;
    }

    private function resolvePatientsTarget(int $target, int $available): int
    {
        $patients = $this->option('patients');
        if ($patients !== null && $patients !== '') {
            return max(1, (int) $patients);
        }

        return min($target, max(1, $available));
    }

    /**
     * @return array{paid: int, partial: int, unpaid: int}|null
     */
    private function resolveRatios(): ?array
    {
        $paid = max(0, (int) $this->option('paid-ratio'));
        $partial = max(0, (int) $this->option('partial-ratio'));
        $unpaid = max(0, (int) $this->option('unpaid-ratio'));
        $sum = $paid + $partial + $unpaid;

        if ($sum === 0) {
            $this->error('Invoice ratios must sum to a positive value.');

            return null;
        }

        if ($sum !== 100) {
            $this->warn("Invoice ratios sum to {$sum}; normalizing to 100.");
            $paid = (int) round($paid / $sum * 100);
            $partial = (int) round($partial / $sum * 100);
            $unpaid = 100 - $paid - $partial;
        }

        return ['paid' => $paid, 'partial' => $partial, 'unpaid' => $unpaid];
    }

    private function parseDateOption(string $option, string $fallback): Carbon
    {
        $value = (string) ($this->option($option) ?: $fallback);

        try {
            return Carbon::parse($value)->startOfDay();
        } catch (\Throwable) {
            $this->error("Invalid --{$option} value [{$value}]. Expected Y-m-d.");

            throw new \InvalidArgumentException("Invalid date for --{$option}");
        }
    }

    private function visitDateForSeq(int $visitSeq, Carbon $fromDate, Carbon $toDate): Carbon
    {
        $days = max(0, $fromDate->diffInDays($toDate));

        if ($days === 0) {
            return $fromDate->copy();
        }

        $offset = ($visitSeq - 1) % ($days + 1);

        return $fromDate->copy()->addDays($offset);
    }

    private function billingMode(int $visitSeq): string
    {
        $slot = ($visitSeq - 1) % 100;
        $paidEnd = $this->ratioBuckets['paid'];
        $partialEnd = $paidEnd + $this->ratioBuckets['partial'];

        if ($slot < $paidEnd) {
            return 'PAID';
        }

        if ($slot < $partialEnd) {
            return 'PARTIAL';
        }

        return 'UNPAID';
    }

    /**
     * @return array<string, mixed>
     */
    private function sampleVisitRow(): array
    {
        return [
            'visit_number' => 'TST-VIS-2026-000000001',
            'branch_id' => 1,
            'clinic_id' => null,
            'patient_id' => 1,
            'doctor_id' => 1,
            'clinic_room_id' => 1,
            'visit_date' => '2026-01-01',
            'queue_number' => 1000,
            'status' => 'completed',
            'chief_complaint' => 'dummy',
            'check_in_at' => now(),
            'started_at' => now(),
            'completed_at' => now(),
            'created_by' => 1,
            'cancelled_at' => null,
            'initial_treatment_id' => 1,
            'initial_service_note' => 'dummy',
            'consent_signed_by_patient' => true,
            'consent_signed_by_doctor' => true,
            'consent_verified_at' => now(),
            'consent_verified_by' => 1,
            'visit_type' => 'new',
            'follow_up_of_visit_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sampleInvoiceItemRow(): array
    {
        return [
            'rme_invoice_id' => 1,
            'treatment_id' => 1,
            'description' => 'dummy',
            'qty' => 1,
            'unit_price' => 100000,
            'discount' => 0,
            'subtotal' => 100000,
            'doctor_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * @return array{
     *     adminUserId: int,
     *     cashierUserId: int,
     *     doctorIds: array<int>,
     *     roomIds: array<int>,
     *     treatments: Collection,
     *     tariffsByTreatment: array<int, float>,
     *     paymentMethodIds: array<int>
     * }|null
     */
    private function resolveFoundation(int $branchId): ?array
    {
        $adminUserId = (int) DB::table('users')
            ->where('email', 'stress.admin001@daengtisia.test')
            ->value('id');

        $cashierUserId = (int) DB::table('users')
            ->where('email', 'stress.cashier001@daengtisia.test')
            ->value('id');

        if (! $adminUserId || ! $cashierUserId) {
            $this->error('Required stress users not found. Run stress:seed-foundation first.');

            return null;
        }

        $doctorIds = DB::table('mst_doctors')
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->orderBy('id')
            ->pluck('id')
            ->values()
            ->all();

        $doctorUserById = DB::table('mst_doctors')
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->whereNotNull('user_id')
            ->pluck('user_id', 'id')
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

        if (empty($doctorIds) || empty($roomIds) || $treatments->isEmpty() || empty($paymentMethodIds) || empty($doctorUserById)) {
            $this->error('Required doctors, rooms, treatments, tariffs, or payment methods are missing. Run stress:seed-foundation first.');

            return null;
        }

        return [
            'adminUserId' => $adminUserId,
            'cashierUserId' => $cashierUserId,
            'doctorIds' => $doctorIds,
            'doctorUserById' => $doctorUserById,
            'roomIds' => $roomIds,
            'treatments' => $treatments,
            'tariffsByTreatment' => $tariffsByTreatment,
            'paymentMethodIds' => $paymentMethodIds,
        ];
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
