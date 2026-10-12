<x-settings-shell title="Semua RME">
    <x-ui.page-header title="Semua RME" subtitle="Pasien dengan RME native atau arsip RME/odontogram lama (PUBLISHED) di seluruh cabang RME.">
        <x-slot:breadcrumb>Finance &amp; Data Analyst · Rekam Medis Elektronik · Semua RME</x-slot:breadcrumb>
        @if ($canExport)
            <x-slot:actions>
                <x-ui.button variant="secondary" size="sm" :href="route('oversight.rme.export', request()->query())">Export CSV</x-ui.button>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    @include('oversight.partials.readonly-notice')

    <x-ui.filter-bar :action="route('oversight.rme.index')">
        <div class="w-full md:w-64">
            <x-ui.input name="search" label="Cari pasien / No. RM / kunjungan / dokter" :value="$filters['search'] ?? ''" />
        </div>
        <div class="w-full md:w-48">
            <x-ui.select name="branch_id" label="Cabang">
                <option value="">Semua cabang RME</option>
                @foreach ($branchOptions as $b)
                    <option value="{{ $b->id }}" @selected((string) ($filters['branch_id'] ?? '') === (string) $b->id)>{{ $b->name }}</option>
                @endforeach
            </x-ui.select>
        </div>
        <div class="w-full md:w-48">
            <x-ui.select name="source" label="Sumber">
                @foreach ($sourceOptions as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['source'] ?? 'all') === $value)>{{ $label }}</option>
                @endforeach
            </x-ui.select>
        </div>
        <div class="w-full md:w-40">
            <x-ui.input type="date" name="visit_date_from" label="Kunjungan dari" :value="$filters['visit_date_from'] ?? ''" />
        </div>
        <div class="w-full md:w-40">
            <x-ui.input type="date" name="visit_date_to" label="Kunjungan sampai" :value="$filters['visit_date_to'] ?? ''" />
        </div>
        <x-slot:actions>
            <x-ui.button type="submit" variant="primary" size="sm">Terapkan</x-ui.button>
            <x-ui.button variant="secondary" size="sm" :href="route('oversight.rme.index')">Atur Ulang</x-ui.button>
        </x-slot:actions>
    </x-ui.filter-bar>

    <div class="mb-4 grid grid-cols-2 gap-3 md:grid-cols-4">
        <x-ui.kpi-card label="Total pasien" :value="number_format($summary['total'] ?? 0, 0, ',', '.')" />
        <x-ui.kpi-card label="RME native" :value="number_format($summary['native'] ?? 0, 0, ',', '.')" />
        <x-ui.kpi-card label="Arsip lama" :value="number_format($summary['legacy'] ?? 0, 0, ',', '.')" />
        <x-ui.kpi-card label="Native + arsip lama" :value="number_format($summary['native_and_legacy'] ?? 0, 0, ',', '.')" />
    </div>

    @if ($patients->isEmpty())
        <x-ui.empty-state title="Tidak ada RME" description="Tidak ada pasien yang cocok dengan filter pada cabang yang dapat Anda lihat." />
    @else
        <x-ui.table>
            <thead class="bg-canvas text-left text-xs uppercase tracking-wide text-ink-soft">
                <tr>
                    <th class="px-4 py-2">No. RM</th>
                    <th class="px-4 py-2">Pasien</th>
                    <th class="px-4 py-2">Cabang Pasien</th>
                    <th class="px-4 py-2">RME Terakhir</th>
                    <th class="px-4 py-2 text-right">Lembar Native</th>
                    <th class="px-4 py-2 text-right">Arsip RME Lama</th>
                    <th class="px-4 py-2 text-right">Arsip Odontogram Lama</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-hairline">
                @foreach ($patients as $patient)
                    @php $latest = $latestNative[$patient->id] ?? null; @endphp
                    <tr>
                        <td class="px-4 py-2 font-mono text-xs text-navy">{{ $patient->medical_record_number }}</td>
                        <td class="px-4 py-2 text-navy">{{ $patient->name }}</td>
                        <td class="px-4 py-2 text-ink-soft">{{ $patient->branch?->name ?? '-' }}</td>
                        <td class="px-4 py-2 text-ink-soft">
                            @if ($latest)
                                {{ $latest->clinicVisit?->visit_date?->format('d-m-Y') ?? '-' }} · {{ $latest->doctor?->name ?? '-' }}
                                <x-ui.badge :tone="$latest->status === 'final' ? 'success' : 'neutral'">{{ $latest->status === 'final' ? 'Final' : 'Draft' }}</x-ui.badge>
                            @else
                                —
                            @endif
                        </td>
                        <td class="px-4 py-2 text-right">{{ (int) $patient->native_count }}</td>
                        <td class="px-4 py-2 text-right">{{ (int) $patient->legacy_rme_count }}</td>
                        <td class="px-4 py-2 text-right">{{ (int) $patient->legacy_odontogram_count }}</td>
                        <td class="px-4 py-2 text-right">
                            <x-ui.button variant="secondary" size="sm" :href="route('oversight.rme.patients.show', $patient->id)">Riwayat RME</x-ui.button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </x-ui.table>

        <div class="mt-4">{{ $patients->links() }}</div>
    @endif
</x-settings-shell>
