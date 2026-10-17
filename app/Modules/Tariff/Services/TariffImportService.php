<?php

declare(strict_types=1);

namespace App\Modules\Tariff\Services;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Branch\Support\BranchCodeAlias;
use App\Modules\LabOrder\Services\AuditLogService;
use App\Modules\Tariff\Interfaces\TariffRepositoryInterface;
use App\Modules\Tariff\Models\Tariff;
use App\Modules\Tariff\Support\TariffAuditEvent;
use App\Modules\Treatment\Models\Treatment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * FEATURE-RME-BRANCH-TARIFF-AUTHORITY-1 — controlled provisioning of a branch's
 * OFFICIAL tariff list (owner D21h).
 *
 * The import never decides a price: the operator supplies the owner-approved
 * list. The service only refuses what cannot be authority and applies the rest
 * exactly, once.
 *
 *   plan()   read-only. Every row is CREATE, UNCHANGED or SKIPPED_BLANK, or the
 *            whole plan is REFUSED with every reason listed: unknown / inactive
 *            treatment code, duplicate code, a price that is not a positive
 *            decimal(18,2) (Rp0 is never price authority, D21g), or a CONFLICT —
 *            a tariff of that branch/treatment/effective date already exists
 *            with another price, inactive or soft-deleted (the unique index
 *            includes trashed rows). There is no partial import.
 *   apply()  requires the digest of the plan the operator reviewed; re-plans
 *            inside the transaction and refuses if anything moved. Writes only
 *            CREATE rows, each audited RME_TARIFF_IMPORTED. Re-running the same
 *            file is idempotent (every row UNCHANGED, nothing written).
 *
 * Never copies another branch's tariff, never derives a price from an invoice.
 */
class TariffImportService
{
    public const CREATE = 'CREATE';

    public const UNCHANGED = 'UNCHANGED';

    public const SKIPPED_BLANK = 'SKIPPED_BLANK';

    public const REASON_MIN = 10;

    public const REASON_MAX = 1000;

    private const PRICE_PATTERN = '/^\d{1,16}(?:\.\d{1,2})?$/D';

    public function __construct(
        private readonly TariffRepositoryInterface $tariffs,
        private readonly AuditLogService $auditLogs,
    ) {}

    /**
     * @param  list<array{treatment_code: string, price: string}>  $rows
     * @return array{branch: array{id: int|null, code: string}, effective_date: string, rows: list<array<string, mixed>>, refusals: list<string>, digest: string, creates: int}
     */
    public function plan(string $branchCode, string $effectiveDate, array $rows): array
    {
        $refusals = [];
        $code = BranchCodeAlias::canonicalize($branchCode) ?? '';
        $branch = $code !== '' ? Branch::query()->whereIn('code', BranchCodeAlias::equivalentCodes($code))->first() : null;

        if (! $branch instanceof Branch) {
            $refusals[] = "BRANCH_UNKNOWN: cabang '{$code}' tidak ditemukan.";
        } elseif (! $branch->is_active || ! $branch->is_rme_enabled) {
            $refusals[] = "BRANCH_NOT_RME: cabang '{$code}' tidak aktif atau bukan cabang RME.";
        }

        $date = $this->strictDate($effectiveDate);
        if ($date === null) {
            $refusals[] = "EFFECTIVE_DATE_INVALID: '{$effectiveDate}' bukan tanggal Y-m-d yang valid.";
        }

        $codes = array_values(array_unique(array_map(fn ($r) => self::normalizeCode((string) $r['treatment_code']), $rows)));
        // Match case-insensitively: treatment codes are free text in master data,
        // and the template prints them as stored. Two treatments whose codes
        // differ only by case cannot be told apart from a CSV row — refused.
        $matched = Treatment::withTrashed()->whereIn(DB::raw('UPPER(code)'), $codes)->get()
            ->groupBy(fn (Treatment $t) => strtoupper((string) $t->code));
        $ambiguous = $matched->filter(fn ($group) => $group->count() > 1)->keys()->all();
        $treatments = $matched->map(fn ($group) => $group->first());

        $existing = [];
        if ($branch instanceof Branch && $date !== null) {
            $existing = $this->tariffs->atEffectiveDateIncludingTrashed(
                (int) $branch->id,
                $treatments->pluck('id')->map(fn ($id) => (int) $id)->all(),
                $date,
            );
        }

        $planned = [];
        $seen = [];
        foreach ($rows as $index => $row) {
            $line = $index + 2; // CSV line number (header is line 1)
            $treatmentCode = self::normalizeCode((string) $row['treatment_code']);
            $price = trim((string) $row['price']);

            if ($treatmentCode === '') {
                $refusals[] = "ROW_{$line}_CODE_MISSING: kode tindakan kosong.";

                continue;
            }

            if (isset($seen[$treatmentCode])) {
                $refusals[] = "ROW_{$line}_DUPLICATE: kode {$treatmentCode} muncul lebih dari sekali.";

                continue;
            }
            $seen[$treatmentCode] = true;

            if ($price === '') {
                $planned[] = ['line' => $line, 'treatment_code' => $treatmentCode, 'action' => self::SKIPPED_BLANK, 'price_cents' => null];

                continue;
            }

            if (in_array($treatmentCode, $ambiguous, true)) {
                $refusals[] = "ROW_{$line}_CODE_AMBIGUOUS: kode {$treatmentCode} cocok dengan lebih dari satu tindakan master (beda huruf besar/kecil).";

                continue;
            }

            $treatment = $treatments->get($treatmentCode);
            if (! $treatment instanceof Treatment || $treatment->trashed() || ! $treatment->is_active) {
                $refusals[] = "ROW_{$line}_TREATMENT_UNKNOWN: kode {$treatmentCode} bukan tindakan master yang aktif.";

                continue;
            }

            if (preg_match(self::PRICE_PATTERN, $price) !== 1) {
                $refusals[] = "ROW_{$line}_PRICE_INVALID: harga '{$price}' bukan angka positif dengan maksimal 2 desimal.";

                continue;
            }

            $cents = BranchTariffAuthorityService::toCents($price);
            if ($cents <= 0) {
                $refusals[] = "ROW_{$line}_PRICE_ZERO: tarif Rp0 tidak dapat menjadi dasar harga (kebijakan Rp0 belum diputuskan).";

                continue;
            }

            $current = $existing[(int) $treatment->id] ?? null;
            if ($current instanceof Tariff) {
                $same = ! $current->trashed()
                    && $current->is_active
                    && BranchTariffAuthorityService::toCents($current->price) === $cents;

                if (! $same) {
                    $refusals[] = "ROW_{$line}_CONFLICT: tarif {$treatmentCode} pada tanggal {$date} sudah ada dengan nilai/status lain; ubah lewat master data, bukan impor.";

                    continue;
                }

                $planned[] = ['line' => $line, 'treatment_code' => $treatmentCode, 'treatment_id' => (int) $treatment->id, 'action' => self::UNCHANGED, 'price_cents' => $cents];

                continue;
            }

            $planned[] = ['line' => $line, 'treatment_code' => $treatmentCode, 'treatment_id' => (int) $treatment->id, 'action' => self::CREATE, 'price_cents' => $cents];
        }

        $creates = count(array_filter($planned, fn ($p) => $p['action'] === self::CREATE));

        return [
            'branch' => ['id' => $branch?->id !== null ? (int) $branch->id : null, 'code' => $code],
            'effective_date' => (string) ($date ?? $effectiveDate),
            'rows' => $planned,
            'refusals' => $refusals,
            'creates' => $creates,
            'digest' => $this->digest($code, (string) ($date ?? $effectiveDate), $planned, $refusals),
        ];
    }

    /**
     * @param  list<array{treatment_code: string, price: string}>  $rows
     * @return array{created: int, unchanged: int, tariff_ids: list<int>}
     */
    public function apply(string $branchCode, string $effectiveDate, array $rows, string $confirmDigest, string $reason, User $actor): array
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < self::REASON_MIN || mb_strlen($reason) > self::REASON_MAX) {
            throw new InvalidArgumentException('REASON_INVALID: alasan impor wajib '.self::REASON_MIN.'–'.self::REASON_MAX.' karakter.');
        }

        if (! $actor->can('manage_clinic_master_data')) {
            throw new InvalidArgumentException('ACTOR_NOT_AUTHORIZED: aktor tidak memiliki izin manage_clinic_master_data.');
        }

        return DB::transaction(function () use ($branchCode, $effectiveDate, $rows, $confirmDigest, $reason, $actor) {
            $plan = $this->plan($branchCode, $effectiveDate, $rows);

            if ($plan['refusals'] !== []) {
                throw new InvalidArgumentException('PLAN_REFUSED: '.implode(' | ', $plan['refusals']));
            }

            if (! hash_equals($plan['digest'], $confirmDigest)) {
                throw new InvalidArgumentException('PLAN_CHANGED: rencana impor berbeda dari yang ditinjau (digest tidak cocok). Jalankan dry-run lagi.');
            }

            $ids = [];
            foreach ($plan['rows'] as $row) {
                if ($row['action'] !== self::CREATE) {
                    continue;
                }

                $tariff = $this->tariffs->create([
                    'branch_id' => (int) $plan['branch']['id'],
                    'treatment_id' => (int) $row['treatment_id'],
                    'price' => number_format($row['price_cents'] / 100, 2, '.', ''),
                    'effective_date' => $plan['effective_date'],
                    'is_active' => true,
                ]);

                $this->auditLogs->log(
                    TariffAuditEvent::ENTITY,
                    (int) $tariff->id,
                    TariffAuditEvent::IMPORTED,
                    null,
                    array_merge(TariffAuditEvent::state($tariff), [
                        'import_digest' => $plan['digest'],
                        'reason_length' => mb_strlen($reason),
                    ]),
                    $actor,
                );

                $ids[] = (int) $tariff->id;
            }

            return [
                'created' => count($ids),
                'unchanged' => count(array_filter($plan['rows'], fn ($r) => $r['action'] === self::UNCHANGED)),
                'tariff_ids' => $ids,
            ];
        });
    }

    private function strictDate(string $value): ?string
    {
        $value = trim($value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value ? $value : null;
    }

    /**
     * @param  list<array<string, mixed>>  $planned
     * @param  list<string>  $refusals
     */
    private function digest(string $code, string $date, array $planned, array $refusals): string
    {
        $canonical = array_map(fn ($p) => $p['treatment_code'].'='.$p['action'].':'.($p['price_cents'] ?? '-'), $planned);
        sort($canonical);

        return substr(hash('sha256', json_encode([$code, $date, $canonical, $refusals])), 0, 32);
    }

    /** Characters a spreadsheet treats as the start of a formula. */
    private const FORMULA_LEADS = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * A treatment code as written into the template: a code a spreadsheet would
     * read as a formula gets a leading apostrophe (the codebase `csvSafe`
     * convention), which normalizeCode() removes again on import.
     */
    public static function csvSafeCode(string $code): string
    {
        return $code !== '' && in_array($code[0], self::FORMULA_LEADS, true) ? "'".$code : $code;
    }

    /** Trim, undo csvSafeCode(), upper-case. */
    private static function normalizeCode(string $raw): string
    {
        $code = trim($raw);
        if (strlen($code) > 1 && $code[0] === "'" && in_array($code[1], self::FORMULA_LEADS, true)) {
            $code = substr($code, 1);
        }

        return strtoupper($code);
    }
}
