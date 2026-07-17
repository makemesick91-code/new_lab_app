{{-- SATUSEHAT-4D — rollout waves. No wave active by default; no wave enables
     external send/production. --}}
<x-settings-shell title="SATUSEHAT — Wave Rollout">
    <x-ui.page-header title="Wave Rollout Multi-Cabang SATUSEHAT"
        subtitle="Rollout bertahap kesiapan internal per gelombang cabang. Tidak mengaktifkan pengiriman eksternal/produksi.">
        <x-slot:breadcrumb>SATUSEHAT · Wave Rollout</x-slot:breadcrumb>
    </x-ui.page-header>

    @if (session('status'))<x-ui.alert variant="success">{{ session('status') }}</x-ui.alert>@endif
    @foreach (['name','wave','branch_id','status','reason'] as $f)@error($f)<x-ui.alert variant="danger">{{ $message }}</x-ui.alert>@enderror @endforeach

    @can('manage_satusehat_rollout_waves')
        <x-ui.card class="my-4">
            <div class="font-semibold mb-2">Buat Wave (draf)</div>
            <form method="POST" action="{{ route('satusehat.waves.store') }}" class="flex flex-wrap gap-2 items-end">
                @csrf
                <x-ui.input name="name" label="Nama wave" required />
                <x-ui.input name="sequence" type="number" label="Urutan" value="1" />
                <x-ui.button type="submit">Buat</x-ui.button>
            </form>
        </x-ui.card>
    @endcan

    <x-ui.table>
        <x-slot:head><tr><th class="text-left">Wave</th><th>Urutan</th><th>Status</th><th>Aktif</th><th>Cabang</th><th></th></tr></x-slot:head>
        @forelse ($waves as $w)
            <tr>
                <td class="text-left">{{ $w->name }}</td>
                <td class="text-center">{{ $w->sequence }}</td>
                <td class="text-center"><x-ui.badge>{{ $w->statusLabel() }}</x-ui.badge></td>
                <td class="text-center">{{ $w->isActive() ? 'Ya' : 'Tidak' }}</td>
                <td class="text-center">{{ $w->enrolled_branches }}</td>
                <td class="text-center"><a href="{{ route('satusehat.waves.show', $w->id) }}"><x-ui.button size="sm" variant="secondary">Detail</x-ui.button></a></td>
            </tr>
        @empty
            <tr><td colspan="6"><x-ui.empty-state title="Belum ada wave." /></td></tr>
        @endforelse
    </x-ui.table>
    <div class="mt-4">{{ $waves->links() }}</div>
</x-settings-shell>
