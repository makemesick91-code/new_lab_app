@php
    use App\Modules\ClinicOversight\Support\OversightFormat;
    use App\Modules\RmeInvoice\Support\RmeRefundSettlementMethod;
    use App\Modules\RmeInvoice\Support\RmeRefundSettlementStatus;
@endphp
{{-- FEATURE-RME-REFUND-SETTLEMENT-1 (rule 216) — refund payouts in the actor's
     branch scope, and the APPROVED requests still waiting to be paid. A row here is a
     record, never a transfer: the system is not connected to any bank. --}}
<x-settings-shell title="Pembayaran Refund">
    <div class="space-y-6">
        <x-ui.page-header title="Pembayaran Refund" subtitle="Pencatatan pengembalian uang yang telah disetujui. Disetujui belum berarti dibayarkan.">
            <x-slot:breadcrumb>Rekam Medis Elektronik · Kasir</x-slot:breadcrumb>
        </x-ui.page-header>

        @if (session('success'))
            <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
        @endif

        <div class="grid grid-cols-2 gap-3 md:grid-cols-4" data-settlement-counts>
            @foreach (RmeRefundSettlementStatus::ALL as $status)
                <x-ui.card>
                    <p class="text-xs text-ink-soft">{{ RmeRefundSettlementStatus::label($status) }}</p>
                    <p class="mt-1 text-xl font-semibold tabular-nums" data-settlement-count="{{ $status }}">{{ $counts[$status] ?? 0 }}</p>
                </x-ui.card>
            @endforeach
        </div>

        <x-ui.card data-settlement-queue>
            <h2 class="text-sm font-semibold text-ink">Refund disetujui — menunggu dibayarkan</h2>
            <p class="mt-1 text-xs text-ink-soft">Mulai pembayaran hanya jika Anda ditunjuk sebagai pembayar refund cabang ini dan Anda bukan penyetujunya.</p>
            @if ($queue->isEmpty())
                <p class="mt-3 text-sm text-ink-soft">Tidak ada refund disetujui yang menunggu.</p>
            @else
                <ul class="mt-3 divide-y divide-hairline text-sm">
                    @foreach ($queue as $refund)
                        <li class="flex flex-wrap items-center justify-between gap-2 py-2" data-settlement-queue-row="{{ $refund->id }}">
                            <span><span class="font-mono">{{ $refund->request_number }}</span> &middot; {{ $refund->branch?->name }} &middot; <span class="tabular-nums">{{ OversightFormat::rupiah($refund->amount) }}</span></span>
                            @if ($isSettler)
                                <x-ui.button size="sm" variant="secondary" :href="route('rme.refund-settlements.create', $refund->id)">Bayarkan</x-ui.button>
                            @endif
                        </li>
                    @endforeach
                </ul>
                @if ($queue->total() > $queue->count())
                    <p class="mt-2 text-xs text-ink-muted">Menampilkan {{ $queue->count() }} dari {{ $queue->total() }} permintaan disetujui.</p>
                @endif
            @endif
        </x-ui.card>

        <x-ui.filter-bar :action="route('rme.refund-settlements.index')">
            <div class="w-full md:w-60">
                <x-ui.select name="status" label="Status">
                    <option value="">Semua status</option>
                    @foreach (RmeRefundSettlementStatus::ALL as $status)
                        <option value="{{ $status }}" @selected(($filters['status'] ?? null) === $status)>{{ RmeRefundSettlementStatus::label($status) }}</option>
                    @endforeach
                </x-ui.select>
            </div>
            <div class="w-full md:w-64">
                <x-ui.input name="search" label="Cari" :value="$filters['search'] ?? ''" placeholder="No. pembayaran, permintaan, atau invoice" />
            </div>
        </x-ui.filter-bar>

        @if ($rows->isEmpty())
            <x-ui.empty-state title="Belum ada pembayaran refund" description="Belum ada refund yang dicatat dibayarkan pada cakupan cabang Anda." />
        @else
            <x-ui.card padding="">
                <x-ui.table>
                    <thead class="bg-navy-50">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium text-ink-soft">No. Pembayaran</th>
                            <th class="px-4 py-3 text-left font-medium text-ink-soft">Permintaan</th>
                            <th class="px-4 py-3 text-left font-medium text-ink-soft">Cabang</th>
                            <th class="px-4 py-3 text-left font-medium text-ink-soft">Metode</th>
                            <th class="px-4 py-3 text-right font-medium text-ink-soft">Jumlah</th>
                            <th class="px-4 py-3 text-left font-medium text-ink-soft">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-hairline">
                        @foreach ($rows as $s)
                            <tr data-settlement-row="{{ $s->id }}">
                                <td class="px-4 py-3 font-mono"><a class="text-brand-700 hover:underline" href="{{ route('rme.refund-settlements.show', $s->id) }}">{{ $s->settlement_number }}</a></td>
                                <td class="px-4 py-3 font-mono">{{ $s->refundRequest?->request_number }}</td>
                                <td class="px-4 py-3">{{ $s->branch?->name }}</td>
                                <td class="px-4 py-3">{{ RmeRefundSettlementMethod::label($s->method) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ OversightFormat::rupiah($s->amount) }}</td>
                                <td class="px-4 py-3"><x-ui.badge :tone="RmeRefundSettlementStatus::TONES[$s->status] ?? 'neutral'">{{ RmeRefundSettlementStatus::label($s->status) }}</x-ui.badge></td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-ui.table>
            </x-ui.card>
            <div class="mt-3">{{ $rows->links() }}</div>
        @endif
    </div>
</x-settings-shell>
