<?php

/*
| FEATURE-RME-RP0-CONTROL-CONSULTATION-1 (rule 217, Sprint 6/7) — shared fixtures
| of the "Selesai Tanpa Biaya" (zero-value closure) suites. Every helper is
| prefixed `rzv`. They build on the invoice-correction fixtures (`ric*`) so the
| suites exercise the REAL invoice, payment, dispute and correction services.
*/

use App\Models\User;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Services\RmeInvoiceService;
use App\Modules\RmeInvoice\Services\RmePaymentService;
use App\Modules\RmeInvoice\Support\RmeFinancialAuditEvent;
use App\Modules\RmeInvoice\Support\RmeZeroValueClosureFeatureGuard;
use App\Modules\Treatment\Models\Treatment;
use App\Modules\TreatmentCategory\Models\TreatmentCategory;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../RmeInvoiceCorrection/helpers.php';

if (! function_exists('rzvSetup')) {
    /**
     * The flag key contains a dot, so the whole `feature_flags.flags` array is
     * rewritten; `env_value` is cleared so a captured override never outranks
     * the test's intent.
     */
    function rzvFlag(bool $enabled): void
    {
        $flags = config('feature_flags.flags', []);
        $flags[RmeZeroValueClosureFeatureGuard::FLAG]['default'] = $enabled;
        $flags[RmeZeroValueClosureFeatureGuard::FLAG]['env_value'] = null;
        config()->set('feature_flags.flags', $flags);
    }

    /**
     * The correction fixture plus the two master-data rows the owner decision
     * names: Konsultasi Dokter (category `consultation`) and the excluded voucher
     * TRT-FREESC (S6-D6).
     */
    function rzvSetup(): void
    {
        ricSetup();

        $t = test();
        $consultation = TreatmentCategory::query()->firstOrCreate(['code' => 'consultation'], ['name' => 'Konsultasi']);
        $preventive = TreatmentCategory::query()->firstOrCreate(['code' => 'preventive'], ['name' => 'Preventif']);

        $t->consultation = Treatment::factory()->create([
            'code' => 'TRT-CONS', 'name' => 'Konsultasi Dokter', 'treatment_category_id' => $consultation->id,
            'is_active' => true, 'requires_lab' => false,
        ]);
        $t->voucher = Treatment::factory()->create([
            'code' => 'TRT-FREESC', 'name' => 'Scalling Voucher Gratis', 'treatment_category_id' => $preventive->id,
            'is_active' => true, 'requires_lab' => false,
        ]);

        rzvFlag(true);
    }

    /** A cashier_pending NEW visit with a final record and a signed consent. */
    function rzvNewVisit(): ClinicVisit
    {
        return ricVisit();
    }

    /**
     * A cashier_pending KONTROL visit: visit_type control AND a server-side parent
     * visit (follow_up_of_visit_id), the way StoreClinicVisitRequest derives it.
     */
    function rzvControlVisit(?ClinicVisit $parent = null, bool $withParent = true): ClinicVisit
    {
        $parent ??= $withParent ? ricVisit() : null;

        $visit = ClinicVisit::factory()->cashierPending()->create([
            'branch_id' => test()->branch->id,
            'clinic_id' => test()->clinic->id,
            'patient_id' => test()->patient->id,
            'doctor_id' => test()->doctor->id,
            'visit_type' => ClinicVisit::VISIT_TYPE_CONTROL,
            'follow_up_of_visit_id' => $parent?->id,
            'visit_number' => 'VIS-RZV-CTL-'.uniqid(),
            'visit_date' => clinicalToday()->toDateString(),
        ]);
        MedicalRecord::factory()->final()->create([
            'clinic_visit_id' => $visit->id, 'branch_id' => test()->branch->id,
            'patient_id' => test()->patient->id, 'doctor_id' => test()->doctor->id,
        ]);
        rmeSignedConsentFor($visit);

        return $visit;
    }

    /** @return array<string, mixed> */
    function rzvLine(?Treatment $treatment, int $unitPrice = 0, int $discount = 0, string $description = 'Konsultasi dokter'): array
    {
        return [
            'description' => $description,
            'qty' => 1,
            'unit_price' => $unitPrice,
            'discount' => $discount,
            'treatment_id' => $treatment?->id,
        ];
    }

    /**
     * Issue an invoice through the REAL invoice service (manual pricing, D21).
     *
     * @param  list<array<string, mixed>>  $lines
     */
    function rzvInvoice(ClinicVisit $visit, array $lines): RmeInvoice
    {
        return app(RmeInvoiceService::class)->create($visit, test()->cashier, ['items' => $lines])->refresh();
    }

    function rzvConsultationInvoice(?ClinicVisit $visit = null): RmeInvoice
    {
        return rzvInvoice($visit ?? rzvNewVisit(), [rzvLine(test()->consultation)]);
    }

    function rzvClose(RmeInvoice $invoice, ?User $actor = null): RmeInvoice
    {
        $actor ??= test()->cashier;
        test()->actingAs($actor);

        return app(RmePaymentService::class)->closeZeroValueInvoice($invoice->fresh(), $actor);
    }

    /** The refusal code (or message) of a closure attempt, or null when it succeeded. */
    function rzvRefusal(RmeInvoice $invoice, ?User $actor = null): ?string
    {
        try {
            rzvClose($invoice, $actor);
        } catch (ValidationException $e) {
            return (string) json_encode($e->errors(), JSON_UNESCAPED_UNICODE);
        }

        return null;
    }

    function rzvClosureAudits(?RmeInvoice $invoice = null): int
    {
        return AuditLog::query()
            ->where('action', RmeFinancialAuditEvent::ACTION_INVOICE_ZERO_VALUE_CLOSED)
            ->when($invoice !== null, fn ($q) => $q->where('entity_id', $invoice->id))
            ->count();
    }

    /**
     * The money a closure must NEVER change: every payment row, the gross and
     * net sums, and every refund/obligation row.
     *
     * @return array<string, mixed>
     */
    function rzvMoney(): array
    {
        return [
            'payments' => DB::table('trx_rme_payments')->orderBy('id')->get(['id', 'rme_invoice_id', 'amount'])->map(fn ($r) => (array) $r)->all(),
            'payment_sum' => (string) DB::table('trx_rme_payments')->sum('amount'),
            'obligations' => DB::table('trx_rme_overpayment_obligations')->count(),
            'refund_requests' => DB::table('trx_rme_refund_requests')->count(),
            'refund_settlements' => DB::table('trx_rme_refund_settlements')->count(),
        ];
    }
}
