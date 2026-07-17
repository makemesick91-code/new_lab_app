{{-- SATUSEHAT-4D — UAT run detail: record scenarios + role sign-offs + finalize.
     Real human sign-off; synthetic/PII-safe evidence only. --}}
<x-settings-shell title="SATUSEHAT — Detail UAT">
    <x-ui.page-header :title="'UAT: '.$run->title"
        subtitle="Catat hasil skenario dan sign-off per peran. Sign-off penuh (semua peran + tanpa skenario gagal) diperlukan untuk GO operasional.">
        <x-slot:breadcrumb>SATUSEHAT · UAT · {{ $run->title }}</x-slot:breadcrumb>
        <a href="{{ route('satusehat.uat.index') }}"><x-ui.button variant="secondary">Kembali</x-ui.button></a>
    </x-ui.page-header>

    @if (session('status'))<x-ui.alert variant="success">{{ session('status') }}</x-ui.alert>@endif
    @foreach (['role','decision','operator_name','outcome','run','signoff','reason'] as $f)@error($f)<x-ui.alert variant="danger">{{ $message }}</x-ui.alert>@enderror @endforeach

    <x-ui.card class="my-4"><div>Status: <x-ui.badge>{{ $run->status }}</x-ui.badge></div></x-ui.card>

    <x-ui.card class="my-4">
        <div class="font-semibold mb-2">Catat Skenario</div>
        <form method="POST" action="{{ route('satusehat.uat.scenario', $run->id) }}" class="flex flex-wrap gap-2 items-end">
            @csrf
            <x-ui.input name="scenario_code" label="Kode" required />
            <x-ui.input name="role" label="Peran" required />
            <x-ui.select name="outcome" label="Hasil"><option value="pass">pass</option><option value="fail">fail</option><option value="blocked">blocked</option></x-ui.select>
            <x-ui.input name="operator_name" label="Nama operator" />
            <x-ui.button type="submit">Catat</x-ui.button>
        </form>
    </x-ui.card>

    <x-ui.table>
        <x-slot:head><tr><th class="text-left">Kode</th><th>Peran</th><th>Hasil</th><th>Operator</th></tr></x-slot:head>
        @forelse ($run->scenarios as $sc)
            <tr><td class="text-left">{{ $sc->scenario_code }}</td><td class="text-center">{{ $sc->role }}</td><td class="text-center"><x-ui.badge>{{ $sc->outcome }}</x-ui.badge></td><td class="text-center">{{ $sc->operator_name }}</td></tr>
        @empty
            <tr><td colspan="4"><x-ui.empty-state title="Belum ada skenario." /></td></tr>
        @endforelse
    </x-ui.table>

    <x-ui.card class="my-4">
        <div class="font-semibold mb-2">Sign-off Peran</div>
        <form method="POST" action="{{ route('satusehat.uat.signoff', $run->id) }}" class="flex flex-wrap gap-2 items-end">
            @csrf
            <x-ui.select name="role" label="Peran">@foreach ($requiredRoles as $r)<option value="{{ $r }}">{{ $r }}</option>@endforeach</x-ui.select>
            <x-ui.select name="decision" label="Keputusan"><option value="approved">approved</option><option value="rejected">rejected</option></x-ui.select>
            <x-ui.input name="operator_name" label="Nama operator" required />
            <x-ui.button type="submit">Tandatangani</x-ui.button>
        </form>
        <div class="mt-3">
            <x-ui.table>
                <x-slot:head><tr><th class="text-left">Peran</th><th>Keputusan</th><th>Operator</th></tr></x-slot:head>
                @foreach ($run->signoffs as $so)
                    <tr><td class="text-left">{{ $so->role }}</td><td class="text-center"><x-ui.badge>{{ $so->decision }}</x-ui.badge></td><td class="text-center">{{ $so->operator_name }}</td></tr>
                @endforeach
            </x-ui.table>
        </div>
    </x-ui.card>

    <div class="flex gap-2">
        <form method="POST" action="{{ route('satusehat.uat.finalize', $run->id) }}">@csrf<x-ui.button variant="success" type="submit">Sign Off Penuh</x-ui.button></form>
    </div>
</x-settings-shell>
