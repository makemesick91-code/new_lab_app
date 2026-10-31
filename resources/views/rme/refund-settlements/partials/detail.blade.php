@php
    use App\Modules\ClinicOversight\Support\OversightFormat;
    use App\Modules\RmeInvoice\Support\RmeRefundSettlementMethod;
    use App\Modules\RmeInvoice\Support\RmeRefundSettlementStatus;
@endphp
{{-- FEATURE-RME-REFUND-SETTLEMENT-1 (rule 216) — shared read-only detail of one
     refund payout, used by the operational page and the oversight page. Never renders
     a patient identity, a full account number or a bank credential: only the bank
     name and the last four digits the settler typed. The proof file is linked only
     when the caller passes an evidenceUrl (the oversight page never does). --}}
<x-ui.card data-settlement-detail="{{ $settlement->id }}">
    @if ($settlement->status === RmeRefundSettlementStatus::DISBURSING)
        <x-ui.alert variant="warning" data-settlement-disbursing>
            <strong>SEDANG DIBAYARKAN — UANG BELUM TERCATAT KELUAR.</strong>
            Konfirmasi hanya setelah transfer/serah terima benar-benar terjadi, dengan bukti.
        </x-ui.alert>
    @elseif ($settlement->status === RmeRefundSettlementStatus::OUTCOME_UNKNOWN)
        <x-ui.alert variant="danger" data-settlement-unknown>
            <strong>HASIL PEMBAYARAN TIDAK PASTI.</strong>
            Jangan membayar ulang. Rekonsiliasi dengan mutasi rekening atau tanda terima, lalu konfirmasi atau batalkan dengan bukti.
        </x-ui.alert>
    @elseif ($settlement->status === RmeRefundSettlementStatus::SETTLED)
        <x-ui.alert variant="success" data-settlement-settled>
            <strong>TELAH DIBAYARKAN.</strong> Kewajiban pengembalian dan permintaan refund ditutup oleh pencatatan ini.
        </x-ui.alert>
    @elseif ($settlement->status === RmeRefundSettlementStatus::ABORTED)
        <x-ui.alert variant="info" data-settlement-aborted>
            <strong>DIBATALKAN — TIDAK ADA UANG YANG KELUAR.</strong> Permintaan refund tetap Disetujui dan dapat dibayarkan ulang.
        </x-ui.alert>
    @endif

    <dl class="mt-3 grid grid-cols-1 gap-3 text-sm md:grid-cols-2">
        <div><dt class="text-ink-soft">Status</dt><dd class="font-medium" data-settlement-status>{{ RmeRefundSettlementStatus::label($settlement->status) }}</dd></div>
        <div><dt class="text-ink-soft">Metode</dt><dd data-settlement-method>{{ RmeRefundSettlementMethod::label($settlement->method) }}</dd></div>
        <div><dt class="text-ink-soft">Jumlah</dt><dd class="tabular-nums font-semibold" data-settlement-amount>{{ OversightFormat::rupiah($settlement->amount) }}</dd></div>
        <div><dt class="text-ink-soft">Cabang tagihan</dt><dd>{{ $settlement->branch?->name }}</dd></div>
        <div><dt class="text-ink-soft">Invoice</dt><dd class="font-mono">{{ $settlement->invoice?->invoice_number }}</dd></div>
        <div><dt class="text-ink-soft">Permintaan refund</dt><dd class="font-mono">{{ $settlement->refundRequest?->request_number }}</dd></div>
        <div><dt class="text-ink-soft">Kewajiban</dt><dd class="font-mono">{{ $settlement->obligation?->obligation_number }}</dd></div>
        <div><dt class="text-ink-soft">Disetujui oleh</dt><dd data-settlement-approver>{{ $settlement->approver?->name ?? '—' }}</dd></div>
        <div><dt class="text-ink-soft">Mulai dibayarkan</dt><dd>{{ OversightFormat::clinicalTime($settlement->started_at, 'd/m/Y H:i') }} WITA — {{ $settlement->starter?->name ?? '—' }}</dd></div>
        @if ($settlement->outcome_unknown_at)
            <div><dt class="text-ink-soft">Dilaporkan tidak pasti</dt><dd>{{ OversightFormat::clinicalTime($settlement->outcome_unknown_at, 'd/m/Y H:i') }} WITA — {{ $settlement->unknownReporter?->name ?? '—' }}</dd></div>
        @endif
        @if ($settlement->settled_at)
            <div><dt class="text-ink-soft">Dicatat dibayarkan (waktu server)</dt><dd data-settlement-settled-at>{{ OversightFormat::clinicalTime($settlement->settled_at, 'd/m/Y H:i') }} WITA — {{ $settlement->settler?->name ?? '—' }}</dd></div>
        @endif
        @if ($settlement->aborted_at)
            <div><dt class="text-ink-soft">Dibatalkan</dt><dd>{{ OversightFormat::clinicalTime($settlement->aborted_at, 'd/m/Y H:i') }} WITA — {{ $settlement->aborter?->name ?? '—' }}</dd></div>
        @endif
    </dl>

    @if ($settlement->status === RmeRefundSettlementStatus::SETTLED)
        <dl class="mt-4 grid grid-cols-1 gap-3 border-t border-hairline pt-4 text-sm md:grid-cols-2" data-settlement-proof>
            <div><dt class="text-ink-soft">{{ $settlement->method === RmeRefundSettlementMethod::CASH ? 'No. tanda terima' : 'Referensi transfer' }}</dt><dd class="font-mono" data-settlement-reference>{{ $settlement->disbursement_reference }}</dd></div>
            @if ($settlement->method === RmeRefundSettlementMethod::BANK_TRANSFER)
                <div><dt class="text-ink-soft">Rekening tujuan</dt><dd data-settlement-destination>{{ $settlement->destination_bank_name }} &middot; ••••&nbsp;{{ $settlement->destination_account_last4 }}</dd></div>
            @else
                <div><dt class="text-ink-soft">Penerima</dt><dd>{{ RmeRefundSettlementMethod::RECIPIENT_LABELS[$settlement->recipient_relation] ?? '—' }}</dd></div>
            @endif
            <div><dt class="text-ink-soft">Bukti</dt>
                <dd>
                    <span class="font-mono text-xs" data-settlement-evidence-hash>SHA-256 {{ substr((string) $settlement->evidence_sha256, 0, 16) }}…</span>
                    @if (! empty($evidenceUrl))
                        &middot; <a class="text-brand-700 hover:underline" href="{{ $evidenceUrl }}" data-settlement-evidence-link>Unduh bukti</a>
                    @endif
                </dd>
            </div>
        </dl>
        <p class="mt-2 text-xs text-ink-muted">Sistem menyimpan bukti dan pernyataan pembayar, tetapi tidak dapat memverifikasi mutasi rekening bank. Cocokkan dengan rekening koran saat rekonsiliasi.</p>
    @endif
</x-ui.card>

<x-ui.card padding="">
    <x-ui.table>
        <thead class="bg-navy-50">
            <tr>
                <th class="px-4 py-3 text-left font-medium text-ink-soft">Waktu</th>
                <th class="px-4 py-3 text-left font-medium text-ink-soft">Dari</th>
                <th class="px-4 py-3 text-left font-medium text-ink-soft">Ke</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-hairline">
            @foreach ($events as $e)
                <tr data-settlement-event="{{ $e->id }}">
                    <td class="px-4 py-3 text-ink-soft">{{ OversightFormat::clinicalTime($e->occurred_at, 'd/m/Y H:i') }} WITA</td>
                    <td class="px-4 py-3">{{ $e->from_status ? RmeRefundSettlementStatus::label($e->from_status) : '—' }}</td>
                    <td class="px-4 py-3">{{ RmeRefundSettlementStatus::label($e->to_status) }}</td>
                </tr>
            @endforeach
        </tbody>
    </x-ui.table>
</x-ui.card>
