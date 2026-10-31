@php
    use App\Modules\ClinicOversight\Support\OversightFormat;
    use App\Modules\RmeInvoice\Support\RmeRefundRequestStatus;
@endphp
{{-- FEATURE-RME-BILLING-REFUND-REQUEST-APPROVAL-1 — shared read-only detail. Never
     renders the Owner reference, the patient reference or any patient identity. --}}
<x-ui.card data-refund-detail="{{ $refund->id }}">
    @if ($refund->status === RmeRefundRequestStatus::APPROVED)
        <x-ui.alert variant="info" data-refund-approved-unpaid>
            <strong>DISETUJUI — BELUM DIBAYARKAN.</strong> Persetujuan ini bukan bukti pembayaran; belum ada uang yang keluar.
        </x-ui.alert>
    @endif
    <dl class="mt-3 grid grid-cols-1 gap-3 text-sm md:grid-cols-2">
        <div><dt class="text-ink-soft">Status</dt><dd class="font-medium" data-refund-status>{{ RmeRefundRequestStatus::label($refund->status) }}</dd></div>
        <div><dt class="text-ink-soft">Cabang</dt><dd>{{ $refund->branch?->name }}</dd></div>
        <div><dt class="text-ink-soft">Invoice</dt><dd class="font-mono">{{ $refund->invoice?->invoice_number }}</dd></div>
        <div><dt class="text-ink-soft">Kewajiban</dt><dd class="font-mono">{{ $refund->obligation?->obligation_number }}</dd></div>
        <div><dt class="text-ink-soft">Jumlah</dt><dd class="tabular-nums font-semibold">{{ OversightFormat::rupiah($refund->amount) }}</dd></div>
        <div><dt class="text-ink-soft">Diajukan</dt><dd>{{ OversightFormat::clinicalTime($refund->requested_at, 'd/m/Y H:i') }} WITA</dd></div>
        @if ($refund->decided_at)
            <div><dt class="text-ink-soft">Diputuskan</dt><dd>{{ OversightFormat::clinicalTime($refund->decided_at, 'd/m/Y H:i') }} WITA</dd></div>
        @endif
        @if ($refund->cancelled_at)
            <div><dt class="text-ink-soft">Dibatalkan</dt><dd>{{ OversightFormat::clinicalTime($refund->cancelled_at, 'd/m/Y H:i') }} WITA</dd></div>
        @endif
        @if ($refund->stale_reason_code)
            <div><dt class="text-ink-soft">Kedaluwarsa karena</dt><dd class="font-mono">{{ $refund->stale_reason_code }}</dd></div>
        @endif
    </dl>
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
                <tr data-refund-event="{{ $e->id }}">
                    <td class="px-4 py-3 text-ink-soft">{{ OversightFormat::clinicalTime($e->occurred_at, 'd/m/Y H:i') }} WITA</td>
                    <td class="px-4 py-3">{{ $e->from_status ? RmeRefundRequestStatus::label($e->from_status) : '—' }}</td>
                    <td class="px-4 py-3">{{ RmeRefundRequestStatus::label($e->to_status) }}</td>
                </tr>
            @endforeach
        </tbody>
    </x-ui.table>
</x-ui.card>
