<x-settings-shell title="Dashboard RME">
    <x-ui.page-header title="Laporan Pembayaran RME">
        <x-slot:breadcrumb>RME · Laporan · Pembayaran</x-slot:breadcrumb>
        <x-slot:actions>
            <x-ui.button variant="secondary" size="sm" :href="route('rme.reports.payments.export', request()->query())">Export Excel</x-ui.button>
            <x-ui.button variant="primary" size="sm" :href="route('rme.reports.payments.print', request()->query())" target="_blank">Cetak / PDF</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.filter-bar :action="route('rme.reports.payments')">
        <div class="w-full md:w-44">
            <x-ui.select name="branch_id" label="Cabang">
                <option value="">Semua cabang RME</option>
                @foreach ($branches as $b)
                    <option value="{{ $b->id }}" @selected($selectedBranchId == $b->id)>{{ $b->name }}</option>
                @endforeach
            </x-ui.select>
        </div>
        <div class="w-full md:w-40">
            <x-ui.input type="date" name="date_from" label="Tanggal Kunjungan Dari" :value="$filters['date_from'] ?? ''" />
        </div>
        <div class="w-full md:w-40">
            <x-ui.input type="date" name="date_to" label="Tanggal Kunjungan Sampai" :value="$filters['date_to'] ?? ''" />
        </div>
        <div class="w-full md:w-44">
            <x-ui.select name="payment_method_id" label="Metode Pembayaran">
                <option value="">Semua metode</option>
                @foreach ($paymentMethods as $method)
                    <option value="{{ $method->id }}" @selected(($filters['payment_method_id'] ?? null) == $method->id)>{{ $method->name }}</option>
                @endforeach
            </x-ui.select>
        </div>
        <div class="w-full md:w-44">
            <x-ui.select name="treatment_id" label="Treatment">
                <option value="">Semua treatment</option>
                @foreach ($treatments as $treatment)
                    <option value="{{ $treatment->id }}" @selected(($filters['treatment_id'] ?? null) == $treatment->id)>{{ $treatment->name }}</option>
                @endforeach
            </x-ui.select>
        </div>
        <div class="w-full md:w-44">
            <x-ui.select name="doctor_id" label="Dokter">
                <option value="">Semua dokter</option>
                @foreach ($doctors as $doctor)
                    <option value="{{ $doctor->id }}" @selected(($filters['doctor_id'] ?? null) == $doctor->id)>{{ $doctor->name }}</option>
                @endforeach
            </x-ui.select>
        </div>
        <div class="w-full md:w-64">
            <x-ui.input type="search" name="q" label="Cari ID / Nama Pasien" :value="$filters['q'] ?? ''" placeholder="ID, RM, atau nama pasien" />
        </div>
        <x-slot:actions>
            <x-ui.button type="submit" variant="primary">Filter</x-ui.button>
            <x-ui.button variant="secondary" :href="route('rme.reports.payments')">Atur Ulang</x-ui.button>
        </x-slot:actions>
    </x-ui.filter-bar>

    <div class="mb-4 grid gap-4 sm:grid-cols-3">
        <x-ui.kpi-card label="Total Pasien Hasil Filter" value="{{ number_format($totalFilteredPatients ?? 0) }} pasien" />
        <x-ui.kpi-card label="Total Baris Transaksi Ditampilkan" value="{{ number_format($payments->count()) }} transaksi" />
        <x-ui.kpi-card label="Total Pembayaran Hasil Filter" value="{{ format_currency_id($totalPaymentAmount ?? 0) }}" accent />
    </div>

    <x-ui.card>
        <x-ui.table>
            <thead>
                <tr class="bg-navy-50 text-left text-ink-soft">
                    <th class="px-3 py-2 font-medium">No</th>
                    <th class="px-3 py-2 font-medium">No. Invoice</th>
                    <th class="px-3 py-2 font-medium">ID / RM Pasien</th>
                    <th class="px-3 py-2 font-medium">Nama Pasien</th>
                    <th class="px-3 py-2 font-medium">Metode Pembayaran</th>
                    <th class="px-3 py-2 font-medium">Treatment</th>
                    <th class="px-3 py-2 font-medium">Dokter</th>
                    <th class="px-3 py-2 font-medium">Cabang</th>
                    <th class="px-3 py-2 font-medium">Tanggal</th>
                    <th class="px-3 py-2 font-medium">Status</th>
                    <th class="px-3 py-2 font-medium text-right">Jumlah</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-hairline">
                @forelse ($payments as $p)
                    @php
                        $treatmentNames = $p->rmeInvoice?->items
                            ?->pluck('treatment.name')
                            ->filter()
                            ->unique()
                            ->values();
                        $doctorNames = collect([$p->clinicVisit?->doctor?->name])
                            ->merge($p->rmeInvoice?->items?->pluck('doctor.name') ?? [])
                            ->filter()
                            ->unique()
                            ->values();
                    @endphp
                    <tr>
                        <td class="px-3 py-2 text-ink-soft">{{ $loop->iteration }}</td>
                        <td class="px-3 py-2 font-medium text-navy">{{ $p->rmeInvoice?->invoice_number ?? '—' }}</td>
                        <td class="px-3 py-2 font-mono text-xs text-ink-soft">{{ $p->patient?->medical_record_number ?? ('#'.$p->patient_id) }}</td>
                        <td class="px-3 py-2 text-ink">{{ $p->patient?->name ?? '—' }}</td>
                        <td class="px-3 py-2 text-ink-soft">{{ $p->paymentMethod?->name ?? '—' }}</td>
                        <td class="px-3 py-2 text-ink-soft">{{ $treatmentNames?->isNotEmpty() ? $treatmentNames->join(', ') : '—' }}</td>
                        <td class="px-3 py-2 text-ink-soft">{{ $doctorNames->isNotEmpty() ? $doctorNames->join(', ') : '—' }}</td>
                        <td class="px-3 py-2 text-ink-soft">{{ $p->branch?->name ?? '—' }}</td>
                        <td class="px-3 py-2 text-ink-soft">{{ $p->paid_at?->format('d/m/Y H:i') ?? '—' }}</td>
                        <td class="px-3 py-2">
                            @if ($p->rmeInvoice?->status)
                                <x-ui.badge :status="strtolower($p->rmeInvoice->status)">{{ $p->rmeInvoice->status }}</x-ui.badge>
                            @else
                                <span class="text-ink-soft">—</span>
                            @endif
                        </td>
                        <td class="px-3 py-2 text-right font-medium text-navy">{{ format_currency_id($p->amount) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="11" class="px-3 py-6">
                            <x-ui.empty-state title="Belum ada pembayaran RME" description="Tidak ada data yang cocok dengan filter saat ini." />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </x-ui.table>
    </x-ui.card>
</x-settings-shell>
