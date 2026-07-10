<x-settings-shell title="Lab Eksternal">
    <x-ui.page-header title="Lab Eksternal" subtitle="Master data lab rekanan untuk jalur pengerjaan eksternal.">
        <x-slot:breadcrumb>Lab / Lab Eksternal</x-slot:breadcrumb>
        <x-slot:actions>
            <x-ui.button :href="route('lab-v2-orders.index')" variant="secondary">Pipeline V2</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if (session('success'))
        <x-ui.alert variant="success" class="mb-4">{{ session('success') }}</x-ui.alert>
    @endif
    @if ($errors->any())
        <x-ui.alert variant="danger" class="mb-4">
            <ul class="list-disc pl-4">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </x-ui.alert>
    @endif

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <x-ui.card>
                @if ($externalLabs->isEmpty())
                    <x-ui.empty-state title="Belum ada lab eksternal" description="Tambahkan lab rekanan untuk mengaktifkan jalur pengerjaan eksternal." />
                @else
                    <x-ui.table>
                        <x-slot:head>
                            <tr>
                                <th class="px-4 py-3 text-left">Nama</th>
                                <th class="px-4 py-3 text-left">Kontak</th>
                                <th class="px-4 py-3 text-left">Pengiriman</th>
                                <th class="px-4 py-3 text-left">Status</th>
                            </tr>
                        </x-slot:head>
                        @foreach ($externalLabs as $lab)
                            <tr class="border-t border-hairline">
                                <td class="px-4 py-3 font-medium text-ink">{{ $lab->name }}</td>
                                <td class="px-4 py-3 text-ink-soft">{{ $lab->phone ?? '—' }}</td>
                                <td class="px-4 py-3 text-ink-soft">{{ $lab->dispatches_count }}</td>
                                <td class="px-4 py-3">
                                    <x-ui.badge :tone="$lab->is_active ? 'success' : 'danger'">{{ $lab->is_active ? 'Aktif' : 'Nonaktif' }}</x-ui.badge>
                                </td>
                            </tr>
                        @endforeach
                    </x-ui.table>
                    <div class="mt-4">{{ $externalLabs->links() }}</div>
                @endif
            </x-ui.card>
        </div>
        <div>
            <x-ui.card title="Tambah Lab Eksternal">
                <form method="POST" action="{{ route('lab-external-labs.store') }}" class="space-y-2">
                    @csrf
                    <x-ui.input name="name" label="Nama" required />
                    <x-ui.input name="phone" label="Telepon" />
                    <x-ui.input type="email" name="email" label="Email" />
                    <x-ui.textarea name="address" label="Alamat" rows="2"></x-ui.textarea>
                    <x-ui.button type="submit" class="w-full">Simpan</x-ui.button>
                </form>
            </x-ui.card>
        </div>
    </div>
</x-settings-shell>
