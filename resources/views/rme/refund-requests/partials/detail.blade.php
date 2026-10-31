@php
    use App\Modules\ClinicOversight\Support\OversightFormat;
    use App\Modules\RmeInvoice\Support\RmeRefundRequestStatus;
@endphp
{{-- FEATURE-RME-BILLING-REFUND-REQUEST-APPROVAL-1 — shared read-only detail. Never
     renders any patient identity. The case itself (who filed it, why, and the Owner
     and patient references) is rendered only when the caller passes
     showCaseDetail = true: the operational page, whose maker and reviewers are
     already branch-scoped, so the checker decides on the case and not only on an
     amount. The oversight page leaves it off (no free text there). --}}
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
        <div><dt class="text-ink-soft">Diajukan oleh</dt><dd data-refund-requester>{{ $refund->requester?->name ?? '—' }}</dd></div>
        @if ($refund->decided_by)
            <div><dt class="text-ink-soft">Diputuskan oleh</dt><dd data-refund-decider>{{ $refund->decider?->name ?? '—' }}</dd></div>
        @endif
        @if ($refund->cancelled_by)
            <div><dt class="text-ink-soft">Dibatalkan oleh</dt><dd>{{ $refund->canceller?->name ?? '—' }}</dd></div>
        @endif
    </dl>

    @if ($showCaseDetail ?? false)
        <dl class="mt-4 grid grid-cols-1 gap-3 border-t border-hairline pt-4 text-sm md:grid-cols-2" data-refund-case>
            <div><dt class="text-ink-soft">Referensi persetujuan Owner</dt><dd class="font-mono text-navy" data-refund-owner-reference>{{ $refund->owner_approval_reference }}</dd></div>
            <div><dt class="text-ink-soft">Referensi permintaan pasien</dt><dd class="font-mono" data-refund-patient-reference>{{ $refund->patient_request_reference }}</dd></div>
            <div class="md:col-span-2"><dt class="text-ink-soft">Alasan pengajuan</dt><dd class="whitespace-pre-line" data-refund-reason>{{ $refund->reason_note }}</dd></div>
            @if ($refund->decision_note)
                <div class="md:col-span-2"><dt class="text-ink-soft">Catatan keputusan</dt><dd class="whitespace-pre-line" data-refund-decision-note>{{ $refund->decision_note }}</dd></div>
            @endif
            @if ($refund->cancel_reason)
                <div class="md:col-span-2"><dt class="text-ink-soft">Alasan pembatalan</dt><dd class="whitespace-pre-line">{{ $refund->cancel_reason }}</dd></div>
            @endif
        </dl>
        <p class="mt-2 text-xs text-ink-muted">Sistem mencatat referensi persetujuan Owner, tetapi tidak dapat membuktikan tanda tangan Owner. Periksa formulirnya di kanal klinik sebelum memutuskan.</p>
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
                <tr data-refund-event="{{ $e->id }}">
                    <td class="px-4 py-3 text-ink-soft">{{ OversightFormat::clinicalTime($e->occurred_at, 'd/m/Y H:i') }} WITA</td>
                    <td class="px-4 py-3">{{ $e->from_status ? RmeRefundRequestStatus::label($e->from_status) : '—' }}</td>
                    <td class="px-4 py-3">{{ RmeRefundRequestStatus::label($e->to_status) }}</td>
                </tr>
            @endforeach
        </tbody>
    </x-ui.table>
</x-ui.card>
