{{--
    LEGACY-RME-PDF-HISTORY-1 — the patient's unified clinical RME history,
    rendered inside the patient-centric RME workspace.

    ONE PATIENT, ONE HISTORY, TWO SOURCES. Native RME produced by this system
    and the patient's PUBLISHED legacy archive are shown on one timeline so a
    doctor opening a new visit reads the whole story in one place. They stay
    visually and semantically DISTINCT: a legacy row is an archived historical
    document, never a visit, and it is never converted into one.

    READ ONLY. There is no edit, convert, delete, publish or generate action
    here — a published legacy archive is immutable clinical evidence, and a
    correction is a VOID plus a fresh import through the intake workflow.

    ORDERING is newest first (the doctor reads from the current encounter into
    the past) and is computed server-side from the CLINICAL date, never from
    upload time. The filter below is presentation only: every row was already
    resolved under the caller's permission, branch scope and doctor clinical
    scope before it reached this template.

    LEGACY-RME-PDF-HISTORY-1A — a legacy row appears here whenever the record is
    PUBLISHED and the reader is authorized, INDEPENDENTLY of the legacy
    migration capability. That flag stops new documents entering the archive; it
    is not a statement that evidence already published stopped being part of
    this patient's history.

    KTP/NIK never appears here, and no storage path is ever rendered.
--}}
@php
    $clinicalHistory = $clinicalHistory ?? collect();
    $legacyCount = $clinicalHistory->filter(fn ($entry) => $entry->isLegacy())->count();
    $nativeCount = $clinicalHistory->count() - $legacyCount;
@endphp

@if ($clinicalHistory->isNotEmpty())
    <x-ui.card title="Riwayat RME Pasien">
        <p class="mb-4 text-sm text-ink-muted">
            Seluruh riwayat rekam medis pasien ini: RME pada sistem DaengtisiaMS digabungkan dengan arsip
            RME lama yang sudah dipublikasikan. Arsip lama bersifat historis dan hanya dapat dibaca.
        </p>

        <div
            x-data="{ filter: 'all' }"
            data-rme-clinical-history
        >
            @if ($legacyCount > 0)
                <div class="mb-4 flex flex-wrap gap-2" role="group" aria-label="Saring riwayat RME">
                    <button
                        type="button"
                        x-on:click="filter = 'all'"
                        x-bind:class="filter === 'all' ? 'bg-brand-600 text-white' : 'bg-navy-50 text-ink-soft hover:text-ink'"
                        class="rounded-lg px-3 py-1.5 text-xs font-medium focus:outline-none focus:ring-2 focus:ring-brand-100"
                    >Semua ({{ $clinicalHistory->count() }})</button>
                    <button
                        type="button"
                        x-on:click="filter = 'native'"
                        x-bind:class="filter === 'native' ? 'bg-brand-600 text-white' : 'bg-navy-50 text-ink-soft hover:text-ink'"
                        class="rounded-lg px-3 py-1.5 text-xs font-medium focus:outline-none focus:ring-2 focus:ring-brand-100"
                    >RME Sistem ({{ $nativeCount }})</button>
                    <button
                        type="button"
                        x-on:click="filter = 'legacy'"
                        x-bind:class="filter === 'legacy' ? 'bg-brand-600 text-white' : 'bg-navy-50 text-ink-soft hover:text-ink'"
                        class="rounded-lg px-3 py-1.5 text-xs font-medium focus:outline-none focus:ring-2 focus:ring-brand-100"
                    >RME Lama ({{ $legacyCount }})</button>
                </div>
            @endif

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
                        @foreach ($clinicalHistory as $entry)
                            <tr
                                x-show="filter === 'all' || filter === '{{ $entry->isLegacy() ? 'legacy' : 'native' }}'"
                                data-history-kind="{{ $entry->isLegacy() ? 'legacy' : 'native' }}"
                                @class([
                                    'bg-warning-50/40' => $entry->isLegacy(),
                                    'bg-brand-50/60' => $entry->isCurrent,
                                ])
                            >
                                <td class="px-3 py-2 text-ink">{{ $entry->dateLabel() }}</td>
                                <td class="px-3 py-2">
                                    @if ($entry->isLegacy())
                                        <x-ui.badge tone="warning">ARSIP RME LAMA</x-ui.badge>
                                    @else
                                        <x-ui.badge tone="info">RME SISTEM</x-ui.badge>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-ink">
                                    {{ $entry->label }}
                                    @if ($entry->reference)
                                        <span class="block font-mono text-xs text-ink-muted">{{ $entry->reference }}</span>
                                    @endif
                                    @if ($entry->isCurrent)
                                        <span class="mt-1 block text-xs font-medium text-brand-700">Kunjungan Saat Ini</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-ink-soft">
                                    {{ $entry->detail ?? '—' }}
                                    @if ($entry->hasDateRange())
                                        <span class="block text-xs text-ink-muted">Dokumen mencakup beberapa tanggal perawatan.</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-right">
                                    @if ($entry->isCurrent)
                                        <span class="text-xs text-ink-muted">—</span>
                                    @elseif ($entry->url)
                                        <a
                                            href="{{ $entry->url }}"
                                            class="text-xs font-medium text-brand-700 hover:text-brand-800"
                                        >{{ $entry->isLegacy() ? 'Lihat PDF' : 'Lihat RME' }}</a>
                                    @else
                                        <span class="text-xs text-ink-muted">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </x-ui.card>
@endif
