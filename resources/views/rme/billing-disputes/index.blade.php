@php
    use App\Modules\ClinicOversight\Support\OversightFormat;
    use App\Modules\RmeInvoice\Support\RmeBillingDisputeReason;
    use App\Modules\RmeInvoice\Support\RmeBillingDisputeStatus;
    $activeTotal = collect(RmeBillingDisputeStatus::ACTIVE)->sum(fn ($s) => $counts[$s] ?? 0);
@endphp
<x-settings-shell title="Ketidaksesuaian Tagihan RME">
    <div class="space-y-6">
        <x-ui.page-header title="Ketidaksesuaian Tagihan RME"
            subtitle="Laporan tagihan yang nominalnya diketahui atau diduga salah. Pembayaran tagihan yang dilaporkan ditahan sampai laporan diselesaikan.">
            <x-slot:breadcrumb>Rekam Medis Elektronik · Kasir</x-slot:breadcrumb>
        </x-ui.page-header>

        @if (session('success'))
            <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
        @endif

        <div class="flex flex-wrap gap-2 text-xs" data-billing-dispute-filters>
            <a href="{{ route('rme.billing-disputes.index') }}"
               class="rounded-full border px-3 py-1 {{ $status === 'ACTIVE' ? 'border-brand-500 bg-brand-50 text-brand-700' : 'border-hairline text-ink-soft hover:bg-navy-50' }}">
                Aktif ({{ $activeTotal }})
            </a>
            @foreach (RmeBillingDisputeStatus::ALL as $value)
                <a href="{{ route('rme.billing-disputes.index', ['status' => $value]) }}"
                   class="rounded-full border px-3 py-1 {{ $status === $value ? 'border-brand-500 bg-brand-50 text-brand-700' : 'border-hairline text-ink-soft hover:bg-navy-50' }}">
                    {{ RmeBillingDisputeStatus::label($value) }} ({{ $counts[$value] ?? 0 }})
                </a>
            @endforeach
            <a href="{{ route('rme.billing-disputes.index', ['status' => 'ALL']) }}"
               class="rounded-full border px-3 py-1 {{ $status === 'ALL' ? 'border-brand-500 bg-brand-50 text-brand-700' : 'border-hairline text-ink-soft hover:bg-navy-50' }}">Semua</a>
        </div>

        @if ($disputes->isEmpty())
            <x-ui.empty-state title="Tidak ada laporan"
                description="Laporan ketidaksesuaian tagihan di cabang yang Anda berwenang lihat akan tampil di sini." />
        @else
            <x-ui.card padding="">
                <x-ui.table>
                    <thead class="bg-navy-50">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium text-ink-soft">No. Laporan</th>
                            <th class="px-4 py-3 text-left font-medium text-ink-soft">Dilaporkan</th>
                            <th class="px-4 py-3 text-left font-medium text-ink-soft">Cabang</th>
                            <th class="px-4 py-3 text-left font-medium text-ink-soft">Invoice</th>
                            <th class="px-4 py-3 text-left font-medium text-ink-soft">Jenis</th>
                            <th class="px-4 py-3 text-left font-medium text-ink-soft">Status</th>
                            <th class="px-4 py-3 text-right font-medium text-ink-soft">Umur</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-hairline">
                        @foreach ($disputes as $dispute)
                            <tr data-billing-dispute-row="{{ $dispute->id }}">
                                <td class="px-4 py-3 font-mono">
                                    <a class="text-brand-700 hover:underline" href="{{ route('rme.billing-disputes.show', $dispute->id) }}">{{ $dispute->dispute_number }}</a>
                                </td>
                                <td class="px-4 py-3 text-ink">{{ OversightFormat::clinicalTime($dispute->reported_at, 'd/m/Y H:i') }} WITA</td>
                                <td class="px-4 py-3 text-ink">{{ $dispute->branch?->code }}</td>
                                <td class="px-4 py-3 font-mono text-navy">{{ $dispute->invoice?->invoice_number }}</td>
                                <td class="px-4 py-3 text-ink">{{ RmeBillingDisputeReason::label($dispute->reason_category) }}</td>
                                <td class="px-4 py-3"><x-ui.badge :tone="RmeBillingDisputeStatus::tone($dispute->status)">{{ RmeBillingDisputeStatus::label($dispute->status) }}</x-ui.badge></td>
                                <td class="px-4 py-3 text-right tabular-nums text-ink-soft">
                                    {{ $dispute->isActive() ? (int) $dispute->reported_at->diffInDays(now()).' hari' : '-' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-ui.table>
            </x-ui.card>
            <div>{{ $disputes->links() }}</div>
        @endif
    </div>
</x-settings-shell>
