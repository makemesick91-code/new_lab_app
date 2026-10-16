<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Support;

use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmeInvoiceItem;
use App\Modules\RmeInvoice\Models\RmePayment;

/**
 * FEATURE-RME-INVOICE-CORRECTION-RECEIPT-FOUNDATION-1 — the canonical, versioned
 * snapshot of an RME invoice document, and its fingerprint.
 *
 * ONE builder for every frozen copy of an invoice: the correction's
 * `original_snapshot`, and the ORIGINAL / REVISION rows of
 * trx_rme_invoice_document_versions. Two builders would eventually disagree
 * about what "the document" was, and a fingerprint is only meaningful if every
 * party computes it from the same definition.
 *
 * SCHEMA (version 1) — explicit, complete, money in integer cents:
 *   schema_version
 *   invoice  {id, invoice_number, status, branch_id, clinic_visit_id, patient_id,
 *             medical_record_id, correction_version, created_at}
 *   branch   {id, code, name, address, phone}         — printed header, frozen
 *   items[]  {id, treatment_id, treatment_code, treatment_name, description, qty,
 *             unit_price_cents, discount_cents, subtotal_cents, doctor_id}
 *   payments[] {id, payment_number, amount_cents, paid_at, payment_batch_uuid,
 *               payment_method_id}
 *   totals   {subtotal_cents, discount_total_cents, grand_total_cents, paid_cents}
 *
 * PRIVACY: no patient name, Nomor RM, KTP/NIK, phone, invoice notes or payment
 * notes/references. Patient identity is not the financial content that changes
 * and is joined live; free-text notes may name a patient.
 *
 * The FINGERPRINT covers exactly the financial content a correction is reviewed
 * against — status, version, items, payments, totals — so ANY change to what the
 * approver saw (a payment landing, another correction applied, an item edited by
 * any writer) makes the fingerprint differ and the correction STALE. Display-only
 * fields (branch header, treatment names) are deliberately outside it: renaming a
 * branch must not invalidate a pending financial review.
 */
final class RmeInvoiceSnapshot
{
    public const SCHEMA_VERSION = 1;

    /**
     * Build the snapshot from FRESH reads. Relations are re-queried, never taken
     * from whatever the caller had loaded — a snapshot of a stale in-memory
     * relation is exactly the error this class exists to prevent.
     *
     * @return array<string, mixed>
     */
    public static function capture(RmeInvoice $invoice): array
    {
        $invoice->load([
            'items' => fn ($query) => $query->orderBy('id'),
            'items.treatment',
            'payments' => fn ($query) => $query->orderBy('id'),
            'branch',
        ]);

        $items = $invoice->items->map(fn (RmeInvoiceItem $item) => [
            'id' => (int) $item->id,
            'treatment_id' => $item->treatment_id !== null ? (int) $item->treatment_id : null,
            'treatment_code' => $item->treatment?->code,
            'treatment_name' => $item->treatment?->name,
            'description' => (string) $item->description,
            'qty' => (int) $item->qty,
            'unit_price_cents' => RmeInvoiceDocument::toCents($item->unit_price),
            'discount_cents' => RmeInvoiceDocument::toCents($item->discount),
            'subtotal_cents' => RmeInvoiceDocument::toCents($item->subtotal),
            'doctor_id' => $item->doctor_id !== null ? (int) $item->doctor_id : null,
        ])->values()->all();

        $payments = $invoice->payments->map(fn (RmePayment $payment) => [
            'id' => (int) $payment->id,
            'payment_number' => (string) $payment->payment_number,
            'amount_cents' => RmeInvoiceDocument::toCents($payment->amount),
            'paid_at' => $payment->paid_at?->toIso8601String(),
            'payment_batch_uuid' => $payment->payment_batch_uuid,
            'payment_method_id' => $payment->payment_method_id !== null ? (int) $payment->payment_method_id : null,
        ])->values()->all();

        $paidCents = array_sum(array_column($payments, 'amount_cents'));

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'invoice' => [
                'id' => (int) $invoice->id,
                'invoice_number' => (string) $invoice->invoice_number,
                'status' => (string) $invoice->status,
                'branch_id' => (int) $invoice->branch_id,
                'clinic_visit_id' => (int) $invoice->clinic_visit_id,
                'patient_id' => (int) $invoice->patient_id,
                'medical_record_id' => $invoice->medical_record_id !== null ? (int) $invoice->medical_record_id : null,
                'correction_version' => (int) ($invoice->correction_version ?? 0),
                'created_at' => $invoice->created_at?->toIso8601String(),
            ],
            'branch' => [
                'id' => (int) $invoice->branch_id,
                'code' => $invoice->branch?->code,
                'name' => $invoice->branch?->name,
                'address' => $invoice->branch?->address,
                'phone' => $invoice->branch?->phone,
            ],
            'items' => $items,
            'payments' => $payments,
            'totals' => [
                'subtotal_cents' => RmeInvoiceDocument::toCents($invoice->subtotal),
                'discount_total_cents' => RmeInvoiceDocument::toCents($invoice->discount_total),
                'grand_total_cents' => RmeInvoiceDocument::toCents($invoice->grand_total),
                'paid_cents' => $paidCents,
            ],
        ];
    }

    /**
     * The financial fingerprint (see class doc for what it covers and why).
     *
     * @param  array<string, mixed>  $snapshot
     */
    public static function fingerprint(array $snapshot): string
    {
        $items = array_map(fn (array $item) => [
            $item['id'] ?? null,
            $item['treatment_id'] ?? null,
            $item['description'] ?? null,
            $item['qty'] ?? null,
            $item['unit_price_cents'] ?? null,
            $item['discount_cents'] ?? null,
            $item['subtotal_cents'] ?? null,
            $item['doctor_id'] ?? null,
        ], $snapshot['items'] ?? []);

        $payments = array_map(fn (array $payment) => [
            $payment['id'] ?? null,
            $payment['amount_cents'] ?? null,
        ], $snapshot['payments'] ?? []);

        return hash('sha256', self::encode([
            'v' => (int) ($snapshot['schema_version'] ?? 0),
            'invoice' => [
                $snapshot['invoice']['id'] ?? null,
                $snapshot['invoice']['status'] ?? null,
                $snapshot['invoice']['correction_version'] ?? null,
            ],
            'items' => $items,
            'payments' => $payments,
            'totals' => [
                $snapshot['totals']['subtotal_cents'] ?? null,
                $snapshot['totals']['discount_total_cents'] ?? null,
                $snapshot['totals']['grand_total_cents'] ?? null,
                $snapshot['totals']['paid_cents'] ?? null,
            ],
        ]));
    }

    /**
     * Integrity hash of the WHOLE stored document (header included), recorded
     * beside every frozen version so tampering with the jsonb is detectable.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public static function documentHash(array $snapshot): string
    {
        return hash('sha256', self::encode($snapshot));
    }

    /**
     * True when a stored snapshot is complete under its declared schema.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public static function isComplete(array $snapshot): bool
    {
        if (($snapshot['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            return false;
        }

        foreach (['invoice', 'branch', 'items', 'payments', 'totals'] as $section) {
            if (! array_key_exists($section, $snapshot) || ! is_array($snapshot[$section])) {
                return false;
            }
        }

        foreach (['id', 'invoice_number', 'status', 'branch_id', 'correction_version'] as $key) {
            if (! array_key_exists($key, $snapshot['invoice'])) {
                return false;
            }
        }

        foreach (['subtotal_cents', 'discount_total_cents', 'grand_total_cents', 'paid_cents'] as $key) {
            if (! is_int($snapshot['totals'][$key] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Rupiah string for a decimal(15,2) column from integer cents.
     */
    public static function centsToDecimal(int $cents): string
    {
        $negative = $cents < 0;
        $abs = abs($cents);

        return ($negative ? '-' : '').intdiv($abs, 100).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Canonical JSON: associative arrays are key-sorted recursively, lists keep
     * their order. PostgreSQL `jsonb` does not preserve object key order, so a
     * hash over the stored value is only reproducible if the encoding never
     * depends on it.
     *
     * @param  array<mixed>  $value
     */
    private static function encode(array $value): string
    {
        return (string) json_encode(self::canonical($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map(fn ($child) => self::canonical($child), $value);

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }
}
