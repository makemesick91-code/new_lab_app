{{-- DMS-RME-COMPREHENSIVE-CLOSURE-1 memo M3.3 PR5 (rule 240 §7) — one version of a
     record beside the one before it (RmeAmendmentService::versionDetailFor()). A
     sheet: fields (changed ones marked), diagnoses, emergency overrides and the
     locked pages — each page only through the existing policy-gated, no-store
     page route; the snapshot's object keys are never rendered. A chart: the
     tooth-by-tooth diff against the previous version. --}}
@php
    use App\Modules\ClinicOversight\Support\OversightFormat;

    $subjectLabel = $isChart ? 'Odontogram' : 'Rekam Medis';
    $historyUrl = route($isChart ? 'rme.amendments.versions.chart' : 'rme.amendments.versions.sheet', $subject->id);
@endphp
<x-settings-shell title="Versi {{ $version->version }} {{ $subjectLabel }}">
    <div class="space-y-6" data-version-detail="{{ $version->version }}">
        <x-ui.page-header :title="'Versi '.$version->version.' — '.$subjectLabel" :subtitle="'Dicatat '.OversightFormat::clinicalTime($version->created_at, 'd/m/Y H:i').' WITA'.($previous !== null ? ' · dibandingkan dengan versi '.$previous->version : '')">
            <x-slot:breadcrumb>Rekam Medis Elektronik · Riwayat Versi</x-slot:breadcrumb>
            <x-slot:actions>
                <x-ui.button variant="secondary" :href="$historyUrl">Riwayat versi</x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>

        @unless ($chainVerified)
            <div class="rounded-lg border border-red-300 bg-red-50 p-4 text-sm text-red-800" role="alert" data-version-chain-broken>
                Riwayat versi rekam medis ini tidak utuh: rantai hash tidak cocok. Isi versi di bawah ini tidak dapat dipastikan keasliannya; laporkan kepada Supervisor RME.
            </div>
        @endunless

        @if ($isChart)
            <x-ui.card title="Odontogram">
                <x-ui.table>
                    <thead class="bg-navy-50">
                        <tr>
                            <th class="px-3 py-2 text-left font-medium text-ink-soft">Gigi</th>
                            <th class="px-3 py-2 text-left font-medium text-ink-soft">{{ $previous !== null ? 'Versi '.$previous->version : 'Sebelumnya' }}</th>
                            <th class="px-3 py-2 text-left font-medium text-ink-soft">Versi {{ $version->version }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-hairline">
                        @forelse ($chart_diff['rows'] as $toothRow)
                            <tr class="align-top {{ $previous !== null && $toothRow['changed'] ? 'bg-amber-50' : '' }}" @if ($previous !== null && $toothRow['changed']) data-chart-row-changed="{{ $toothRow['tooth'] }}" @endif>
                                <td class="px-3 py-2 font-semibold">{{ $toothRow['tooth'] }}</td>
                                <td class="px-3 py-2 text-ink-soft">@forelse ($toothRow['before'] as $line)<div>{{ $line }}</div>@empty<div>—</div>@endforelse</td>
                                <td class="px-3 py-2">@forelse ($toothRow['after'] as $line)<div>{{ $line }}</div>@empty<div>—</div>@endforelse</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="px-3 py-2 text-ink-soft">Tidak ada data gigi.</td></tr>
                        @endforelse
                        @foreach ($chart_diff['notes'] as $noteRow)
                            <tr class="align-top">
                                <td class="px-3 py-2 font-medium">{{ $noteRow['label'] }}</td>
                                <td class="whitespace-pre-line px-3 py-2 text-ink-soft">{{ $noteRow['before'] ?? '—' }}</td>
                                <td class="whitespace-pre-line px-3 py-2">{{ $noteRow['after'] ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-ui.table>
            </x-ui.card>
        @else
            <x-ui.card title="Isi rekam medis">
                <dl class="space-y-3 text-sm">
                    @foreach ($fields as $field)
                        <div @if ($field['changed']) class="rounded bg-amber-50 p-2" data-version-field-changed @endif>
                            <dt class="text-ink-soft">{{ $field['label'] }} @if ($field['changed'])<span class="text-xs font-medium text-warning">berubah</span>@endif</dt>
                            <dd class="whitespace-pre-line">{{ $field['value'] ?? '—' }}</dd>
                        </div>
                    @endforeach
                </dl>
            </x-ui.card>

            <x-ui.card title="Diagnosis terstruktur">
                <ul class="list-disc pl-5 text-sm">
                    @forelse ($diagnoses as $label)<li>{{ $label }}</li>@empty<li class="text-ink-soft">Tidak ada.</li>@endforelse
                </ul>
                @if ($overrides !== [])
                    <h4 class="mt-4 text-sm font-semibold text-navy">Override darurat diagnosis</h4>
                    <ul class="list-disc pl-5 text-sm">
                        @foreach ($overrides as $override)
                            <li>{{ $override['reason'] }} @if ($override['expires_at'])(berlaku s/d {{ OversightFormat::clinicalTime($override['expires_at'], 'd/m/Y H:i') }} WITA)@endif</li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>

            <x-ui.card title="Halaman RM tulisan tangan yang terkunci">
                @if ($pages === [])
                    <p class="text-sm text-ink-soft">Tidak ada halaman terkunci pada versi ini.</p>
                @else
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        @foreach ($pages as $page)
                            <figure class="rounded border border-hairline p-2" data-version-page="{{ $page['page_number'] }}">
                                <img src="{{ $page['url'] }}" alt="Halaman RM {{ $page['page_number'] }}" loading="lazy" class="w-full">
                                <figcaption class="mt-1 text-xs text-ink-soft">Halaman {{ $page['page_number'] }} · SHA-256 {{ $page['sha256'] ?? '—' }}…</figcaption>
                            </figure>
                        @endforeach
                    </div>
                @endif
            </x-ui.card>
        @endif
    </div>
</x-settings-shell>
