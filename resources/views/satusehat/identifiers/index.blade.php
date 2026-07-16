<x-settings-shell title="Identifier Entitas SATUSEHAT">
    <x-ui.page-header title="Identifier Entitas SATUSEHAT (IHS)" subtitle="Diinput/diverifikasi manual. Tidak ada lookup eksternal. Sandbox &amp; produksi tidak dicampur.">
        <x-slot:breadcrumb>Rekam Medis Elektronik / SATUSEHAT</x-slot:breadcrumb>
    </x-ui.page-header>

    @if (session('success'))
        <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
    @endif
    @if ($errors->any())
        <x-ui.alert variant="danger">{{ $errors->first() }}</x-ui.alert>
    @endif

    <x-ui.card title="Tambah / Perbarui Identifier">
        <form method="POST" action="{{ route('satusehat.identifiers.store') }}" class="grid gap-4 sm:grid-cols-2">
            @csrf
            <x-ui.select label="Lingkungan" name="environment" required>
                @foreach ($environments as $env)
                    <option value="{{ $env }}">{{ $env }}</option>
                @endforeach
            </x-ui.select>
            <x-ui.select label="Tipe entitas" name="entity_type" required>
                @foreach ($entityTypes as $type)
                    <option value="{{ $type }}">{{ $type }}</option>
                @endforeach
            </x-ui.select>
            <x-ui.input label="Tipe entitas lokal (mis. patient/doctor/branch/clinic_room)" name="local_entity_type" :value="old('local_entity_type')" required />
            <x-ui.input label="ID entitas lokal" name="local_entity_id" type="number" :value="old('local_entity_id')" required />
            <x-ui.input label="Identifier IHS/SATUSEHAT" name="remote_identifier" :value="old('remote_identifier')" required />
            <x-ui.input label="Sistem identifier" name="identifier_system" :value="old('identifier_system')" />
            <div class="sm:col-span-2">
                <x-ui.button type="submit" variant="primary">Simpan Identifier</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <x-ui.filter-bar :action="route('satusehat.identifiers.index')" method="GET" class="mt-4">
        <x-ui.select label="Lingkungan" name="environment">
            <option value="">Semua</option>
            @foreach ($environments as $env)
                <option value="{{ $env }}" @selected($filters['environment'] === $env)>{{ $env }}</option>
            @endforeach
        </x-ui.select>
        <x-ui.select label="Tipe entitas" name="entity_type">
            <option value="">Semua</option>
            @foreach ($entityTypes as $type)
                <option value="{{ $type }}" @selected($filters['entity_type'] === $type)>{{ $type }}</option>
            @endforeach
        </x-ui.select>
        <x-ui.select label="Status" name="status">
            <option value="">Semua</option>
            @foreach ($statuses as $status)
                <option value="{{ $status }}" @selected($filters['status'] === $status)>{{ $status }}</option>
            @endforeach
        </x-ui.select>
        <x-slot:actions>
            <x-ui.button type="submit" variant="primary">Terapkan</x-ui.button>
        </x-slot:actions>
    </x-ui.filter-bar>

    <x-ui.card padding="">
        @if ($identifiers->isEmpty())
            <x-ui.empty-state title="Belum ada identifier." />
        @else
            <div class="overflow-x-auto">
                <x-ui.table>
                    <thead class="bg-navy-50"><tr class="text-left text-ink-soft">
                        <th class="px-4 py-3">Lingkungan</th><th class="px-4 py-3">Tipe</th><th class="px-4 py-3">Entitas Lokal</th>
                        <th class="px-4 py-3">Identifier IHS</th><th class="px-4 py-3">Status</th><th class="px-4 py-3">Aksi</th>
                    </tr></thead>
                    <tbody class="divide-y divide-hairline">
                        @foreach ($identifiers as $identifier)
                            <tr>
                                <td class="px-4 py-3">{{ $identifier->environment }}</td>
                                <td class="px-4 py-3">{{ $identifier->entity_type }}</td>
                                <td class="px-4 py-3">{{ $identifier->local_entity_type }} #{{ $identifier->local_entity_id }}</td>
                                <td class="px-4 py-3">{{ $identifier->remote_identifier }}</td>
                                <td class="px-4 py-3"><x-ui.badge :tone="$identifier->statusTone()">{{ $identifier->statusLabel() }}</x-ui.badge></td>
                                <td class="px-4 py-3">
                                    @if ($identifier->isActive())
                                        <form method="POST" action="{{ route('satusehat.identifiers.deactivate', $identifier) }}">@csrf<x-ui.button size="sm" variant="warning" type="submit">Nonaktifkan</x-ui.button></form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-ui.table>
            </div>
        @endif
    </x-ui.card>

    <div class="mt-4">{{ $identifiers->links() }}</div>
</x-settings-shell>
