{{--
    FEATURE-LEGACY-BATCH-REVIEW-PUBLISH-1 (PR2) — selection + confirmation.

    §12 is the whole point of this screen: before the final action the operator
    sees Selected / Eligible now / Blocked after revalidation, and the button
    names the number it will ACTUALLY publish. There is deliberately no
    "Publish All" — if any selected document has stopped being eligible, saying
    "all" would be a lie.

    §15: a withheld document gets no checkbox at all. The UI never offers a
    selection the server would refuse, and nothing on this page can clear a
    triage annotation — that is a review-authority act in PR1's flow.

    Alpine + Blade only, no new dependency. "Select all eligible" is a client
    convenience that ticks the boxes already rendered; the server still receives
    and re-evaluates every id.
--}}
<x-settings-shell :title="$heading">
    <div class="space-y-6" x-data="{ selected: [] }">
        <x-ui.page-header
            :title="$heading"
            subtitle="Pilih dokumen yang sudah ditinjau, periksa ringkasan kelayakan, lalu publikasikan."
        >
            <x-slot:breadcrumb>
                Master Data RME / {{ $heading }} / Sesi {{ Str::limit($run->uuid, 8, '') }}
            </x-slot:breadcrumb>

            <x-slot:actions>
                <x-ui.button :href="route($routePrefix.'.index')" variant="secondary" size="sm">
                    Kembali ke Daftar
                </x-ui.button>

                @if (! \App\Modules\LegacyImport\BatchPublish\Support\LegacyBatchPublishRunStatus::isTerminal($run->status))
                    <form method="POST" action="{{ route($routePrefix.'.abandon', $run->uuid) }}">
                        @csrf
                        <x-ui.button type="submit" variant="secondary" size="sm">
                            Tinggalkan Sesi
                        </x-ui.button>
                    </form>
                @endif
            </x-slot:actions>
        </x-ui.page-header>

        @if (session('status'))
            <x-ui.alert variant="success">{{ session('status') }}</x-ui.alert>
        @endif

        @if ($errors->any())
            <x-ui.alert variant="danger">{{ $errors->first() }}</x-ui.alert>
        @endif

        <div class="flex flex-wrap items-center gap-3 text-sm">
            <x-ui.badge tone="info">{{ $run->statusLabel() }}</x-ui.badge>
            @if ($counters['pending'] > 0)
                <span class="text-ink-soft">Menunggu publikasi: <strong>{{ $counters['pending'] }}</strong></span>
            @endif
            @if ($counters['refused'] > 0)
                <span class="text-danger-700">Ditolak: <strong>{{ $counters['refused'] }}</strong></span>
            @endif
        </div>

        @include('settings.rme.legacy-batch-publish._counters')

        {{-- §12 CONFIRMATION. Quoted from the server's own evaluation of the
             documents this run actually selected, not from the page's checkbox
             count — so the number on the button is the number that will go. --}}
        @if ($counters['selected'] > 0)
            <x-ui.card accent>
                <div class="space-y-3">
                    <p class="text-sm font-semibold text-navy">Ringkasan sebelum publikasi</p>

                    <dl class="grid grid-cols-1 gap-2 text-sm sm:grid-cols-3">
                        <div>
                            <dt class="text-ink-muted">Terpilih</dt>
                            <dd class="text-lg font-semibold text-ink">{{ $counters['selected'] }}</dd>
                        </div>
                        <div>
                            <dt class="text-ink-muted">Layak saat ini</dt>
                            <dd class="text-lg font-semibold text-success-700">{{ $counters['pending'] }}</dd>
                        </div>
                        <div>
                            <dt class="text-ink-muted">Ditolak setelah pemeriksaan ulang</dt>
                            <dd class="text-lg font-semibold text-warning-700">{{ $counters['refused'] }}</dd>
                        </div>
                    </dl>

                    @if ($counters['pending'] > 0)
                        <form method="POST" action="{{ route($routePrefix.'.publish', $run->uuid) }}" class="space-y-3">
                            @csrf

                            <div class="grid gap-3 sm:grid-cols-2">
                                <x-ui.input name="title" label="Judul arsip (opsional)" maxlength="150" />
                                <x-ui.input name="description" label="Keterangan arsip (opsional)" maxlength="2000" />
                            </div>

                            <x-ui.button type="submit" variant="success">
                                Publikasikan {{ $counters['pending'] }} Dokumen
                            </x-ui.button>

                            <p class="text-xs text-ink-muted">
                                Setiap dokumen diperiksa ulang tepat sebelum diterbitkan. Dokumen yang
                                sudah tidak layak akan ditolak dengan alasan, tanpa membatalkan dokumen
                                lain yang berhasil terbit.
                            </p>
                        </form>
                    @else
                        <x-ui.alert variant="warning">
                            Tidak ada dokumen yang layak dipublikasikan saat ini. Periksa alasan
                            penolakan di bawah, atau pilih dokumen lain.
                        </x-ui.alert>
                    @endif
                </div>
            </x-ui.card>
        @endif

        @if ($items === [])
            <x-ui.empty-state
                title="Tidak ada dokumen siap dipublikasikan"
                description="Belum ada arsip lama berstatus sudah ditinjau pada cakupan cabang Anda."
            />
        @else
            <form method="POST" action="{{ route($routePrefix.'.select', $run->uuid) }}">
                @csrf

                <x-ui.card padding="p-0">
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-hairline px-4 py-3">
                        <p class="text-sm font-semibold text-ink">Dokumen Sudah Ditinjau</p>

                        @if ($run->isMutable())
                            <div class="flex items-center gap-2">
                                <x-ui.button
                                    type="button"
                                    variant="secondary"
                                    size="sm"
                                    x-on:click="selected = Array.from($root.querySelectorAll('input[data-selectable]')).map(el => el.value)"
                                >Pilih Semua yang Layak</x-ui.button>
                                <x-ui.button type="button" variant="secondary" size="sm" x-on:click="selected = []">
                                    Kosongkan
                                </x-ui.button>
                                <x-ui.button type="submit" size="sm" x-bind:disabled="selected.length === 0">
                                    Tambahkan ke Sesi (<span x-text="selected.length"></span>)
                                </x-ui.button>
                            </div>
                        @endif
                    </div>

                    <x-ui.table>
                        <thead class="bg-navy-50 text-xs uppercase tracking-wide text-ink-soft">
                            <tr>
                                <th class="px-4 py-3 text-left">Pilih</th>
                                <th class="px-4 py-3 text-left">Pasien</th>
                                <th class="px-4 py-3 text-left">Nomor RM</th>
                                <th class="px-4 py-3 text-left">Tanggal dokumen</th>
                                <th class="px-4 py-3 text-left">Status</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-hairline">
                            @foreach ($items as $item)
                                <tr>
                                    <td class="px-4 py-3">
                                        @if ($item['selectable'] && $run->isMutable() && $item['item_status'] !== 'PUBLISHED')
                                            <input
                                                type="checkbox"
                                                name="import_ids[]"
                                                value="{{ $item['import_id'] }}"
                                                data-selectable="1"
                                                x-model="selected"
                                                class="rounded border-hairline text-brand-600 focus:ring-brand-100"
                                            >
                                        @else
                                            {{-- §15: no checkbox for a withheld or already-published
                                                 document. The UI does not offer what the server refuses. --}}
                                            <span class="text-xs text-ink-muted">—</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-ink">{{ $item['patient_name'] ?? '—' }}</td>
                                    <td class="px-4 py-3 text-ink-soft">{{ $item['medical_record_number'] ?? '—' }}</td>
                                    <td class="px-4 py-3 text-ink-soft">{{ $item['clinical_date'] ?? '—' }}</td>
                                    <td class="px-4 py-3">
                                        @if ($item['triage_blocking'])
                                            <x-ui.badge tone="warning">{{ $item['triage_status_label'] }}</x-ui.badge>
                                            <span class="mt-1 block text-xs text-ink-muted">
                                                {{ $item['triage_reason_label'] }} · dibebaskan melalui alur tinjauan
                                            </span>
                                        @elseif ($item['item_status'] === 'PUBLISHED')
                                            <x-ui.badge tone="success">Dipublikasikan</x-ui.badge>
                                            @if ($item['record_id'])
                                                <a
                                                    href="{{ route($recordRoute, $item['record_id']) }}"
                                                    class="mt-1 block text-xs text-brand-700 underline"
                                                >Lihat arsip</a>
                                            @endif
                                        @elseif ($item['item_status'] === 'REFUSED')
                                            <x-ui.badge tone="danger">{{ $item['item_reason_label'] }}</x-ui.badge>
                                            @if ($item['item_reason_message'])
                                                <span class="mt-1 block text-xs text-ink-muted">
                                                    {{ $item['item_reason_message'] }}
                                                </span>
                                            @endif
                                        @elseif ($item['item_status'] === 'PENDING')
                                            <x-ui.badge tone="info">Siap dipublikasikan</x-ui.badge>
                                        @else
                                            <x-ui.badge tone="info">Sudah ditinjau</x-ui.badge>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </x-ui.table>

                    <div class="border-t border-hairline px-4 py-3">{{ $paginator->links() }}</div>
                </x-ui.card>
            </form>
        @endif
    </div>
</x-settings-shell>
