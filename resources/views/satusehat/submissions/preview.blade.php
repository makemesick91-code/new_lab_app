<x-settings-shell title="Preview FHIR Lokal — SATUSEHAT">
    <x-ui.page-header
        title="Preview FHIR Lokal — Kandidat #{{ $candidate->id }}"
        subtitle="Dibangun dari data lokal + mapping aktif. Odontogram, scan, dan tulisan tangan tidak disertakan.">
        <x-slot:breadcrumb>Rekam Medis Elektronik / SATUSEHAT</x-slot:breadcrumb>
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('satusehat.submissions.show', $candidate)">Kembali</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.alert variant="info" title="Preview Lokal">
        {{ $preview['note'] }} Lingkungan: <strong>{{ $preview['environment'] }}</strong>.
    </x-ui.alert>

    @foreach ($preview['resources'] as $resource)
        <x-ui.card class="mt-4" :title="$resource['resource_type']">
            <div class="mb-2 flex items-center gap-2">
                <x-ui.badge :tone="$resource['supported'] ? 'success' : 'neutral'">
                    {{ $resource['supported'] ? 'Didukung' : 'Belum didukung' }}
                </x-ui.badge>
                <span class="text-xs text-ink-muted">Urutan dependensi: {{ $resource['order'] }}</span>
            </div>

            @if (! empty($resource['issues']))
                <ul class="mb-2 list-disc pl-5 text-sm text-warning-700">
                    @foreach ($resource['issues'] as $issue)
                        <li>{{ $issue }}</li>
                    @endforeach
                </ul>
            @endif

            @if ($resource['payload'])
                <pre class="overflow-x-auto rounded-xl bg-navy-50 p-3 text-xs text-ink">{{ json_encode($resource['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                @if (($resource['payload_hash'] ?? null))
                    <p class="mt-1 text-xs text-ink-muted">Hash payload: {{ $resource['payload_hash'] }}</p>
                @endif
            @else
                <p class="text-sm text-ink-muted">Tidak ada payload untuk resource ini.</p>
            @endif
        </x-ui.card>
    @endforeach
</x-settings-shell>
