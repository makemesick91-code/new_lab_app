<?php

/*
| FEATURE-FINANCE-KLINIK-DATA-ANALYST-ROLES-1 — shared fixtures of the
| read-only oversight suites. Every helper is prefixed `cov` (clinic oversight)
| so it can never collide with another suite's global Pest helper.
*/

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Patient\Models\Patient;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmePayment;
use App\Modules\RmeOnlineContext\Middleware\EnsureRmeOnlineContext;
use Illuminate\Support\Str;

if (! function_exists('covBranch')) {
    /** @return list<string> */
    function covRoles(): array
    {
        return ['Finance Klinik', 'Data Analyst'];
    }

    function covBranch(string $code, ?string $name = null, bool $rme = true, bool $active = true): Branch
    {
        return Branch::factory()->create([
            'code' => $code,
            'name' => $name ?? 'Cabang '.$code,
            'is_active' => $active,
            'is_rme_enabled' => $rme,
        ]);
    }

    function covUser(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    /** Act as $user with the RME online-context middleware lifted (it is orthogonal). */
    function covActingAs(User $user): void
    {
        test()->actingAs($user)->withoutMiddleware(EnsureRmeOnlineContext::class);
    }

    function covPatient(?Branch $branch = null, array $attributes = []): Patient
    {
        return Patient::factory()->create(array_merge([
            'branch_id' => $branch?->id,
        ], $attributes));
    }

    function covRoom(Branch $branch, string $type = ClinicRoom::TYPE_TREATMENT_ROOM, string $status = ClinicRoom::STATUS_ACTIVE, ?string $name = null): ClinicRoom
    {
        return ClinicRoom::factory()->create([
            'branch_id' => $branch->id,
            'type' => $type,
            'status' => $status,
            'name' => $name ?? 'Ruang '.Str::upper(Str::random(4)),
        ]);
    }

    function covVisit(Branch $branch, Patient $patient, array $attributes = []): ClinicVisit
    {
        return ClinicVisit::factory()->create(array_merge([
            'branch_id' => $branch->id,
            'patient_id' => $patient->id,
            'clinic_room_id' => null,
            'visit_date' => clinicalToday()->toDateString(),
            'status' => ClinicVisit::STATUS_WAITING,
            'check_in_at' => now(),
        ], $attributes));
    }

    /**
     * Created WITHOUT the RmeInvoice factory on purpose: its definition()
     * eagerly creates a whole extra visit (and branch), which would leak into
     * the cross-branch counts these suites measure.
     */
    function covInvoice(ClinicVisit $visit, string $status, string $grandTotal, array $attributes = []): RmeInvoice
    {
        static $sequence = 0;
        $sequence++;

        return RmeInvoice::query()->forceCreate(array_merge([
            'branch_id' => $visit->branch_id,
            'clinic_visit_id' => $visit->id,
            'patient_id' => $visit->patient_id,
            'medical_record_id' => null,
            'cashier_id' => covCashier()->id,
            'invoice_number' => 'RME-COV-'.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT).'-'.Str::upper(Str::random(4)),
            'status' => $status,
            'subtotal' => $grandTotal,
            'discount_total' => 0,
            'grand_total' => $grandTotal,
        ], $attributes));
    }

    function covPayment(RmeInvoice $invoice, string $amount, string $paidAtUtc, ?string $batch = null): RmePayment
    {
        static $sequence = 0;
        $sequence++;

        return RmePayment::query()->forceCreate([
            'branch_id' => $invoice->branch_id,
            'rme_invoice_id' => $invoice->id,
            'clinic_visit_id' => $invoice->clinic_visit_id,
            'patient_id' => $invoice->patient_id,
            'cashier_id' => covCashier()->id,
            'payment_method_id' => null,
            'payment_number' => 'RMEPAY-COV-'.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT).'-'.Str::upper(Str::random(4)),
            'amount' => $amount,
            'paid_at' => $paidAtUtc,
            'payment_batch_uuid' => $batch,
        ]);
    }

    /**
     * Rewrite a payment row underneath the application, for proving the KPIs stay
     * correct against rows the application itself can no longer produce.
     *
     * FEATURE-RME-BILLING-REFUND-1 / Phase 0 (rule 189 §6) — `RmePayment` refuses
     * any Eloquent update (except patient_id) and any delete, because a
     * re-branched, re-amounted or deleted row would silently rewrite balances and
     * revenue. Such rows can still arise underneath the application (raw SQL),
     * which is exactly why the KPIs must stay correct against them. So these
     * fixtures write the columns the way such a row actually appears — directly,
     * bypassing model events — and the KPI assertions stay under test.
     *
     * @param  array<string, mixed>  $columns
     */
    function covWritePaymentRowDirectly(RmePayment $payment, array $columns): RmePayment
    {
        RmePayment::query()->withTrashed()->whereKey($payment->getKey())->toBase()->update($columns);

        return $payment;
    }

    /** A soft-deleted payment row — proves the KPIs EXCLUDE soft-deleted rows. */
    function covSoftDeletePayment(RmePayment $payment): void
    {
        covWritePaymentRowDirectly($payment, ['deleted_at' => now()]);
    }

    function covCashier(): User
    {
        return User::query()->where('email', 'cov-cashier@example.test')->first()
            ?? User::factory()->create(['email' => 'cov-cashier@example.test']);
    }
}
