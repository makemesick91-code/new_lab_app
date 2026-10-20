<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Services;

use App\Modules\RmeInvoice\Interfaces\RmeInvoiceCorrectionRepositoryInterface;
use App\Modules\RmeInvoice\Models\RmeInvoiceCorrectionLine;
use App\Modules\RmeInvoice\Support\RmeInvoiceSnapshot;
use Illuminate\Validation\ValidationException;

/**
 * FEATURE-RME-INVOICE-CORRECTION-RECEIPT-FOUNDATION-1 — turns a submitted list of
 * proposed lines into a SERVER-COMPUTED proposal.
 *
 * WHAT IS NEVER TRUSTED FROM THE REQUEST: a subtotal, a total, a difference, an
 * operation (keep/change/add/remove), a branch. The submitted list is the COMPLETE
 * new item list; each entry may name an `original_item_id` (keep/change of that
 * item); entries without one are additions; original items not named are
 * removals. Every money figure is recomputed here in integer cents.
 *
 * WHAT IS REFUSED (stable codes, fail closed):
 *   CORRECTION_ITEM_UNKNOWN      original_item_id not on this invoice
 *   CORRECTION_ITEM_DUPLICATED   the same original item named twice
 *   CORRECTION_LINE_INVALID      malformed qty / price / discount / description
 *   CORRECTION_LINE_NEGATIVE     discount larger than the line's gross amount
 *   CORRECTION_TREATMENT_INVALID changed/added line names an unknown or inactive treatment
 *   CORRECTION_DOCTOR_INVALID    unknown doctor
 *   CORRECTION_RP0_NOT_PERMITTED a line would be NEWLY zero, or the invoice total
 *                                would be zero — Rp0 policy (D11, D21, D22) is undecided,
 *                                so a correction can never become an Rp0 path. An
 *                                unchanged pre-existing zero line is kept as it is.
 *   CORRECTION_NO_CHANGE         nothing differs from the original document
 *   CORRECTION_EMPTY             no resulting line
 *   CORRECTION_TOTAL_OVERFLOW    totals do not fit decimal(15,2)
 *
 * PRICE AUTHORITY (owner D21, REVISED 2026-10-20 — REVISION-RME-MANUAL-PRICING-
 * AUTHORITY-1): prices are MANUAL. This builder IS the server-side price
 * validation of a correction, and applies the same rules invoice creation
 * applies (a typed unit price, non-negative, at most 2 decimals, discount not
 * above the gross, overflow refused, totals recomputed). No tariff is compared;
 * no discount cap or deviation cap is invented (D21c remains a pending owner
 * decision); the Rp0 refusal stays. The capability still ships behind a
 * default-OFF flag (activation is a separate ceremony).
 */
final class RmeInvoiceCorrectionProposalBuilder
{
    public const MAX_LINES = 100;

    public const MAX_QTY = 10000;

    /** decimal(15,2) holds at most 9,999,999,999,999.99. */
    public const MAX_CENTS = 999_999_999_999_999;

    private const MONEY_PATTERN = '/^\d{1,13}(?:\.\d{1,2})?$/D';

    public function __construct(
        private readonly RmeInvoiceCorrectionRepositoryInterface $corrections,
    ) {}

    /**
     * @param  array<string, mixed>  $originalSnapshot  RmeInvoiceSnapshot::capture() of the LOCKED invoice
     * @param  array<int, array<string, mixed>>  $submittedLines
     * @return array{
     *     lines: list<array<string, mixed>>,
     *     items: list<array<string, mixed>>,
     *     subtotal_cents: int,
     *     discount_total_cents: int,
     *     grand_total_cents: int,
     *     original_grand_total_cents: int
     * }
     */
    public function build(array $originalSnapshot, array $submittedLines): array
    {
        if ($submittedLines === []) {
            $this->refuse('CORRECTION_EMPTY', 'Koreksi harus menyisakan minimal satu item tagihan.');
        }

        if (count($submittedLines) > self::MAX_LINES) {
            $this->refuse('CORRECTION_LINE_INVALID', 'Jumlah item koreksi melebihi batas '.self::MAX_LINES.'.');
        }

        /** @var array<int, array<string, mixed>> $originalItems */
        $originalItems = [];
        foreach ($originalSnapshot['items'] ?? [] as $item) {
            $originalItems[(int) $item['id']] = $item;
        }

        $normalized = [];
        $named = [];
        foreach (array_values($submittedLines) as $index => $raw) {
            $line = $this->normalizeLine(is_array($raw) ? $raw : [], $index);

            if ($line['original_item_id'] !== null) {
                if (! array_key_exists($line['original_item_id'], $originalItems)) {
                    $this->refuse('CORRECTION_ITEM_UNKNOWN', 'Item asal pada baris '.($index + 1).' bukan bagian dari tagihan ini.');
                }
                if (isset($named[$line['original_item_id']])) {
                    $this->refuse('CORRECTION_ITEM_DUPLICATED', 'Item asal yang sama dicantumkan lebih dari sekali.');
                }
                $named[$line['original_item_id']] = true;

                $original = $originalItems[$line['original_item_id']];

                // An existing item's treatment may be CHANGED to another active
                // treatment (visible to the approver), never silently CLEARED:
                // treatment decides whether a lab candidate is created after
                // payment, and an inactive treatment that the form could not
                // offer must not turn into "no treatment" by accident.
                if ($original['treatment_id'] !== null && $line['treatment_id'] === null) {
                    $this->refuse('CORRECTION_TREATMENT_INVALID', 'Tindakan item asal pada baris '.($index + 1).' tidak boleh dikosongkan.');
                }

                // Re-attributing an existing item to another doctor changes doctor
                // income attribution — a policy the owner has not decided. The
                // form never offers it; a crafted request is refused, not stored.
                if ($original['doctor_id'] !== $line['doctor_id']) {
                    $this->refuse('CORRECTION_DOCTOR_CHANGE_NOT_PERMITTED', 'Dokter pada item asal baris '.($index + 1).' tidak dapat diubah melalui koreksi tagihan.');
                }
            }

            $normalized[] = $line;
        }

        // Master-data checks, one query each, only for values that CHANGE —
        // an unchanged kept line is not re-litigated against today's master.
        $treatmentIds = [];
        $doctorIds = [];
        foreach ($normalized as $line) {
            $original = $line['original_item_id'] !== null ? $originalItems[$line['original_item_id']] : null;
            if ($line['treatment_id'] !== null && ($original === null || $original['treatment_id'] !== $line['treatment_id'])) {
                $treatmentIds[] = $line['treatment_id'];
            }
            if ($line['doctor_id'] !== null && ($original === null || $original['doctor_id'] !== $line['doctor_id'])) {
                $doctorIds[] = $line['doctor_id'];
            }
        }

        $activeTreatments = $this->corrections->activeTreatments(array_values(array_unique($treatmentIds)));
        foreach (array_unique($treatmentIds) as $id) {
            if (! $activeTreatments->has($id)) {
                $this->refuse('CORRECTION_TREATMENT_INVALID', 'Tindakan yang dipilih tidak ditemukan atau tidak aktif.');
            }
        }

        $existingDoctors = $this->corrections->existingDoctorIds(array_values(array_unique($doctorIds)));
        foreach (array_unique($doctorIds) as $id) {
            if (! in_array($id, $existingDoctors, true)) {
                $this->refuse('CORRECTION_DOCTOR_INVALID', 'Dokter yang dipilih tidak ditemukan.');
            }
        }

        $lines = [];
        $items = [];
        $lineNo = 0;
        $subtotal = 0;
        $discountTotal = 0;
        $changed = false;

        foreach ($normalized as $line) {
            $original = $line['original_item_id'] !== null ? $originalItems[$line['original_item_id']] : null;

            // Refuse BEFORE multiplying: past PHP_INT_MAX the product silently
            // becomes a float and the cents arithmetic stops being exact.
            if ($line['unit_price_cents'] > intdiv(self::MAX_CENTS, max(1, $line['qty']))) {
                $this->refuse('CORRECTION_TOTAL_OVERFLOW', 'Nilai item "'.$this->clip($line['description']).'" melebihi batas yang dapat disimpan.');
            }

            $gross = $line['qty'] * $line['unit_price_cents'];
            $net = $gross - $line['discount_cents'];

            if ($net < 0) {
                $this->refuse('CORRECTION_LINE_NEGATIVE', 'Diskon pada item "'.$this->clip($line['description']).'" melebihi nilai item.');
            }

            $operation = $original === null
                ? RmeInvoiceCorrectionLine::OP_ADD
                : ($this->sameAsOriginal($line, $original) ? RmeInvoiceCorrectionLine::OP_KEEP : RmeInvoiceCorrectionLine::OP_CHANGE);

            // Rp0 is a policy that does not exist yet (D11/D21/D22). A zero line
            // may survive only as an UNCHANGED copy of a zero line that already
            // existed — the correction never creates one.
            if ($net === 0 && $operation !== RmeInvoiceCorrectionLine::OP_KEEP) {
                $this->refuse('CORRECTION_RP0_NOT_PERMITTED', 'Koreksi tidak dapat membuat item bernilai Rp0. Kebijakan Rp0 belum diputuskan owner.');
            }

            if ($operation !== RmeInvoiceCorrectionLine::OP_KEEP) {
                $changed = true;
            }

            $subtotal += $gross;
            $discountTotal += $line['discount_cents'];

            $lines[] = array_merge(
                ['line_no' => ++$lineNo, 'operation' => $operation],
                $this->originalColumns($original),
                [
                    'proposed_treatment_id' => $line['treatment_id'],
                    'proposed_description' => $line['description'],
                    'proposed_qty' => $line['qty'],
                    'proposed_unit_price' => RmeInvoiceSnapshot::centsToDecimal($line['unit_price_cents']),
                    'proposed_discount' => RmeInvoiceSnapshot::centsToDecimal($line['discount_cents']),
                    'proposed_subtotal' => RmeInvoiceSnapshot::centsToDecimal($net),
                    'proposed_doctor_id' => $line['doctor_id'],
                ],
            );

            $items[] = [
                'original_item_id' => $line['original_item_id'],
                'operation' => $operation,
                'treatment_id' => $line['treatment_id'],
                'description' => $line['description'],
                'qty' => $line['qty'],
                'unit_price_cents' => $line['unit_price_cents'],
                'discount_cents' => $line['discount_cents'],
                'subtotal_cents' => $net,
                'doctor_id' => $line['doctor_id'],
            ];
        }

        foreach ($originalItems as $id => $original) {
            if (isset($named[$id])) {
                continue;
            }
            $changed = true;
            $lines[] = array_merge(
                ['line_no' => ++$lineNo, 'operation' => RmeInvoiceCorrectionLine::OP_REMOVE],
                $this->originalColumns($original),
                [
                    'proposed_treatment_id' => null,
                    'proposed_description' => null,
                    'proposed_qty' => null,
                    'proposed_unit_price' => null,
                    'proposed_discount' => null,
                    'proposed_subtotal' => null,
                    'proposed_doctor_id' => null,
                ],
            );
        }

        if ($items === []) {
            $this->refuse('CORRECTION_EMPTY', 'Koreksi harus menyisakan minimal satu item tagihan.');
        }

        if (! $changed) {
            $this->refuse('CORRECTION_NO_CHANGE', 'Usulan koreksi sama dengan tagihan asli; tidak ada yang dikoreksi.');
        }

        $grandTotal = $subtotal - $discountTotal;

        if ($subtotal > self::MAX_CENTS || $grandTotal > self::MAX_CENTS) {
            $this->refuse('CORRECTION_TOTAL_OVERFLOW', 'Total usulan koreksi melebihi batas yang dapat disimpan.');
        }

        if ($grandTotal <= 0) {
            $this->refuse('CORRECTION_RP0_NOT_PERMITTED', 'Koreksi tidak dapat menjadikan total tagihan Rp0. Kebijakan Rp0 belum diputuskan owner.');
        }

        return [
            'lines' => $lines,
            'items' => $items,
            'subtotal_cents' => $subtotal,
            'discount_total_cents' => $discountTotal,
            'grand_total_cents' => $grandTotal,
            'original_grand_total_cents' => (int) ($originalSnapshot['totals']['grand_total_cents'] ?? 0),
        ];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array{original_item_id: int|null, treatment_id: int|null, description: string, qty: int, unit_price_cents: int, discount_cents: int, doctor_id: int|null}
     */
    private function normalizeLine(array $raw, int $index): array
    {
        $position = 'baris '.($index + 1);

        $description = trim((string) ($raw['description'] ?? ''));
        if ($description === '' || mb_strlen($description) > 255) {
            $this->refuse('CORRECTION_LINE_INVALID', "Deskripsi item pada {$position} wajib diisi (maksimal 255 karakter).");
        }

        $qty = $raw['qty'] ?? null;
        if (! $this->isPositiveInteger($qty) || (int) $qty > self::MAX_QTY) {
            $this->refuse('CORRECTION_LINE_INVALID', "Qty pada {$position} harus bilangan bulat 1–".self::MAX_QTY.'.');
        }

        $unit = $this->money($raw['unit_price'] ?? null, $position, 'Harga satuan', required: true);
        $discount = $this->money($raw['discount'] ?? 0, $position, 'Diskon', required: false);

        return [
            'original_item_id' => $this->optionalId($raw['original_item_id'] ?? null, $position),
            'treatment_id' => $this->optionalId($raw['treatment_id'] ?? null, $position),
            'description' => $description,
            'qty' => (int) $qty,
            'unit_price_cents' => $unit,
            'discount_cents' => $discount,
            'doctor_id' => $this->optionalId($raw['doctor_id'] ?? null, $position),
        ];
    }

    /**
     * @param  array<string, mixed>  $line
     * @param  array<string, mixed>  $original
     */
    private function sameAsOriginal(array $line, array $original): bool
    {
        return $line['treatment_id'] === ($original['treatment_id'] ?? null)
            && $line['description'] === (string) ($original['description'] ?? '')
            && $line['qty'] === (int) ($original['qty'] ?? 0)
            && $line['unit_price_cents'] === (int) ($original['unit_price_cents'] ?? -1)
            && $line['discount_cents'] === (int) ($original['discount_cents'] ?? -1)
            && $line['doctor_id'] === ($original['doctor_id'] ?? null);
    }

    /**
     * @param  array<string, mixed>|null  $original
     * @return array<string, mixed>
     */
    private function originalColumns(?array $original): array
    {
        if ($original === null) {
            return [
                'original_item_id' => null,
                'original_treatment_id' => null,
                'original_description' => null,
                'original_qty' => null,
                'original_unit_price' => null,
                'original_discount' => null,
                'original_subtotal' => null,
                'original_doctor_id' => null,
            ];
        }

        return [
            'original_item_id' => (int) $original['id'],
            'original_treatment_id' => $original['treatment_id'],
            'original_description' => mb_substr((string) $original['description'], 0, 255),
            'original_qty' => (int) $original['qty'],
            'original_unit_price' => RmeInvoiceSnapshot::centsToDecimal((int) $original['unit_price_cents']),
            'original_discount' => RmeInvoiceSnapshot::centsToDecimal((int) $original['discount_cents']),
            'original_subtotal' => RmeInvoiceSnapshot::centsToDecimal((int) $original['subtotal_cents']),
            'original_doctor_id' => $original['doctor_id'],
        ];
    }

    private function money(mixed $value, string $position, string $label, bool $required): int
    {
        if ($value === null || $value === '') {
            if ($required) {
                $this->refuse('CORRECTION_LINE_INVALID', "{$label} pada {$position} wajib diisi.");
            }

            return 0;
        }

        if (is_int($value)) {
            $value = (string) $value;
        } elseif (is_float($value)) {
            $value = number_format($value, 2, '.', '');
        }

        if (! is_string($value) || preg_match(self::MONEY_PATTERN, trim($value)) !== 1) {
            $this->refuse('CORRECTION_LINE_INVALID', "{$label} pada {$position} harus angka tidak negatif dengan maksimal 2 desimal.");
        }

        $parts = explode('.', trim($value));
        $cents = ((int) $parts[0]) * 100 + (int) str_pad($parts[1] ?? '', 2, '0');

        if ($cents > self::MAX_CENTS) {
            $this->refuse('CORRECTION_TOTAL_OVERFLOW', "{$label} pada {$position} melebihi batas yang dapat disimpan.");
        }

        return $cents;
    }

    private function optionalId(mixed $value, string $position): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! $this->isPositiveInteger($value)) {
            $this->refuse('CORRECTION_LINE_INVALID', "Referensi pada {$position} tidak valid.");
        }

        return (int) $value;
    }

    private function isPositiveInteger(mixed $value): bool
    {
        if (is_int($value)) {
            return $value >= 1;
        }

        return is_string($value) && preg_match('/^[1-9]\d{0,9}$/D', $value) === 1;
    }

    private function clip(string $text): string
    {
        return mb_strlen($text) > 40 ? mb_substr($text, 0, 40).'…' : $text;
    }

    private function refuse(string $code, string $message): never
    {
        throw ValidationException::withMessages(['lines' => "[{$code}] {$message}"]);
    }
}
