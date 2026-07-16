<x-settings-shell title="Detail Mapping SATUSEHAT">
    <x-ui.page-header title="Mapping #{{ $mapping->id }} (v{{ $mapping->version }})" :subtitle="$mapping->local_entity_type.' → '.$mapping->target_resource_type">
        <x-slot:breadcrumb>Rekam Medis Elektronik / SATUSEHAT</x-slot:breadcrumb>
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('satusehat.mappings.index')">Kembali</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if (session('success'))
        <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
    @endif
    @if ($errors->any())
        <x-ui.alert variant="danger">{{ $errors->first() }}</x-ui.alert>
    @endif

    <x-ui.card title="Detail">
        <dl class="grid gap-2 text-sm sm:grid-cols-2">
            <div class="flex justify-between"><dt class="text-ink-soft">Lingkungan</dt><dd class="text-ink">{{ $mapping->environment }}</dd></div>
            <div class="flex justify-between"><dt class="text-ink-soft">Status</dt><dd><x-ui.badge :tone="$mapping->statusTone()">{{ $mapping->statusLabel() }}</x-ui.badge></dd></div>
            <div class="flex justify-between"><dt class="text-ink-soft">Kunci lokal</dt><dd class="text-ink">{{ $mapping->local_entity_id ?? $mapping->local_code }}</dd></div>
            <div class="flex justify-between"><dt class="text-ink-soft">Kode target</dt><dd class="text-ink">{{ $mapping->target_code ?? '—' }}</dd></div>
            <div class="flex justify-between"><dt class="text-ink-soft">Sistem terminologi</dt><dd class="text-ink">{{ $mapping->terminology_system ?? '—' }}</dd></div>
            <div class="flex justify-between"><dt class="text-ink-soft">Berlaku sejak</dt><dd class="text-ink">{{ optional($mapping->effective_date)->format('d M Y') ?? '—' }}</dd></div>
        </dl>

        <div class="mt-4 flex flex-wrap gap-2">
            <form method="POST" action="{{ route('satusehat.mappings.review', $mapping) }}">@csrf<x-ui.button type="submit" variant="secondary">Review</x-ui.button></form>
            <form method="POST" action="{{ route('satusehat.mappings.activate', $mapping) }}">@csrf<x-ui.button type="submit" variant="success" :disabled="$mapping->isActive()">Aktifkan</x-ui.button></form>
            <form method="POST" action="{{ route('satusehat.mappings.deprecate', $mapping) }}">@csrf<x-ui.button type="submit" variant="warning">Usangkan</x-ui.button></form>
        </div>

        @if ($mapping->isProfileFamilyGoverned())
            <div class="mt-4 rounded-xl border border-hairline bg-navy-50 p-3">
                <p class="mb-2 text-sm text-ink-soft">
                    Mapping profil <strong>{{ $mapping->profile_family }}</strong> wajib diverifikasi terhadap
                    sumber resmi sebelum diaktifkan.
                    Status verifikasi:
                    <x-ui.badge :tone="$mapping->hasOfficialProvenance() ? 'success' : 'warning'">
                        {{ $mapping->hasOfficialProvenance() ? 'Terverifikasi' : 'Belum diverifikasi' }}
                    </x-ui.badge>
                </p>
                <form method="POST" action="{{ route('satusehat.mappings.verify', $mapping) }}" class="flex flex-wrap items-end gap-2">
                    @csrf
                    <x-ui.input label="Sumber resmi (URL/dokumen)" name="official_source" :value="$mapping->official_source" />
                    <x-ui.input label="Versi sumber" name="official_source_version" :value="$mapping->official_source_version" />
                    <x-ui.button type="submit" variant="primary">Verifikasi</x-ui.button>
                </form>
            </div>
        @endif
    </x-ui.card>

    <x-ui.card title="Riwayat Versi" class="mt-4" padding="">
        <div class="overflow-x-auto">
            <x-ui.table>
                <thead class="bg-navy-50"><tr class="text-left text-ink-soft">
                    <th class="px-4 py-2">Versi</th><th class="px-4 py-2">Status</th><th class="px-4 py-2">Kode target</th><th class="px-4 py-2">Dibuat</th>
                </tr></thead>
                <tbody class="divide-y divide-hairline">
                    @foreach ($versions as $v)
                        <tr>
                            <td class="px-4 py-2">v{{ $v->version }}</td>
                            <td class="px-4 py-2"><x-ui.badge :tone="$v->statusTone()">{{ $v->statusLabel() }}</x-ui.badge></td>
                            <td class="px-4 py-2">{{ $v->target_code ?? '—' }}</td>
                            <td class="px-4 py-2">{{ optional($v->created_at)->format('d M Y H:i') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
        </div>
    </x-ui.card>

    <x-ui.card title="Riwayat Audit" class="mt-4" padding="">
        <div class="overflow-x-auto">
            <x-ui.table>
                <thead class="bg-navy-50"><tr class="text-left text-ink-soft">
                    <th class="px-4 py-2">Waktu</th><th class="px-4 py-2">Peristiwa</th><th class="px-4 py-2">Aktor</th>
                </tr></thead>
                <tbody class="divide-y divide-hairline">
                    @forelse ($timeline as $log)
                        <tr>
                            <td class="px-4 py-2 text-sm">{{ optional($log->created_at)->format('d M Y H:i') }}</td>
                            <td class="px-4 py-2 text-sm">{{ $log->event }}</td>
                            <td class="px-4 py-2 text-sm">{{ $log->actor?->name ?? 'sistem' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="px-4 py-3 text-ink-muted">Belum ada audit.</td></tr>
                    @endforelse
                </tbody>
            </x-ui.table>
        </div>
    </x-ui.card>
</x-settings-shell>
