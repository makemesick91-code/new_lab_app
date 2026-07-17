{{-- SATUSEHAT-4D — human operator UAT runs. A signed-off run is the mandatory
     precondition for an operational GO; automated tests never substitute. --}}
<x-settings-shell title="SATUSEHAT — UAT Operator">
    <x-ui.page-header title="UAT Operator SATUSEHAT (Multi-Cabang)"
        subtitle="Sesi UAT operator manusia. Sign-off nyata dari seluruh peran wajib untuk GO operasional. Gunakan data sintetis/tanpa PII.">
        <x-slot:breadcrumb>SATUSEHAT · UAT</x-slot:breadcrumb>
    </x-ui.page-header>

    @if (session('status'))<x-ui.alert variant="success">{{ session('status') }}</x-ui.alert>@endif
    @foreach (['title','run','role','decision','operator_name','reason','outcome'] as $f)@error($f)<x-ui.alert variant="danger">{{ $message }}</x-ui.alert>@enderror @endforeach

    <x-ui.alert variant="info" title="Kit UAT">
        Peran wajib sign-off: {{ implode(', ', $requiredRoles) }}. Bukti wajib sintetis/PII-safe — jangan gunakan NIK atau data pasien nyata.
    </x-ui.alert>

    <x-ui.card class="my-4">
        <div class="font-semibold mb-2">Buat Sesi UAT</div>
        <form method="POST" action="{{ route('satusehat.uat.store') }}" class="flex flex-wrap gap-2 items-end">
            @csrf
            <x-ui.input name="title" label="Judul sesi" required />
            <x-ui.input name="rollout_wave_id" type="number" label="Wave ID (opsional)" />
            <x-ui.button type="submit">Buat</x-ui.button>
        </form>
    </x-ui.card>

    <x-ui.table>
        <x-slot:head><tr><th class="text-left">Sesi</th><th>Status</th><th>Skenario</th><th></th></tr></x-slot:head>
        @forelse ($runs as $run)
            <tr>
                <td class="text-left">{{ $run->title }}</td>
                <td class="text-center"><x-ui.badge>{{ $run->status }}</x-ui.badge></td>
                <td class="text-center">{{ $run->scenarios_count }}</td>
                <td class="text-center"><a href="{{ route('satusehat.uat.show', $run->id) }}"><x-ui.button size="sm" variant="secondary">Buka</x-ui.button></a></td>
            </tr>
        @empty
            <tr><td colspan="4"><x-ui.empty-state title="Belum ada sesi UAT." /></td></tr>
        @endforelse
    </x-ui.table>
    <div class="mt-4">{{ $runs->links() }}</div>
</x-settings-shell>
