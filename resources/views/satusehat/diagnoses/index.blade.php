{{-- SATUSEHAT-4A — Master clinical diagnosis governance (manage_structured_diagnoses).
     Master ≠ SATUSEHAT mapping: mapping tetap lifecycle review terpisah. --}}
<x-settings-shell title="Master Diagnosis Klinis">
    <x-ui.page-header
        title="Master Diagnosis Klinis"
        subtitle="Referensi diagnosis terstruktur (default ICD-10). Entri master TIDAK otomatis siap SATUSEHAT — mapping Condition tetap butuh review klinis.">
        <x-slot:breadcrumb>SATUSEHAT</x-slot:breadcrumb>
    </x-ui.page-header>

    @if (session('status'))
        <x-ui.alert variant="success">{{ session('status') }}</x-ui.alert>
    @endif
    @if ($errors->any())
        <x-ui.alert variant="danger" title="Validasi gagal">{{ $errors->first() }}</x-ui.alert>
    @endif

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <x-ui.card title="Tambah Diagnosis Master">
            <form method="POST" action="{{ route('satusehat.diagnoses.store') }}" class="space-y-3">
                @csrf
                <x-ui.input label="Code System" name="code_system" value="ICD-10" />
                <x-ui.input label="Kode" name="code" required />
                <x-ui.input label="Nama (display)" name="display" required />
                <x-ui.input label="Sumber" name="source" value="WHO ICD-10" />
                <x-ui.button type="submit" class="w-full">Simpan</x-ui.button>
            </form>
        </x-ui.card>

        <x-ui.card title="Daftar Diagnosis" class="lg:col-span-2">
            <x-ui.filter-bar :action="route('satusehat.diagnoses.index')" method="GET">
                <x-ui.input label="Cari (kode / nama)" name="search" :value="$filters['search'] ?? null" />
                <x-slot:actions>
                    <x-ui.button type="submit">Cari</x-ui.button>
                </x-slot:actions>
            </x-ui.filter-bar>

            <div class="mt-3 overflow-x-auto">
                <x-ui.table>
                    <thead>
                        <tr>
                            <th class="px-3 py-2 text-left">Kode</th>
                            <th class="px-3 py-2 text-left">Nama</th>
                            <th class="px-3 py-2 text-left">Status</th>
                            <th class="px-3 py-2 text-left">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($diagnoses as $dx)
                            <tr class="border-t border-hairline">
                                <td class="px-3 py-2 font-mono text-sm">{{ $dx->code_system }} {{ $dx->code }}</td>
                                <td class="px-3 py-2">{{ $dx->display }}</td>
                                <td class="px-3 py-2"><x-ui.badge :tone="$dx->isActive() ? 'success' : 'neutral'">{{ $dx->status }}</x-ui.badge></td>
                                <td class="px-3 py-2">
                                    @if ($dx->isActive())
                                        <form method="POST" action="{{ route('satusehat.diagnoses.deprecate', $dx) }}">
                                            @csrf
                                            <x-ui.button size="sm" type="submit" variant="warning">Nonaktifkan</x-ui.button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-3 py-6">
                                    <x-ui.empty-state title="Belum ada diagnosis master" />
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </x-ui.table>
            </div>
            <div class="mt-3">{{ $diagnoses->links() }}</div>
        </x-ui.card>
    </div>
</x-settings-shell>
