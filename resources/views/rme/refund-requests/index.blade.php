@php
    use App\Modules\ClinicOversight\Support\OversightFormat;
    use App\Modules\RmeInvoice\Support\RmeRefundRequestStatus;
@endphp
{{-- FEATURE-RME-BILLING-REFUND-REQUEST-APPROVAL-1 (rule 215) — the branch-scoped
     refund request queue. Approving never pays: settlement is Sprint 5. --}}
<x-settings-shell title="Permintaan Refund">
    <div class="space-y-6">
        <x-ui.page-header title="Permintaan Refund" subtitle="Permintaan pengembalian kelebihan bayar. Persetujuan tidak membayarkan uang.">
            <x-slot:breadcrumb>Rekam Medis Elektronik · Kasir</x-slot:breadcrumb>
        </x-ui.page-header>

        <div class="flex flex-wrap gap-2 text-sm" data-refund-status-counts>
            @foreach (RmeRefundRequestStatus::ALL as $status)
                <a href="{{ route('rme.refund-requests.index', ['status' => $status]) }}" class="rounded border border-hairline px-3 py-1 {{ ($filters['status'] ?? null) === $status ? 'bg-navy-50 font-semibold' : '' }}">
                    {{ RmeRefundRequestStatus::label($status) }} <span class="tabular-nums">({{ $counts[$status] ?? 0 }})</span>
                </a>
            @endforeach
        </div>

        @if ($rows->isEmpty())
            <x-ui.empty-state title="Belum ada permintaan refund" description="Tidak ada permintaan refund pada cabang Anda." />
        @else
            <x-ui.card padding="">
                <x-ui.table>
                    <thead class="bg-navy-50">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium text-ink-soft">No. Permintaan</th>
                            <th class="px-4 py-3 text-left font-medium text-ink-soft">Diajukan</th>
                            <th class="px-4 py-3 text-left font-medium text-ink-soft">Cabang</th>
                            <th class="px-4 py-3 text-left font-medium text-ink-soft">Invoice</th>
                            <th class="px-4 py-3 text-right font-medium text-ink-soft">Jumlah</th>
                            <th class="px-4 py-3 text-left font-medium text-ink-soft">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-hairline">
                        @foreach ($rows as $r)
                            <tr data-refund-row="{{ $r->id }}">
                                <td class="px-4 py-3 font-mono"><a class="text-brand-700 hover:underline" href="{{ route('rme.refund-requests.show', $r->id) }}">{{ $r->request_number }}</a></td>
                                <td class="px-4 py-3 text-ink-soft">{{ OversightFormat::clinicalTime($r->requested_at, 'd/m/Y H:i') }} WITA</td>
                                <td class="px-4 py-3">{{ $r->branch?->name }}</td>
                                <td class="px-4 py-3 font-mono">{{ $r->invoice?->invoice_number }}</td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ OversightFormat::rupiah($r->amount) }}</td>
                                <td class="px-4 py-3">{{ RmeRefundRequestStatus::label($r->status) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-ui.table>
            </x-ui.card>
            <div class="mt-3">{{ $rows->links() }}</div>
        @endif
    </div>
</x-settings-shell>
