<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\LabOrder\Services\AuditLogService;
use App\Modules\RmeInvoice\Interfaces\RmeInvoiceRepositoryInterface;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Services\RmeLabIntegrationService;
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
 * rme_invoice_item_id). Dry run by default: it reads and counts only. --apply
 * generates, one invoice per transaction, and writes one audit row per invoice it
 * filled. Running it twice changes nothing the second time. Counts only on the
 * console: no patient detail.
 */
class RmeLabCandidatesBackfillCommand extends Command
{
    protected $signature = 'rme:lab-candidates-backfill
        {--apply : Generate the missing candidates (default: dry run, nothing written)}
        {--limit= : At most this many invoices in one run}';

    protected $description = 'Regenerate missing lab-work candidates of paid RME invoices (dry run unless --apply; idempotent).';

    public const AUDIT_TABLE = 'trx_rme_invoices';

    public const AUDIT_ACTION = 'RME_LAB_CANDIDATES_BACKFILLED';

    public function handle(RmeLabIntegrationService $lab, RmeInvoiceRepositoryInterface $invoices, AuditLogService $audit): int
    {
        $apply = (bool) $this->option('apply');
        $limit = $this->option('limit') !== null ? max(1, (int) $this->option('limit')) : null;

        $ids = $lab->paidInvoiceIdsMissingCandidates($limit);
        $counts = ['invoices' => $ids->count(), 'filled' => 0, 'candidates_created' => 0, 'failed' => 0];

        if ($apply) {
            foreach ($ids as $invoiceId) {
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
}
