{{--
    LEGACY-RME-PDF-1C — the patient's rekam medis timeline: native RME produced
    by this system, interleaved with the published legacy (historical) archive.

    Legacy and native stay visually and semantically DISTINCT — a legacy row is
    an archived document, never a visit — and the ordering is by the clinical
    date, never by upload time.

    Rendered only when the patient actually has a published legacy archive that
    this operator may see, so with the feature flag off (or without the archive
    permission) the RME workspace looks exactly as it did before this sprint.

    KTP/NIK never appears here, and no storage path is ever rendered.
--}}
@php
    $rmeTimeline = $rmeTimeline ?? collect();
@endphp

@if ($rmeTimeline->isNotEmpty())
    <x-ui.card title="Linimasa Rekam Medis Pasien">
        <p class="mb-4 text-sm text-ink-muted">
            Menggabungkan rekam medis pada sistem ini dengan arsip RME lama pasien yang sudah dipublikasikan.
            Arsip lama bersifat historis dan hanya dapat dibaca.
        </p>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-hairline text-sm">
                <thead class="bg-navy-50">
                    <tr class="text-left text-ink-soft">
                        <th scope="col" class="px-3 py-2 font-medium">Tanggal</th>
                        <th scope="col" class="px-3 py-2 font-medium">Jenis</th>
                        <th scope="col" class="px-3 py-2 font-medium">Sumber</th>
                        <th scope="col" class="px-3 py-2 font-medium">Keterangan</th>
                        <th scope="col" class="px-3 py-2 font-medium"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-hairline">
                    @foreach ($rmeTimeline as $entry)
                        <tr @class(['bg-warning-50/40' => $entry->isLegacy()])>
                            {{-- LEGACY-RME-PDF-HISTORY-1 — an archive covering
                                 several clinical dates renders its earliest–latest
                                 range, so one PDF is never mistaken for one visit. --}}
                            <td class="px-3 py-2 text-ink">{{ $entry->dateLabel() }}</td>
                            <td class="px-3 py-2">
                                @if ($entry->isLegacy())
                                    <x-ui.badge tone="warning">ARSIP LAMA</x-ui.badge>
                                @else
                                    <x-ui.badge tone="info">RME SISTEM</x-ui.badge>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-ink">
                                {{ $entry->label }}
                                @if ($entry->reference)
                                    <span class="block font-mono text-xs text-ink-muted">{{ $entry->reference }}</span>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-ink-soft">{{ $entry->detail ?? '—' }}</td>
                            <td class="px-3 py-2 text-right">
                                @if ($entry->url)
                                    <a
                                        href="{{ $entry->url }}"
                                        class="text-xs font-medium text-brand-700 hover:text-brand-800"
                                    >{{ $entry->isLegacy() ? 'Lihat Arsip' : 'Detail' }}</a>
                                @else
                                    <span class="text-xs text-ink-muted">—</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-ui.card>
@endif
