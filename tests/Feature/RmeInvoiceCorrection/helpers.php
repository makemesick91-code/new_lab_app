<?php

/*
| FEATURE-RME-INVOICE-CORRECTION-RECEIPT-FOUNDATION-1 — shared fixtures of the
| invoice-correction suites. Every helper is prefixed `ric` so it can never
| collide with another suite's global Pest helper.
*/

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Clinic\Models\Clinic;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\Patient\Models\Patient;
use App\Modules\RmeInvoice\Interfaces\RmeInvoiceCorrectionRepositoryInterface;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmeInvoiceCorrection;
use App\Modules\RmeInvoice\Services\RmeInvoiceCorrectionService;
use App\Modules\RmeInvoice\Services\RmeInvoiceService;
use App\Modules\RmeInvoice\Services\RmePaymentService;
use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionFeatureGuard;
use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionStatus;
use App\Modules\RmeOnlineContext\Middleware\EnsureRmeOnlineContext;
use App\Modules\Tariff\Models\Tariff;
use App\Modules\Treatment\Models\Treatment;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Collection;

if (! function_exists('ricFlag')) {
    /**
     * The flag key contains a dot, so the whole `feature_flags.flags` array is
     * rewritten (dot-notation config cannot reach the key). `env_value` is cleared
     * so a captured environment override can never outrank the test's intent.
     */
    function ricFlag(bool $enabled): void
    {
        $flags = config('feature_flags.flags', []);
        $flags[RmeInvoiceCorrectionFeatureGuard::FLAG]['default'] = $enabled;
        $flags[RmeInvoiceCorrectionFeatureGuard::FLAG]['env_value'] = null;
        config()->set('feature_flags.flags', $flags);
    }

    /**
     * Branch, clinic, doctor, two active treatments, one patient, and the four
     * actors: a maker (request), a checker (approve), an applier (apply) and a
     * cashier who records payments. None of them is context-bound, so their
     * working scope is every active RME-enabled branch.
     */
    function ricSetup(): void
    {
        test()->seed(BranchSeeder::class);
        seedAccessControl();

        $t = test();
        $t->clinic = Clinic::factory()->create();
        $t->branch = Branch::factory()->create(['code' => 'IC01', 'name' => 'Cabang Koreksi', 'is_rme_enabled' => true, 'is_active' => true]);
        $t->doctor = Doctor::factory()->create();
        $t->treatment = Treatment::factory()->create(['is_active' => true, 'requires_lab' => false, 'name' => 'Scaling']);
        $t->treatment2 = Treatment::factory()->create(['is_active' => true, 'requires_lab' => false, 'name' => 'Tambal']);
        $t->patient = Patient::factory()->create([
            'name' => 'Pasien Rahasia Koreksi',
            'medical_record_number' => 'DG-IC01-2026-0001',
            'ktp_number' => '7371010101900001',
            'branch_id' => $t->branch->id,
        ]);

        $t->maker = userWith(['request_rme_invoice_correction', 'manage_rme_billing', 'view_clinic_visits']);
        $t->checker = userWith(['approve_rme_invoice_correction', 'view_clinic_visits']);
        $t->applier = userWith(['apply_rme_invoice_correction', 'view_clinic_visits']);
        $t->cashier = userWith(['manage_rme_billing', 'view_clinic_visits']);

        ricFlag(true);
        // Rule 195 — the pilot allowlist is fail-closed and source-controlled to
        // SPN4; the suite admits its own fixture branch exactly like production
        // admits SPN4. Tests about the allowlist itself call ricPilot() again.
        ricPilot(['IC01']);
        // Rule 197 (owner D1e) — the pilot identity allowlist is fail-closed and
        // source-controlled to maker 29 / checker 1. The suite designates its own
        // fixture accounts exactly like production designates those two. The
        // recovery apply belongs to the checker identity, so the fixture applier
        // is designated as a checker too (it holds no approve permission, so it
        // can never approve). Tests about the identities call this again.
        ricPilotIdentities([(int) $t->maker->id], [(int) $t->checker->id, (int) $t->applier->id]);
    }

    /**
     * HARDENING-RME-INVOICE-CORRECTION-PILOT-IDENTITY-PAYMENT-ACTOR-1 (rule 197,
     * owner D1e) — set the pilot identity allowlist. Empty lists admit nobody.
     *
     * @param  list<mixed>  $makers
     * @param  list<mixed>  $checkers
     */
    function ricPilotIdentities(array $makers, array $checkers): void
    {
        config()->set('rme_invoice_correction.pilot.identities', [
            'maker_user_ids' => $makers,
            'checker_user_ids' => $checkers,
        ]);
    }

    /**
     * Rule 197 — add accounts to the designated MAKER list, keeping the rest of
     * the allowlist. For tests whose subject is something other than identity
     * (self-approval, D1 roles, readiness) and which therefore must run with
     * their own actors designated, exactly as production designates user 29.
     */
    function ricDesignateMaker(User ...$users): void
    {
        $current = (array) config('rme_invoice_correction.pilot.identities', []);
        $makers = array_values(array_unique(array_merge(
            (array) ($current['maker_user_ids'] ?? []),
            array_map(fn (User $u) => (int) $u->id, $users),
        )));

        ricPilotIdentities($makers, (array) ($current['checker_user_ids'] ?? []));
    }

    /** Rule 197 — add accounts to the designated CHECKER list (see ricDesignateMaker). */
    function ricDesignateChecker(User ...$users): void
    {
        $current = (array) config('rme_invoice_correction.pilot.identities', []);
        $checkers = array_values(array_unique(array_merge(
            (array) ($current['checker_user_ids'] ?? []),
            array_map(fn (User $u) => (int) $u->id, $users),
        )));

        ricPilotIdentities((array) ($current['maker_user_ids'] ?? []), $checkers);
    }

    /**
     * ACTIVATION-RME-INVOICE-CORRECTION-PREPAYMENT-2 (rule 195) — set the pilot
     * allowlist of branch codes. An empty list admits nothing.
     *
     * @param  list<string>  $codes
     */
    function ricPilot(array $codes): void
    {
        config()->set('rme_invoice_correction.pilot.branch_codes', $codes);
    }

    /**
     * FEATURE-RME-BRANCH-TARIFF-AUTHORITY-1 (owner D21) — a correction is only
     * activated for lines whose price the BRANCH tariff validates. The canonical
     * scenario therefore has tariffs: Scaling 300.000, Tambal 50.000. The invoice
     * is billed in a TRANSITIONAL branch with Tambal mistyped as 200.000 (a
     * transitional deviation), and the correction brings it to the tariff.
     * Idempotent per branch.
     */
    function ricTariffs(Branch $branch): void
    {
        foreach ([[test()->treatment, '300000.00'], [test()->treatment2, '50000.00']] as [$treatment, $price]) {
            Tariff::query()->firstOrCreate(
                ['branch_id' => $branch->id, 'treatment_id' => $treatment->id, 'effective_date' => '2026-01-01'],
                ['price' => $price, 'is_active' => true],
            );
        }
    }

    function ricVisit(?Branch $branch = null): ClinicVisit
    {
        $branch ??= test()->branch;
        ricTariffs($branch);

        $visit = ClinicVisit::factory()->cashierPending()->create([
            'branch_id' => $branch->id,
            'clinic_id' => test()->clinic->id,
            'patient_id' => test()->patient->id,
            'doctor_id' => test()->doctor->id,
            'visit_type' => ClinicVisit::VISIT_TYPE_NEW,
            'visit_number' => 'VIS-IC-'.uniqid(),
            'visit_date' => clinicalToday()->toDateString(),
        ]);

        MedicalRecord::factory()->final()->create([
            'clinic_visit_id' => $visit->id,
            'branch_id' => $branch->id,
            'patient_id' => test()->patient->id,
            'doctor_id' => test()->doctor->id,
        ]);

        rmeSignedConsentFor($visit);

        return $visit;
    }

    /**
     * The 500.000 invoice of the canonical scenario: Scaling 300.000 + Tambal 200.000.
     */
    function ricInvoice(?ClinicVisit $visit = null): RmeInvoice
    {
        $visit ??= ricVisit();

        return app(RmeInvoiceService::class)->create($visit, test()->cashier, [
            'items' => [
                ['description' => 'Scaling gigi rahang atas', 'qty' => 1, 'unit_price' => 300000, 'discount' => 0, 'treatment_id' => test()->treatment->id],
                ['description' => 'Tambal komposit gigi 36', 'qty' => 1, 'unit_price' => 200000, 'discount' => 0, 'treatment_id' => test()->treatment2->id],
            ],
        ])->refresh();
    }

    /**
     * The corrected list: Tambal price 200.000 → 50.000 (total 500.000 → 350.000).
     *
     * @return list<array<string, mixed>>
     */
    function ricLinesReducing(RmeInvoice $invoice, string $tambalPrice = '50000'): array
    {
        $items = $invoice->items()->orderBy('id')->get();

        return [
            [
                'original_item_id' => $items[0]->id,
                'treatment_id' => test()->treatment->id,
                'description' => 'Scaling gigi rahang atas',
                'qty' => 1,
                'unit_price' => '300000',
                'discount' => '0',
            ],
            [
                'original_item_id' => $items[1]->id,
                'treatment_id' => test()->treatment2->id,
                'description' => 'Tambal komposit gigi 36',
                'qty' => 1,
                'unit_price' => $tambalPrice,
                'discount' => '0',
            ],
        ];
    }

    function ricSubmit(RmeInvoice $invoice, ?User $maker = null, ?array $lines = null, string $reason = 'Harga tambal salah input oleh kasir.'): RmeInvoiceCorrection
    {
        $maker ??= test()->maker;
        test()->actingAs($maker);

        return app(RmeInvoiceCorrectionService::class)->submit($invoice, $maker, [
            'reason' => $reason,
            'lines' => $lines ?? ricLinesReducing($invoice),
        ]);
    }

    function ricApprove(RmeInvoiceCorrection $correction, ?User $checker = null): RmeInvoiceCorrection
    {
        $checker ??= test()->checker;
        test()->actingAs($checker);

        return app(RmeInvoiceCorrectionService::class)->approve($correction, $checker, null);
    }

    /**
     * ACTIVATION-RME-INVOICE-CORRECTION-PREPAYMENT-2 (rule 195) — since owner
     * D1a, approve() APPLIES. A correction that is APPROVED but not applied can
     * only predate D1a; this builds that legacy shape through the repository
     * (no service path produces it any more) so the recovery path, the D10
     * block on a legacy row and cancellation of an approved row stay tested.
     */
    function ricLegacyApproved(RmeInvoiceCorrection $correction, ?User $checker = null): RmeInvoiceCorrection
    {
        $checker ??= test()->checker;

        app(RmeInvoiceCorrectionRepositoryInterface::class)->transition($correction, [
            'status' => RmeInvoiceCorrectionStatus::APPROVED,
            'decided_by' => (int) $checker->id,
            'decided_at' => now(),
        ]);

        return $correction->fresh();
    }

    function ricApply(RmeInvoiceCorrection $correction, ?User $applier = null): RmeInvoiceCorrection
    {
        $applier ??= test()->applier;
        test()->actingAs($applier);

        return app(RmeInvoiceCorrectionService::class)->apply($correction, $applier);
    }

    function ricPay(RmeInvoice $invoice, float $amount, ?User $cashier = null): void
    {
        $cashier ??= test()->cashier;
        test()->actingAs($cashier);

        app(RmePaymentService::class)->pay($invoice->fresh(), $cashier, rmeFreshPaymentForm($invoice->fresh(), [
            'amount' => $amount,
            'paid_at' => now()->format('Y-m-d H:i:s'),
        ]));
    }

    /** @return Collection<int, AuditLog> */
    function ricAudit(string $action): Collection
    {
        return AuditLog::query()->where('action', $action)->orderBy('id')->get();
    }

    function ricActingAs(User $user): void
    {
        test()->actingAs($user)->withoutMiddleware(EnsureRmeOnlineContext::class);
    }
}
