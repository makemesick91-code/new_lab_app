<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class StressSeedPatientsCommand extends Command
{
    protected $signature = 'stress:seed-patients
        {--target=10000 : Total stress patients target}
        {--chunk=1000 : Insert chunk size}
        {--branch-code=TST : Stress branch code}';

    protected $description = 'Seed dummy patients for stress testing. Only runs in APP_ENV=stress.';

    public function handle(): int
    {
        if (! app()->environment('stress')) {
            $this->error('This command only runs in APP_ENV=stress.');
            return self::FAILURE;
        }

        $target = max(1, (int) $this->option('target'));
        $requestedChunkSize = max(100, (int) $this->option('chunk'));

        // PostgreSQL/PDO has a parameter limit around 65,535.
        // This command inserts around 18 columns per patient, so keep chunks safely below that.
        $chunkSize = min($requestedChunkSize, 1000);
        $branchCode = (string) $this->option('branch-code');

        $branchId = DB::table('mst_branches')
            ->where('code', $branchCode)
            ->value('id');

        if (! $branchId) {
            $this->error("Branch with code [{$branchCode}] not found. Run php artisan stress:seed-foundation --env=stress first.");
            return self::FAILURE;
        }

        $existing = DB::table('mst_patients')
            ->where('medical_record_number', 'like', "DG-{$branchCode}-2026-%")
            ->count();

        $this->info("Stress patient target : {$target}");
        $this->info("Existing stress data  : {$existing}");
        $this->info("Chunk size            : {$chunkSize}");

        if ($requestedChunkSize !== $chunkSize) {
        $this->warn("Requested chunk [{$requestedChunkSize}] was reduced to safe chunk [{$chunkSize}] to avoid PostgreSQL parameter limit.");
        }
        $this->info("Branch ID             : {$branchId}");

        if ($existing >= $target) {
            $this->info('Target already reached. No new patients inserted.');
            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($target - $existing);
        $bar->start();

        $start = $existing + 1;
        $now = now();

        for ($from = $start; $from <= $target; $from += $chunkSize) {
            $to = min($from + $chunkSize - 1, $target);
            $rows = [];

            for ($i = $from; $i <= $to; $i++) {
                $manualRm = str_pad((string) $i, 8, '0', STR_PAD_LEFT);
                $gender = $i % 2 === 0 ? 'female' : 'male';

                $rows[] = [
                    'clinic_id' => null,
                    'doctor_id' => null,
                    'medical_record_number' => "DG-{$branchCode}-2026-{$manualRm}",
                    'name' => 'Stress Patient ' . $manualRm,
                    'gender' => $gender,
                    'date_of_birth' => now()->subYears(18 + ($i % 55))->subDays($i % 365)->toDateString(),
                    'phone' => '08' . str_pad((string) (7000000000 + $i), 10, '0', STR_PAD_LEFT),
                    'address' => 'Alamat Dummy Stress Test No. ' . $i,
                    'is_active' => true,
                    'ktp_number' => '99' . str_pad((string) $i, 14, '0', STR_PAD_LEFT),
                    'whatsapp_number' => '08' . str_pad((string) (8000000000 + $i), 10, '0', STR_PAD_LEFT),
                    'email' => 'stress.patient' . $manualRm . '@daengtisia.test',
                    'occupation' => 'Stress Test Dummy',
                    'branch_id' => $branchId,
                    'registered_at' => now()->subDays($i % 720)->toDateString(),
                    'manual_rm_number' => $manualRm,
                    'import_batch_id' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::table('mst_patients')->insertOrIgnore($rows);

            $bar->advance(count($rows));
        }

        $bar->finish();
        $this->newLine(2);

        $finalCount = DB::table('mst_patients')
            ->where('medical_record_number', 'like', "DG-{$branchCode}-2026-%")
            ->count();

        $this->info("Final stress patients count: {$finalCount}");

        return self::SUCCESS;
    }
}
