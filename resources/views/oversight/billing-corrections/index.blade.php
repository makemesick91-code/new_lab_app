@php
    use App\Modules\ClinicOversight\Services\OversightBillingCorrectionService;
    use App\Modules\ClinicOversight\Support\ClinicalDateRange;
    use App\Modules\ClinicOversight\Support\OversightFormat;
    use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionStatus;
    use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionType;
    $isPatients = $mode === 'patients';
    $title = $isPatients ? 'Daftar Pasien Koreksi' : 'Riwayat & Detail Koreksi';
    $route = $isPatients ? 'oversight.billing-corrections.patients' : 'oversight.billing-corrections.index';
    $period = $range->isSingleDay()
        ? OversightFormat::clinicalDate($range->startDate)
        : OversightFormat::clinicalDate($range->startDate).' – '.OversightFormat::clinicalDate($range->endDate);
@endphp
<x-settings-shell :title="$title">
    <x-ui.page-header :title="$title" subtitle="Koreksi tagihan RME lintas cabang — periode tanggal pengajuan klinik WITA (Asia/Makassar).">
        <x-slot:breadcrumb>Billing Correction · {{ $title }}</x-slot:breadcrumb>
        @if ($canExport && ! $isPatients)
            <x-slot:actions>
                <x-ui.button variant="secondary" size="sm" :href="route('oversight.billing-corrections.export', $carriedFilters)">Export CSV</x-ui.button>
                <x-ui.button variant="primary" size="sm" :href="route('oversight.billing-corrections.print', $carriedFilters)" target="_blank">Cetak / PDF</x-ui.button>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    @include('oversight.partials.readonly-notice')

    @if ($errors->any())
        <x-ui.alert variant="danger">
            Filter tidak valid — data di bawah menampilkan periode {{ $period }}.
        </x-ui.alert>
    @endif

    <x-ui.filter-bar :action="route($route)">
        <div class="w-full md:w-56">
            <x-ui.select name="branch_id" label="Cabang">
                <option value="">Semua cabang RME</option>
                @foreach ($branchOptions as $b)
                    <option value="{{ $b['id'] }}" @selected((string) ($filters['branch_id'] ?? '') === (string) $b['id'])>{{ $b['name'] }}</option>
                @endforeach
            </x-ui.select>
        </div>
        <div class="w-full md:w-44">
            <x-ui.input type="date" name="start_date" label="Tanggal Mulai" :value="$range->startDate" required
                min="{{ ClinicalDateRange::MIN_DATE }}" max="{{ ClinicalDateRange::MAX_DATE }}" />
        </div>
        <div class="w-full md:w-44">
            <x-ui.input type="date" name="end_date" label="Tanggal Akhir" :value="$range->endDate" required
                min="{{ ClinicalDateRange::MIN_DATE }}" max="{{ ClinicalDateRange::MAX_DATE }}" />
        </div>
        <div class="w-full md:w-52">
            <x-ui.select name="status" label="Status">
                <option value="">Semua status</option>
                @foreach (RmeInvoiceCorrectionStatus::ALL as $status)
                    <option value="{{ $status }}" @selected(($filters['status'] ?? null) === $status)>{{ RmeInvoiceCorrectionStatus::label($status) }}</option>
                @endforeach
            </x-ui.select>
        </div>
        <div class="w-full md:w-64">
            <x-ui.input name="search" label="Cari" :value="$filters['search'] ?? ''" placeholder="Pasien, No. RM, invoice, koreksi" />
        </div>
    </x-ui.filter-bar>

    <p class="my-3 text-xs text-ink-soft">Periode: {{ $period }} ({{ $range->dayCount }} hari, inklusif). Kolom “Usulan” dan “Diterapkan” dipisah: koreksi yang disetujui tetapi belum diterapkan tidak mengubah tagihan. Refund belum diimplementasikan.</p>

    @if ($rows->isEmpty())
        <x-ui.empty-state title="Belum ada catatan koreksi" description="Tidak ada pengajuan koreksi tagihan pada periode dan cakupan cabang ini." />
    @elseif ($isPatients)
        <x-ui.card padding="">
            <x-ui.table>
                <thead class="bg-navy-50">
                    <tr>
                        <th class="px-4 py-3 text-left font-medium text-ink-soft">Pasien</th>
                        <th class="px-4 py-3 text-left font-medium text-ink-soft">No. RM</th>
                        <th class="px-4 py-3 text-right font-medium text-ink-soft">Jumlah Pengajuan</th>
                        <th class="px-4 py-3 text-right font-medium text-ink-soft">Diterapkan</th>
                        <th class="px-4 py-3 text-right font-medium text-ink-soft">Selisih Diterapkan</th>
                        <th class="px-4 py-3 text-left font-medium text-ink-soft">Pengajuan Terakhir</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-hairline">
                    @foreach ($rows as $row)
                        <tr>
                            <td class="px-4 py-3 text-navy">{{ $row->patient?->name ?? '—' }}</td>
                            <td class="px-4 py-3 font-mono text-ink">{{ $row->patient?->medical_record_number ?? '—' }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ (int) $row->correction_count }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ (int) $row->applied_count }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ OversightFormat::rupiah($row->applied_difference) }}</td>
                            <td class="px-4 py-3 text-ink-soft">{{ OversightFormat::clinicalTime($row->last_requested_at, 'd/m/Y H:i') }} WITA</td>
                            <td class="px-4 py-3">
                                <a class="text-brand-700 hover:underline" href="{{ route('oversight.billing-corrections.index', array_merge($carriedFilters, ['search' => $row->patient?->medical_record_number])) }}">Lihat Koreksi</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
        </x-ui.card>
        <div class="mt-3">{{ $rows->links() }}</div>
    @else
        <x-ui.card padding="">
            <x-ui.table>
                <thead class="bg-navy-50">
                    <tr>
                        <th class="px-4 py-3 text-left font-medium text-ink-soft">No. Koreksi</th>
                        <th class="px-4 py-3 text-left font-medium text-ink-soft">Diajukan</th>
                        <th class="px-4 py-3 text-left font-medium text-ink-soft">Cabang</th>
                        <th class="px-4 py-3 text-left font-medium text-ink-soft">Pasien / No. RM</th>
                        <th class="px-4 py-3 text-left font-medium text-ink-soft">Invoice</th>
                        <th class="px-4 py-3 text-right font-medium text-ink-soft">Asli</th>
                        <th class="px-4 py-3 text-right font-medium text-ink-soft">Usulan</th>
                        <th class="px-4 py-3 text-right font-medium text-ink-soft">Diterapkan</th>
                        <th class="px-4 py-3 text-right font-medium text-ink-soft">Selisih</th>
                        <th class="px-4 py-3 text-left font-medium text-ink-soft">Persetujuan</th>
                        <th class="px-4 py-3 text-left font-medium text-ink-soft">Penerapan</th>
                        <th class="px-4 py-3 text-left font-medium text-ink-soft">Pengaju / Pemutus</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-hairline">
                    @foreach ($rows as $c)
                        <tr data-oversight-correction-row="{{ $c->id }}">
                            <td class="px-4 py-3 font-mono"><a class="text-brand-700 hover:underline" href="{{ route('oversight.billing-corrections.show', $c->id) }}">{{ $c->correction_number }}</a>
                                <div class="text-xs text-ink-muted">{{ RmeInvoiceCorrectionType::label($c->correction_type) }}</div></td>
                            <td class="px-4 py-3 text-ink-soft">{{ OversightFormat::clinicalTime($c->requested_at, 'd/m/Y H:i') }}</td>
                            <td class="px-4 py-3">{{ $c->branch?->name }}</td>
                            <td class="px-4 py-3 text-navy">{{ $c->patient?->name }}<div class="font-mono text-xs text-ink-muted">{{ $c->patient?->medical_record_number }}</div></td>
                            <td class="px-4 py-3 font-mono">{{ $c->invoice?->invoice_number }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ OversightFormat::rupiah($c->original_grand_total) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ OversightFormat::rupiah($c->proposed_grand_total) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ $c->isApplied() ? OversightFormat::rupiah($c->proposed_grand_total) : '—' }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ OversightFormat::rupiah($c->difference_amount) }}</td>
                            <td class="px-4 py-3">{{ OversightBillingCorrectionService::approvalStatus($c) }}</td>
                            <td class="px-4 py-3">{{ OversightBillingCorrectionService::applicationStatus($c) }}</td>
                            <td class="px-4 py-3 text-ink-soft">{{ $c->requester?->name }} / {{ $c->decider?->name ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
        </x-ui.card>
        <div class="mt-3">{{ $rows->links() }}</div>
    @endif
</x-settings-shell>
