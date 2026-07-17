{{-- SATUSEHAT-4D — change-control. Production/credential categories can never be
     approved/applied; separation of duties enforced server-side. --}}
<x-settings-shell title="SATUSEHAT — Change Control">
    <x-ui.page-header title="Change Control SATUSEHAT"
        subtitle="Kontrol perubahan tata kelola. Tidak ada kategori yang mengaktifkan produksi/pengiriman eksternal pada SATUSEHAT-4D.">
        <x-slot:breadcrumb>SATUSEHAT · Change Control</x-slot:breadcrumb>
    </x-ui.page-header>

    @if (session('status'))<x-ui.alert variant="success">{{ session('status') }}</x-ui.alert>@endif
    @foreach (['category','reason','scope','approved_by'] as $f)@error($f)<x-ui.alert variant="danger">{{ $message }}</x-ui.alert>@enderror @endforeach

    <x-ui.alert variant="warning">
        Kategori terblokir ({{ implode(', ', $blocked) }}) hanya dapat dicatat sebagai niat — tidak dapat disetujui/diterapkan.
    </x-ui.alert>

    <x-ui.card class="my-4">
        <div class="font-semibold mb-2">Buat Change Request</div>
        <form method="POST" action="{{ route('satusehat.change-control.store') }}" class="flex flex-wrap gap-2 items-end">
            @csrf
            <x-ui.select name="category" label="Kategori">
                @foreach ($categories as $c)<option value="{{ $c }}">{{ $c }}</option>@endforeach
            </x-ui.select>
            <x-ui.input name="scope" label="Scope" required />
            <x-ui.input name="reason" label="Alasan (min 10)" required />
            <x-ui.button type="submit">Ajukan</x-ui.button>
        </form>
    </x-ui.card>

    <x-ui.table>
        <x-slot:head><tr><th class="text-left">Kategori</th><th>Scope</th><th>Status</th><th></th></tr></x-slot:head>
        @forelse ($requests as $cr)
            <tr>
                <td class="text-left">{{ $cr->category }}</td>
                <td class="text-center">{{ $cr->scope }}</td>
                <td class="text-center"><x-ui.badge>{{ $cr->status }}</x-ui.badge></td>
                <td class="text-center">
                    @if (! in_array($cr->category, $blocked) && in_array($cr->status, ['pending','reviewed']))
                        <form method="POST" action="{{ route('satusehat.change-control.approve', $cr->id) }}" class="inline">@csrf<x-ui.button size="sm" variant="success" type="submit">Setujui</x-ui.button></form>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="4"><x-ui.empty-state title="Belum ada change request." /></td></tr>
        @endforelse
    </x-ui.table>
    <div class="mt-4">{{ $requests->links() }}</div>
</x-settings-shell>
