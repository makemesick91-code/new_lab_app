<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class StressSeedPatientsCommand extends Command
{
    protected $signature = 'stress:seed-patients
        {--target=10000 : Total stress patients target}
        {--chunk-size= : Insert chunk size (rows per insert); clamped to a PostgreSQL-safe maximum}
        {--chunk= : Deprecated alias for --chunk-size}
        {--branch-code=TST : Stress branch code}
        {--dry-run : Validate the plan and print the effective chunk size without writing to the database}';

    protected $description = 'Seed dummy patients for stress testing. Only runs in local/stress/testing.';

    /**
     * Environments where stress seeding is permitted. Never production or pilot.
     */
    private const ALLOWED_ENVIRONMENTS = ['local', 'stress', 'testing'];

    /**
     * Conservative parameter budget per insert. PostgreSQL/PDO caps bound
     * parameters at 65,535; we stay well under that for safety.
     */
    private const PARAMETER_BUDGET = 60000;

    private const DEFAULT_CHUNK_SIZE = 1000;

    public function handle(): int
    {
        if (! app()->environment(self::ALLOWED_ENVIRONMENTS)) {
            $this->error('This command only runs in local, stress, or testing environments (never production/pilot).');

            return self::FAILURE;
        }

        $target = max(1, (int) $this->option('target'));
        $dryRun = (bool) $this->option('dry-run');
        $branchCode = (string) $this->option('branch-code');

        $columnCount = count($this->buildRow(1, $branchCode, 0, now()));
        $safeChunkSize = max(1, (int) floor(self::PARAMETER_BUDGET / max(1, $columnCount)));

        $requestedChunkSize = max(1, $this->resolveRequestedChunkSize());
        $chunkSize = min($requestedChunkSize, $safeChunkSize);

        $this->info("Stress patient target : {$target}");
        $this->info("Columns per row       : {$columnCount}");
        $this->info("Safe max chunk        : {$safeChunkSize} (budget ".self::PARAMETER_BUDGET.')');
        $this->info("Requested chunk       : {$requestedChunkSize}");
        $this->info("Effective chunk size  : {$chunkSize}");
        $this->info('Params per full chunk : '.($chunkSize * $columnCount));

        if ($requestedChunkSize !== $chunkSize) {
            $this->warn("Requested chunk [{$requestedChunkSize}] clamped to safe chunk [{$chunkSize}] to stay under the PostgreSQL parameter limit.");
        }

        $branchId = DB::table('mst_branches')
            ->where('code', $branchCode)
            ->value('id');

        if (! $branchId) {
            if ($dryRun) {
                $this->warn("Branch [{$branchCode}] not found. Run php artisan stress:seed-foundation first (dry-run continues).");

                return self::SUCCESS;
            }

            $this->error("Branch with code [{$branchCode}] not found. Run php artisan stress:seed-foundation first.");

            return self::FAILURE;
        }

        $existing = DB::table('mst_patients')
            ->where('medical_record_number', 'like', "DG-{$branchCode}-2026-%")
            ->count();

        $this->info("Branch ID             : {$branchId}");
        $this->info("Existing stress data  : {$existing}");

        if ($existing >= $target) {
            $this->info('Target already reached. No new patients inserted.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->info('[dry-run] Plan validated. No rows written.');

            return self::SUCCESS;
        }

        $start = $existing + 1;
        $now = now();
        $attempted = 0;
        $inserted = 0;

        $bar = $this->output->createProgressBar($target - $existing);
        $bar->start();

        for ($from = $start; $from <= $target; $from += $chunkSize) {
            $to = min($from + $chunkSize - 1, $target);
            $rows = [];

            for ($i = $from; $i <= $to; $i++) {
                $rows[] = $this->buildRow($i, $branchCode, $branchId, $now);
            }

            // insertOrIgnore keeps reruns idempotent (unique RM/KTP skipped).
            $inserted += DB::table('mst_patients')->insertOrIgnore($rows);
            $attempted += count($rows);

            $bar->advance(count($rows));
        }

        $bar->finish();
        $this->newLine(2);

        $skipped = max(0, $attempted - $inserted);
        $this->info("Attempted : {$attempted}");
        $this->info("Inserted  : {$inserted}");
        $this->info("Skipped   : {$skipped} (existing / duplicate)");

        $finalCount = DB::table('mst_patients')
            ->where('medical_record_number', 'like', "DG-{$branchCode}-2026-%")
            ->count();

        $this->info("Final stress patients count: {$finalCount}");

        return self::SUCCESS;
    }

    /**
     * Resolve the requested chunk size from --chunk-size (preferred) or the
     * deprecated --chunk alias, falling back to the default.
     */
    private function resolveRequestedChunkSize(): int
    {
        $chunkSize = $this->option('chunk-size');
        if ($chunkSize !== null && $chunkSize !== '') {
            return (int) $chunkSize;
        }

        $legacy = $this->option('chunk');
        if ($legacy !== null && $legacy !== '') {
            $this->warn('--chunk is deprecated; use --chunk-size.');

            return (int) $legacy;
        }

        return self::DEFAULT_CHUNK_SIZE;
    }

    /**
     * Build one synthetic patient row. KTP/phone values are entirely fabricated
     * (no real PII) and are never logged.
     *
     * @return array<string, mixed>
     */
    private function buildRow(int $i, string $branchCode, int $branchId, mixed $now): array
    {
        $manualRm = str_pad((string) $i, 8, '0', STR_PAD_LEFT);
        $gender = $i % 2 === 0 ? 'female' : 'male';

        return [
            'clinic_id' => null,
            'doctor_id' => null,
            'medical_record_number' => "DG-{$branchCode}-2026-{$manualRm}",
            'name' => 'Stress Patient '.$manualRm,
            'gender' => $gender,
            'date_of_birth' => now()->subYears(18 + ($i % 55))->subDays($i % 365)->toDateString(),
            'phone' => '08'.str_pad((string) (7000000000 + $i), 10, '0', STR_PAD_LEFT),
            'address' => 'Alamat Dummy Stress Test No. '.$i,
            'is_active' => true,
            'ktp_number' => '99'.str_pad((string) $i, 14, '0', STR_PAD_LEFT),
            'whatsapp_number' => '08'.str_pad((string) (8000000000 + $i), 10, '0', STR_PAD_LEFT),
            'email' => 'stress.patient'.$manualRm.'@daengtisia.test',
            'occupation' => 'Stress Test Dummy',
            'branch_id' => $branchId,
            'registered_at' => now()->subDays($i % 720)->toDateString(),
            'manual_rm_number' => $manualRm,
            'import_batch_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
}
