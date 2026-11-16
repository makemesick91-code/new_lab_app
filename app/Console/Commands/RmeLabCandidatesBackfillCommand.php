<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\ClinicOversight\Support\ClinicalDayWindow;
use App\Modules\LabOrder\Services\AuditLogService;
use App\Modules\RmeInvoice\Interfaces\RmeInvoiceRepositoryInterface;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Services\RmeLabIntegrationService;
use App\Support\Clinical\ClinicalClock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * DMS-RME-COMPREHENSIVE-CLOSURE-1 (OD-7-5, owner decision 2026-11-16) — recover
 * lab-work candidates a payment failed to generate.
 *
 * RmePaymentService generates candidates after a payment commits and, when that
 * fails, keeps the payment and logs a redacted line; nothing ever retried it.
 * This command finds the PAID invoices of the active RME branches that still
 * miss a candidate for a lab-requiring item and runs the existing, idempotent
 * generator on each (one candidate per invoice item, keyed by
 * rme_invoice_item_id).
 *
 * THE SET IS BOUNDED BY AN EXPLICIT PAYMENT WINDOW. "Paid and missing a
 * candidate" is wider than "a generation that failed": a treatment flagged
 * `requires_lab` after its invoice was paid, or an invoice paid before
 * candidates existed, matches too. So `--apply` refuses to run without
 * `--paid-from` (a clinic date, WITA), optionally with `--paid-to` (inclusive),
 * compared with the invoice's latest payment; the dry run prints the counts per
 * clinic month of that payment so the operator can choose the window first.
 *
 * Dry run by default: it reads and counts only. --apply generates, one invoice
 * per transaction under the invoice row lock, and writes one audit row per
 * invoice it filled. Running it twice changes nothing the second time. Counts
 * only on the console: no invoice number, identifier or patient detail.
 */
class RmeLabCandidatesBackfillCommand extends Command
{
    protected $signature = 'rme:lab-candidates-backfill
        {--apply : Generate the missing candidates (default: dry run, nothing written)}
        {--paid-from= : Clinic date (Y-m-d, WITA) of the earliest latest-payment to include; REQUIRED with --apply}
        {--paid-to= : Clinic date (Y-m-d, WITA) of the last latest-payment to include, inclusive}
        {--limit= : At most this many invoices in one run}';

    protected $description = 'Regenerate missing lab-work candidates of paid RME invoices within an explicit payment window (dry run unless --apply; idempotent).';

    public const AUDIT_TABLE = 'trx_rme_invoices';

    public const AUDIT_ACTION = 'RME_LAB_CANDIDATES_BACKFILLED';

    public function handle(RmeLabIntegrationService $lab, RmeInvoiceRepositoryInterface $invoices, AuditLogService $audit, ClinicalClock $clock): int
    {
        $apply = (bool) $this->option('apply');
        $limit = $this->option('limit') !== null ? max(1, (int) $this->option('limit')) : null;

        $from = $this->windowOption('paid-from', $clock);
        $to = $this->windowOption('paid-to', $clock);

        if ($from === false || $to === false) {
            $this->error('--paid-from / --paid-to must be real clinic dates in Y-m-d form. Nothing was read or written.');

            return self::FAILURE;
        }

        if ($apply && $from === null) {
            $this->error('--apply needs an explicit --paid-from=YYYY-MM-DD (clinic date): review the dry run counts per month first. Nothing was written.');

            return self::FAILURE;
        }

        if ($from !== null && $to !== null && $to->date < $from->date) {
            $this->error('--paid-to is before --paid-from. Nothing was read or written.');

            return self::FAILURE;
        }

        $found = $lab->paidInvoicesMissingCandidates($from?->startUtcString(), $to?->endUtcString(), $limit);
        $counts = ['invoices' => $found->count(), 'filled' => 0, 'candidates_created' => 0, 'failed' => 0];

        $this->line('PAID_FROM='.($from?->date ?? 'ANY'));
        $this->line('PAID_TO='.($to?->date ?? 'ANY'));

        if (! $apply) {
            // The operator's basis for choosing the window: counts per clinic
            // month of each invoice's latest payment, nothing else.
            $perMonth = $found
                ->groupBy(fn (RmeInvoice $invoice): string => $invoice->last_paid_at === null
                    ? 'NONE'
                    : $clock->toClinicalDate((string) $invoice->last_paid_at)->format('Y-m'))
                ->map->count()
                ->sortKeys();

            foreach ($perMonth as $month => $count) {
                $this->line('PAID_MONTH_'.$month.'='.$count);
            }
        }

        if ($apply) {
            foreach ($found as $row) {
                $invoiceId = (int) $row->getKey();

                try {
                    $created = DB::transaction(function () use ($lab, $invoices, $audit, $invoiceId): int {
                        // Under the invoice row lock, so a payment or a correction on
                        // the same invoice is serialised with the backfill.
                        $invoice = $invoices->findForUpdate($invoiceId);

                        if (! $invoice instanceof RmeInvoice || ! $invoice->isPaid()) {
                            return 0;
                        }

                        $made = $lab->generateForPaidInvoice($invoice)
                            ->filter(fn ($candidate) => $candidate->wasRecentlyCreated)
                            ->count();

                        if ($made > 0) {
                            $audit->log(self::AUDIT_TABLE, (int) $invoice->getKey(), self::AUDIT_ACTION, null, [
                                'candidates_created' => $made,
                                'source' => 'rme:lab-candidates-backfill',
                            ]);
                        }

                        return $made;
                    });

                    if ($created > 0) {
                        $counts['filled']++;
                        $counts['candidates_created'] += $created;
                    }
                } catch (Throwable $e) {
                    // One failing invoice never stops the others; class only, no message (CG-11).
                    $counts['failed']++;
                    $this->warn('An invoice could not be backfilled ('.$e::class.').');
                }
            }
        }

        foreach ($counts as $name => $value) {
            $this->line(strtoupper($name).'='.$value);
        }

        $this->line('LAB_CANDIDATE_BACKFILL='.(! $apply ? 'DRY_RUN' : ($counts['failed'] === 0 ? 'APPLIED' : 'INCOMPLETE')));

        return $counts['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** The option's clinic-day window, null when absent, false when not a real date. */
    private function windowOption(string $name, ClinicalClock $clock): ClinicalDayWindow|false|null
    {
        $value = $this->option($name);

        if ($value === null || $value === '') {
            return null;
        }

        return ClinicalDayWindow::tryFromDate((string) $value, $clock) ?? false;
    }
}
