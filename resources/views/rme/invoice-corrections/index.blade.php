@php
    use App\Modules\ClinicOversight\Support\OversightFormat;
    use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionStatus;
    use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionType;
    $isApprovals = $mode === 'approvals';
    $title = $isApprovals ? 'Persetujuan Koreksi Tagihan' : 'Koreksi Tagihan RME';
    $route = $isApprovals ? 'rme.invoice-corrections.approvals' : 'rme.invoice-corrections.index';
@endphp
<x-settings-shell :title="$title">
    <div class="space-y-6">
        <x-ui.page-header :title="$title"
            subtitle="Pengajuan koreksi tagihan RME yang belum dibayar. Pengaju tidak pernah dapat menyetujui pengajuannya sendiri.">
            <x-slot:breadcrumb>Rekam Medis Elektronik · Kasir</x-slot:breadcrumb>
        </x-ui.page-header>

        @unless ($capabilityEnabled)
            <x-ui.alert variant="warning" data-correction-capability-off>
                Fitur koreksi tagihan belum diaktifkan. Halaman ini hanya menampilkan riwayat; tidak ada pengajuan,
                persetujuan, atau penerapan yang dapat dilakukan sampai owner memutuskan otoritas persetujuan.
            </x-ui.alert>
        @endunless

        @if (session('success'))
            <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
        @endif

        <div class="flex flex-wrap gap-2 text-xs">
            @foreach (RmeInvoiceCorrectionStatus::ALL as $status)
                <a href="{{ route($route, ['status' => $status]) }}"
                   class="rounded-full border px-3 py-1 {{ ($filters['status'] ?? null) === $status ? 'border-brand-500 bg-brand-50 text-brand-700' : 'border-hairline text-ink-soft hover:bg-navy-50' }}">
                    {{ RmeInvoiceCorrectionStatus::label($status) }} ({{ $counts[$status] ?? 0 }})
                </a>
            @endforeach
            <a href="{{ route($route) }}" class="rounded-full border border-hairline px-3 py-1 text-ink-soft hover:bg-navy-50">Semua</a>
            @unless ($isApprovals)
                <a href="{{ route($route, ['mine' => 1]) }}"
                   class="rounded-full border px-3 py-1 {{ isset($filters['mine']) ? 'border-brand-500 bg-brand-50 text-brand-700' : 'border-hairline text-ink-soft hover:bg-navy-50' }}">Pengajuan Saya</a>
            @endunless
        </div>

        <x-ui.filter-bar :action="route($route)">
            <div class="w-full md:w-72">
                <x-ui.input name="search" label="Cari" :value="$filters['search'] ?? ''" placeholder="No. koreksi, invoice, pasien, No. RM" />
            </div>
            @if (isset($filters['status']))
                <input type="hidden" name="status" value="{{ $filters['status'] }}">
            @endif
        </x-ui.filter-bar>

        @if ($corrections->isEmpty())
            <x-ui.empty-state title="Belum ada pengajuan koreksi"
                description="Pengajuan koreksi tagihan yang Anda berwenang lihat akan tampil di sini." />
        @else
            <x-ui.card padding="">
                <x-ui.table>
                    <thead class="bg-navy-50">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium text-ink-soft">No. Koreksi</th>
                            <th class="px-4 py-3 text-left font-medium text-ink-soft">Diajukan</th>
                            <th class="px-4 py-3 text-left font-medium text-ink-soft">Cabang</th>
                            <th class="px-4 py-3 text-left font-medium text-ink-soft">Pasien</th>
                            <th class="px-4 py-3 text-left font-medium text-ink-soft">Invoice</th>
                            <th class="px-4 py-3 text-right font-medium text-ink-soft">Total Asli</th>
                            <th class="px-4 py-3 text-right font-medium text-ink-soft">Total Usulan</th>
                            <th class="px-4 py-3 text-right font-medium text-ink-soft">Selisih</th>
                            <th class="px-4 py-3 text-left font-medium text-ink-soft">Status</th>
                            <th class="px-4 py-3 text-left font-medium text-ink-soft">Pengaju</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-hairline">
                        @foreach ($corrections as $correction)
                            <tr data-correction-row="{{ $correction->id }}">
                                <td class="px-4 py-3 font-mono text-navy">
                                    <a class="text-brand-700 hover:underline" href="{{ route('rme.invoice-corrections.show', $correction->id) }}">{{ $correction->correction_number }}</a>
                                    <div class="text-xs text-ink-muted">{{ RmeInvoiceCorrectionType::label($correction->correction_type) }}</div>
                                </td>
                                <td class="px-4 py-3 text-ink">{{ OversightFormat::clinicalTime($correction->requested_at, 'd/m/Y H:i') }} WITA</td>
                                <td class="px-4 py-3 text-ink">{{ $correction->branch?->name }}</td>
                                <td class="px-4 py-3 text-navy">{{ $correction->patient?->name }}
                                    <div class="text-xs text-ink-muted font-mono">{{ $correction->patient?->medical_record_number }}</div>
                                </td>
                                <td class="px-4 py-3 font-mono text-navy">{{ $correction->invoice?->invoice_number }}</td>
                                <td class="px-4 py-3 text-right tabular-nums text-ink">Rp {{ number_format((float) $correction->original_grand_total, 0, ',', '.') }}</td>
                                <td class="px-4 py-3 text-right tabular-nums text-ink">Rp {{ number_format((float) $correction->proposed_grand_total, 0, ',', '.') }}</td>
                                <td class="px-4 py-3 text-right tabular-nums {{ (float) $correction->difference_amount < 0 ? 'text-success-700' : 'text-warning-700' }}">Rp {{ number_format((float) $correction->difference_amount, 0, ',', '.') }}</td>
                                <td class="px-4 py-3">
                                    <x-ui.badge :tone="RmeInvoiceCorrectionStatus::tone($correction->status)">{{ RmeInvoiceCorrectionStatus::label($correction->status) }}</x-ui.badge>
                                </td>
                                <td class="px-4 py-3 text-ink-soft">{{ $correction->requester?->name }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-ui.table>
            </x-ui.card>
            <div>{{ $corrections->links() }}</div>
        @endif
    </div>
</x-settings-shell>
