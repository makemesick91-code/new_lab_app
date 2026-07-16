<x-settings-shell title="Mapping Kode SATUSEHAT">
    <x-ui.page-header title="Mapping Kode SATUSEHAT" subtitle="Mapping lokal → SATUSEHAT bersifat berversi dan tidak diubah di tempat saat aktif.">
        <x-slot:breadcrumb>Rekam Medis Elektronik / SATUSEHAT</x-slot:breadcrumb>
        <x-slot:actions>
            <x-ui.button variant="primary" :href="route('satusehat.mappings.create')">+ Draft Mapping</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if (session('success'))
        <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
    @endif

    <x-ui.filter-bar :action="route('satusehat.mappings.index')" method="GET">
        <x-ui.select label="Lingkungan" name="environment">
            <option value="">Semua</option>
            @foreach ($environments as $env)
                <option value="{{ $env }}" @selected($filters['environment'] === $env)>{{ $env }}</option>
            @endforeach
        </x-ui.select>
        <x-ui.input label="Tipe entitas lokal" name="local_entity_type" :value="$filters['local_entity_type']" />
        <x-ui.select label="Status" name="status">
            <option value="">Semua</option>
            @foreach ($statuses as $status)
                <option value="{{ $status }}" @selected($filters['status'] === $status)>{{ $status }}</option>
            @endforeach
        </x-ui.select>
        <x-ui.input label="Cari kode" name="search" :value="$filters['search']" />
        <x-slot:actions>
            <x-ui.button type="submit" variant="primary">Terapkan</x-ui.button>
            <x-ui.button variant="secondary" :href="route('satusehat.mappings.index')">Atur Ulang</x-ui.button>
        </x-slot:actions>
    </x-ui.filter-bar>

    <x-ui.card padding="">
        @if ($mappings->isEmpty())
            <x-ui.empty-state title="Belum ada mapping." description="Buat draft mapping lalu review dan aktifkan." />
        @else
            <div class="overflow-x-auto">
                <x-ui.table>
                    <thead class="bg-navy-50">
                        <tr class="text-left text-ink-soft">
                            <th class="px-4 py-3">Lingkungan</th>
                            <th class="px-4 py-3">Entitas Lokal</th>
                            <th class="px-4 py-3">Kunci Lokal</th>
                            <th class="px-4 py-3">Resource</th>
                            <th class="px-4 py-3">Kode Target</th>
                            <th class="px-4 py-3">Versi</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-hairline">
                        @foreach ($mappings as $mapping)
                            <tr>
                                <td class="px-4 py-3">{{ $mapping->environment }}</td>
                                <td class="px-4 py-3">{{ $mapping->local_entity_type }}</td>
                                <td class="px-4 py-3">{{ $mapping->local_entity_id ?? $mapping->local_code }}</td>
                                <td class="px-4 py-3">{{ $mapping->target_resource_type }}</td>
                                <td class="px-4 py-3">{{ $mapping->target_code ?? '—' }}</td>
                                <td class="px-4 py-3">v{{ $mapping->version }}</td>
                                <td class="px-4 py-3"><x-ui.badge :tone="$mapping->statusTone()">{{ $mapping->statusLabel() }}</x-ui.badge></td>
                                <td class="px-4 py-3"><x-ui.button size="sm" variant="secondary" :href="route('satusehat.mappings.show', $mapping)">Detail</x-ui.button></td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-ui.table>
            </div>
        @endif
    </x-ui.card>

    <div class="mt-4">{{ $mappings->links() }}</div>
</x-settings-shell>
