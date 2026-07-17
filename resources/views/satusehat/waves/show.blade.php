{{-- SATUSEHAT-4D — wave detail + governance actions. Credential-independent. --}}
<x-settings-shell title="SATUSEHAT — Detail Wave">
    <x-ui.page-header :title="'Wave: '.$wave->name"
        subtitle="Kelola cabang, persetujuan, dan rehearsal multi-cabang (tanpa pengiriman eksternal).">
        <x-slot:breadcrumb>SATUSEHAT · Wave · {{ $wave->name }}</x-slot:breadcrumb>
        <a href="{{ route('satusehat.waves.index') }}"><x-ui.button variant="secondary">Kembali</x-ui.button></a>
    </x-ui.page-header>

    @if (session('status'))<x-ui.alert variant="success">{{ session('status') }}</x-ui.alert>@endif
    @foreach (['wave','branch_id','reason','status'] as $f)@error($f)<x-ui.alert variant="danger">{{ $message }}</x-ui.alert>@enderror @endforeach

    <x-ui.card class="my-4">
        <div>Status: <x-ui.badge>{{ $wave->statusLabel() }}</x-ui.badge> · Urutan: {{ $wave->sequence }} · Aktif: {{ $wave->isActive() ? 'Ya' : 'Tidak' }}</div>
    </x-ui.card>

    @can('manage_satusehat_rollout_waves')
        <x-ui.card class="my-4">
            <div class="font-semibold mb-2">Daftarkan Cabang (ID)</div>
            <form method="POST" action="{{ route('satusehat.waves.enroll', $wave->id) }}" class="flex gap-2 items-end">
                @csrf
                <x-ui.input name="branch_id" type="number" label="Branch ID (RME)" required />
                <x-ui.button type="submit">Daftarkan</x-ui.button>
            </form>
        </x-ui.card>
    @endcan

    <x-ui.card class="my-4">
        <div class="font-semibold mb-2">Cabang Terdaftar</div>
        <x-ui.table>
            <x-slot:head><tr><th class="text-left">Cabang</th><th>Kode</th></tr></x-slot:head>
            @forelse ($wave->activeMemberships as $m)
                <tr><td class="text-left">{{ $m->branch?->name }}</td><td class="text-center">{{ $m->branch?->code }}</td></tr>
            @empty
                <tr><td colspan="2"><x-ui.empty-state title="Belum ada cabang terdaftar." /></td></tr>
            @endforelse
        </x-ui.table>
    </x-ui.card>

    <div class="flex flex-wrap gap-2">
        @can('approve_satusehat_rollout_wave')
            <form method="POST" action="{{ route('satusehat.waves.approve', $wave->id) }}">@csrf<x-ui.button variant="success" type="submit">Setujui Wave</x-ui.button></form>
        @endcan
        @can('run_satusehat_pilot_rehearsal')
            <form method="POST" action="{{ route('satusehat.waves.rehearse', $wave->id) }}">@csrf<x-ui.button variant="secondary" type="submit">Rehearsal Multi-Cabang (dry-run)</x-ui.button></form>
        @endcan
        @can('manage_satusehat_rollout_waves')
            <form method="POST" action="{{ route('satusehat.waves.close', $wave->id) }}">@csrf<x-ui.button variant="danger" type="submit">Tutup Wave</x-ui.button></form>
        @endcan
    </div>
</x-settings-shell>
