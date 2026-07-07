<x-settings-shell title="Dashboard RME">
    <x-ui.page-header title="Laporan Pasien RME">
        <x-slot:breadcrumb>RME · Laporan · Pasien</x-slot:breadcrumb>
        <x-slot:actions>
            <x-ui.button variant="secondary" size="sm" :href="route('rme.reports.patients.export', request()->query())">Export Excel</x-ui.button>
            <x-ui.button variant="primary" size="sm" :href="route('rme.reports.patients.print', request()->query())" target="_blank">Cetak / PDF</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.filter-bar :action="route('rme.reports.patients')">
        <div class="w-full md:w-48">
            <x-ui.select name="branch_id" label="Cabang">
                <option value="">Semua cabang RME</option>
                @foreach ($branches as $b)
                    <option value="{{ $b->id }}" @selected($selectedBranchId == $b->id)>{{ $b->name }}</option>
                @endforeach
            </x-ui.select>
        </div>
        <div class="w-full md:w-40">
            <x-ui.input type="date" name="date_from" label="Tanggal dari" :value="$filters['date_from'] ?? ''" />
        </div>
        <div class="w-full md:w-40">
            <x-ui.input type="date" name="date_to" label="Tanggal sampai" :value="$filters['date_to'] ?? ''" />
        </div>
        <div class="w-full md:w-44">
            <x-ui.select name="status" label="Status">
                <option value="">Semua status</option>
                @foreach ($statusOptions as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['status'] ?? null) === $value)>{{ $label }}</option>
                @endforeach
            </x-ui.select>
        </div>
        <div class="w-full md:w-64">
            <x-ui.input type="search" name="q" label="Cari ID / Nama Pasien" :value="$filters['q'] ?? ''" placeholder="ID, RM, atau nama pasien" />
        </div>
        <x-slot:actions>
            <x-ui.button type="submit" variant="primary">Filter</x-ui.button>
            <x-ui.button variant="secondary" :href="route('rme.reports.patients')">Atur Ulang</x-ui.button>
        </x-slot:actions>
    </x-ui.filter-bar>

    <div class="mb-4 grid gap-4 sm:grid-cols-2">
        <x-ui.kpi-card label="Total Pasien Hasil Filter" value="{{ number_format($totalFilteredPatients ?? 0) }} pasien" />
        <x-ui.kpi-card label="Total Kunjungan Ditampilkan" value="{{ number_format($totalVisits) }}" />
    </div>

    <x-ui.card>
        <x-ui.table>
            <thead>
                <tr class="bg-navy-50 text-left text-ink-soft">
                    <th class="px-3 py-2 font-medium">No. Kunjungan</th>
                    <th class="px-3 py-2 font-medium">ID / RM Pasien</th>
                    <th class="px-3 py-2 font-medium">Nama Pasien</th>
                    <th class="px-3 py-2 font-medium">Cabang</th>
                    <th class="px-3 py-2 font-medium">Tanggal Kunjungan</th>
                    <th class="px-3 py-2 font-medium">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-hairline">
                @forelse ($visits as $v)
                    <tr>
                        <td class="px-3 py-2 font-medium text-navy">{{ $v->visit_number }}</td>
                        <td class="px-3 py-2 font-mono text-xs text-ink-soft">{{ $v->patient?->medical_record_number ?? ('#'.$v->patient_id) }}</td>
                        <td class="px-3 py-2 text-ink">{{ $v->patient?->name ?? '—' }}</td>
                        <td class="px-3 py-2 text-ink-soft">{{ $v->branch?->name ?? '—' }}</td>
                        <td class="px-3 py-2 text-ink-soft">{{ $v->visit_date?->format('d/m/Y') ?? '—' }}</td>
                        <td class="px-3 py-2">
                            <x-ui.badge :status="$v->status">{{ $statusOptions[$v->status] ?? $v->status }}</x-ui.badge>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-3 py-6">
                            <x-ui.empty-state title="Belum ada kunjungan RME" description="Tidak ada data yang cocok dengan filter saat ini." />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </x-ui.table>
    </x-ui.card>
</x-settings-shell>
