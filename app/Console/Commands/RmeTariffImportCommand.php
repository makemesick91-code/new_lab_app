<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Modules\Tariff\Services\TariffImportService;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * FEATURE-RME-BRANCH-TARIFF-AUTHORITY-1 — import a branch's OFFICIAL tariff list
 * (owner D21h). The CSV is the owner-approved price list; nothing is guessed.
 *
 *   DRY RUN BY DEFAULT. Prints the plan and its digest; writes nothing.
 *   --apply needs --confirm-plan=<digest> of the plan you reviewed, a named
 *   active --actor holding manage_clinic_master_data, and a --reason. The plan
 *   is recomputed inside the transaction; any drift refuses.
 *
 * CSV header must contain `treatment_code` and `price` (other columns, such as
 * the template's `current_tariff_in_force`, are ignored). A blank price is
 * skipped, never imported as Rp0.
 */
class RmeTariffImportCommand extends Command
{
    protected $signature = 'rme:tariff-import
        {file : Path to the CSV}
        {--branch= : Canonical branch code, e.g. SPN4 (required)}
        {--effective-date= : Y-m-d the tariffs take effect (required)}
        {--apply : Write the plan (otherwise dry run)}
        {--confirm-plan= : Digest printed by the dry run (required with --apply)}
        {--actor= : Id or email of the active account this import is attributed to (required with --apply)}
        {--reason= : Why these tariffs are imported (required with --apply)}
        {--json : Print the plan as JSON}';

    protected $description = 'Import an owner-approved branch tariff CSV. Dry run unless --apply with the reviewed plan digest.';

    public function handle(TariffImportService $importer): int
    {
        $rows = $this->readCsv((string) $this->argument('file'));
        if (is_string($rows)) {
            $this->error($rows);

            return self::FAILURE;
        }

        $branch = (string) $this->option('branch');
        $date = (string) $this->option('effective-date');
        $plan = $importer->plan($branch, $date, $rows);

        if ($this->option('json')) {
            $this->line((string) json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(['Line', 'Treatment', 'Action', 'Price'], array_map(fn ($r) => [
                $r['line'], $r['treatment_code'], $r['action'],
                $r['price_cents'] === null ? '-' : number_format($r['price_cents'] / 100, 2, '.', ''),
            ], $plan['rows']));
            foreach ($plan['refusals'] as $refusal) {
                $this->line('REFUSED '.$refusal);
            }
            $this->line('BRANCH='.$plan['branch']['code'].' EFFECTIVE_DATE='.$plan['effective_date'].' CREATES='.$plan['creates']);
            $this->line('PLAN_DIGEST='.$plan['digest']);
        }

        if ($plan['refusals'] !== []) {
            $this->error('Rencana impor ditolak. Tidak ada yang ditulis.');

            return self::FAILURE;
        }

        if (! $this->option('apply')) {
            $this->line('DRY RUN — nothing written. Re-run with --apply --confirm-plan='.$plan['digest'].' --actor= --reason=');

            return self::SUCCESS;
        }

        $actor = $this->resolveActor();
        if (is_string($actor)) {
            $this->error($actor);

            return self::FAILURE;
        }

        try {
            $result = $importer->apply($branch, $date, $rows, (string) $this->option('confirm-plan'), (string) $this->option('reason'), $actor);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line('APPLIED created='.$result['created'].' unchanged='.$result['unchanged']);

        return self::SUCCESS;
    }

    /**
     * @return list<array{treatment_code: string, price: string}>|string
     */
    private function readCsv(string $path): array|string
    {
        if (! is_file($path) || ! is_readable($path)) {
            return 'Berkas CSV tidak ditemukan atau tidak dapat dibaca.';
        }

        $handle = fopen($path, 'r');
        if ($handle === false) {
            return 'Berkas CSV tidak dapat dibuka.';
        }

        $header = fgetcsv($handle);
        if (! is_array($header)) {
            fclose($handle);

            return 'Berkas CSV kosong.';
        }

        $header = array_map(fn ($h) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h) ?? '')), $header);
        $codeAt = array_search('treatment_code', $header, true);
        $priceAt = array_search('price', $header, true);

        if ($codeAt === false || $priceAt === false) {
            fclose($handle);

            return 'Header CSV wajib memuat kolom treatment_code dan price.';
        }

        $rows = [];
        while (($cells = fgetcsv($handle)) !== false) {
            if ($cells === [null] || $cells === []) {
                continue;
            }
            $rows[] = [
                'treatment_code' => (string) ($cells[$codeAt] ?? ''),
                'price' => (string) ($cells[$priceAt] ?? ''),
            ];
            if (count($rows) > 5000) {
                fclose($handle);

                return 'Berkas CSV melebihi 5000 baris.';
            }
        }
        fclose($handle);

        return $rows;
    }

    private function resolveActor(): User|string
    {
        $identifier = trim((string) $this->option('actor'));
        if ($identifier === '') {
            return 'Sertakan --actor= dengan id atau email pengguna yang menjalankan impor.';
        }

        $actor = ctype_digit($identifier)
            ? User::query()->find((int) $identifier)
            : User::query()->where('email', $identifier)->first();

        if ($actor === null) {
            return 'Pengguna tersebut tidak ditemukan.';
        }

        if (! (bool) $actor->is_active) {
            return 'Akun tersebut sudah tidak aktif.';
        }

        return $actor;
    }
}
