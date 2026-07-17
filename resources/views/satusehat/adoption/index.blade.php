{{-- SATUSEHAT-4B — structured diagnosis adoption dashboard (read-only,
     PII-free). Indikator kualitas operasional — bukan peringkat punitif
     dokter. Angka tanpa penyebut ditampilkan sebagai N/A, bukan 0%. --}}
<x-settings-shell title="Adopsi Diagnosis Terstruktur">
    <x-ui.page-header
        title="Adopsi Diagnosis Terstruktur"
        subtitle="Kelengkapan diagnosis terstruktur per cabang & dokter (indikator kualitas operasional, bukan peringkat). Periode {{ $metrics['period']['from'] }} s/d {{ $metrics['period']['to'] }}.">
        <x-slot:breadcrumb>SATUSEHAT</x-slot:breadcrumb>
    </x-ui.page-header>

    <x-ui.filter-bar :action="route('satusehat.adoption.index')" method="GET">
        <x-ui.input type="date" label="Dari" name="from" :value="$filters['from'] ?? null" />
        <x-ui.input type="date" label="Sampai" name="to" :value="$filters['to'] ?? null" />
        <x-ui.select label="Cabang" name="branch_id">
            <option value="">Semua cabang RME</option>
            @foreach ($branches as $branch)
                <option value="{{ $branch->id }}" @selected(($filters['branch_id'] ?? null) === $branch->id)>{{ $branch->name }}</option>
            @endforeach
        </x-ui.select>
        <x-slot:actions>
            <x-ui.button type="submit">Terapkan</x-ui.button>
        </x-slot:actions>
    </x-ui.filter-bar>

    <div class="mt-4 grid grid-cols-2 gap-4 md:grid-cols-4">
        <x-ui.kpi-card label="Kunjungan Eligible" :value="$metrics['eligible_visits']" />
        <x-ui.kpi-card label="Dengan Diagnosis" :value="$metrics['with_structured_diagnosis']" />
        <x-ui.kpi-card label="Adoption Rate" :value="$metrics['adoption_rate'] !== null ? $metrics['adoption_rate'].'%' : 'N/A'" />
        <x-ui.kpi-card label="Primary Completeness" :value="$metrics['primary_completeness_rate'] !== null ? $metrics['primary_completeness_rate'].'%' : 'N/A'" />
        <x-ui.kpi-card label="Diagnosis Sekunder" :value="$metrics['secondary_diagnosis_count']" />
        <x-ui.kpi-card label="Terminologi Nonaktif Terpakai" :value="$metrics['deprecated_diagnosis_usage']" />
        <x-ui.kpi-card label="Override Darurat" :value="$metrics['override_count']" />
        <x-ui.kpi-card label="Kandidat Source Changed" :value="$metrics['source_changed_candidates']" />
    </div>

    <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
        <x-ui.card title="Adopsi per Cabang">
            <div class="overflow-x-auto">
                <x-ui.table>
                    <thead>
                        <tr>
                            <th class="px-3 py-2 text-left">Cabang</th>
                            <th class="px-3 py-2 text-right">Eligible</th>
                            <th class="px-3 py-2 text-right">Dengan Dx</th>
                            <th class="px-3 py-2 text-right">Primary</th>
                            <th class="px-3 py-2 text-right">Adopsi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($metrics['per_branch'] as $row)
                            <tr class="border-t border-hairline">
                                <td class="px-3 py-2">{{ $row['branch_name'] }}</td>
                                <td class="px-3 py-2 text-right">{{ $row['eligible'] }}</td>
                                <td class="px-3 py-2 text-right">{{ $row['with_diagnosis'] }}</td>
                                <td class="px-3 py-2 text-right">{{ $row['with_primary'] }}</td>
                                <td class="px-3 py-2 text-right">{{ $row['adoption_rate'] !== null ? $row['adoption_rate'].'%' : 'N/A' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-3 py-6"><x-ui.empty-state title="Belum ada data" /></td></tr>
                        @endforelse
                    </tbody>
                </x-ui.table>
            </div>
        </x-ui.card>

        <x-ui.card title="Adopsi per Dokter (indikator operasional)">
            <div class="overflow-x-auto">
                <x-ui.table>
                    <thead>
                        <tr>
                            <th class="px-3 py-2 text-left">Dokter</th>
                            <th class="px-3 py-2 text-right">Eligible</th>
                            <th class="px-3 py-2 text-right">Dengan Dx</th>
                            <th class="px-3 py-2 text-right">Primary</th>
                            <th class="px-3 py-2 text-right">Adopsi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($metrics['per_doctor'] as $row)
                            <tr class="border-t border-hairline">
                                <td class="px-3 py-2">{{ $row['doctor_name'] }}</td>
                                <td class="px-3 py-2 text-right">{{ $row['eligible'] }}</td>
                                <td class="px-3 py-2 text-right">{{ $row['with_diagnosis'] }}</td>
                                <td class="px-3 py-2 text-right">{{ $row['with_primary'] }}</td>
                                <td class="px-3 py-2 text-right">{{ $row['adoption_rate'] !== null ? $row['adoption_rate'].'%' : 'N/A' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-3 py-6"><x-ui.empty-state title="Belum ada data" /></td></tr>
                        @endforelse
                    </tbody>
                </x-ui.table>
            </div>
        </x-ui.card>
    </div>

    <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
        <x-ui.card title="Isu Kualitas Data Diagnosis (terbuka)">
            @if ($metrics['open_diagnosis_issues'] === [])
                <x-ui.alert variant="success">Tidak ada isu diagnosis terbuka pada scope ini.</x-ui.alert>
            @else
                <ul class="space-y-1 text-sm">
                    @foreach ($metrics['open_diagnosis_issues'] as $rule => $total)
                        <li class="flex justify-between border-b border-hairline py-1">
                            <span class="font-mono">{{ $rule }}</span>
                            <span class="font-medium">{{ $total }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>

        <x-ui.card title="Mode Rollout per Cabang">
            <ul class="space-y-1 text-sm">
                @foreach ($metrics['rollout_modes'] as $row)
                    <li class="flex justify-between border-b border-hairline py-1">
                        <span>{{ $row['branch_name'] }}</span>
                        <x-ui.badge :tone="$row['mode'] === 'pilot_enforced' ? 'danger' : ($row['mode'] === 'warning' ? 'warning' : 'info')">{{ $row['mode'] }}</x-ui.badge>
                    </li>
                @endforeach
            </ul>
        </x-ui.card>
    </div>
</x-settings-shell>
