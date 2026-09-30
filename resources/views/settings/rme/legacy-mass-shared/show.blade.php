{{--
    FEATURE-LEGACY-RME-ODONTOGRAM-MASS-UPLOAD-1 — preflight review and outcome.

    The operator's decision point (§18, §19, §20). Every count and every verdict
    was computed server-side; confirming cannot promote a blocked row, and the
    confirm form carries no row selection for exactly that reason.

    No KTP/NIK, no patient name, no clinical content. The medical record number
    appears because the operator supplied it and cannot locate their own
    spreadsheet row without it.
--}}
@php
    use App\Modules\LegacyImport\MassUpload\Support\LegacyMassUploadItemStatus;
@endphp

<x-settings-shell :title="$heading">
    <div class="space-y-6">
        <x-ui.page-header
            :title="$heading"
            :subtitle="'Batch '.\Illuminate\Support\Str::limit($batch->uuid, 8, '').' — '.$batch->statusLabel()"
        >
            <x-slot:breadcrumb>Master Data RME / {{ $heading }} / Batch</x-slot:breadcrumb>

            <x-slot:actions>
                <x-ui.button :href="route($routePrefix.'.index')" variant="secondary">Kembali</x-ui.button>
                <x-ui.button :href="route($routePrefix.'.report', $batch)" variant="secondary">Unduh Laporan</x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>

        @if (session('status'))
            <x-ui.alert variant="success">{{ session('status') }}</x-ui.alert>
        @endif

        @if ($batch->failure_code)
            {{-- A package-integrity refusal: nothing was attempted (§17). --}}
            <x-ui.alert variant="danger">
                <p class="font-medium">Paket ditolak</p>
                <p>{{ $batch->failure_message }}</p>
                <p class="mt-1 text-xs">Tidak ada dokumen klinis yang dibuat dari paket ini.</p>
            </x-ui.alert>
        @endif

        <div class="grid grid-cols-2 gap-4 lg:grid-cols-5">
            <x-ui.kpi-card label="Total Baris" :value="$batch->total_items" />
            <x-ui.kpi-card label="Layak" :value="$batch->eligible_items + $batch->warning_items" />
            <x-ui.kpi-card label="Ditolak" :value="$batch->blocked_items" />
            <x-ui.kpi-card label="Diproses" :value="$batch->dispatched_items" />
            <x-ui.kpi-card label="Gagal Teknis" :value="$batch->failed_items" />
        </div>

        @if ($batch->awaitingReview())
            <x-ui.card title="Tinjau sebelum memulai">
                <div class="space-y-4 text-sm text-ink-soft">
                    <p>
                        <strong>{{ $batch->eligible_items + $batch->warning_items }}</strong> baris akan diproses.
                        <strong>{{ $batch->blocked_items }}</strong> baris ditolak dan tidak akan dibuat.
                    </p>

                    <x-ui.alert variant="info">
                        Memulai proses hanya membuat dokumen untuk baris yang dinyatakan layak
                        oleh server. Baris yang ditolak tetap ditolak. Dokumen yang dibuat
                        masuk ke tahap tinjau — belum terbit.
                    </x-ui.alert>

                    <div class="flex flex-wrap gap-2">
                        <form method="POST" action="{{ route($routePrefix.'.confirm', $batch) }}" x-data="{ busy: false }" @submit="busy = true">
                            @csrf
                            {{-- Disabling on submit is a courtesy against double clicks; the
                                 server guarantee is the status transition under a row lock. --}}
                            <x-ui.button type="submit" x-bind:disabled="busy">
                                Mulai Upload ({{ $batch->eligible_items + $batch->warning_items }} baris)
                            </x-ui.button>
                        </form>

                        @can('cancel', $batch)
                            <form method="POST" action="{{ route($routePrefix.'.cancel', $batch) }}">
                                @csrf
                                <x-ui.button type="submit" variant="danger">Batalkan Batch</x-ui.button>
                            </form>
                        @endcan
                    </div>
                </div>
            </x-ui.card>
        @endif

        @if ($batch->status === \App\Modules\LegacyImport\MassUpload\Support\LegacyMassUploadBatchStatus::DISPATCHING)
            {{-- Bounded dispatch: a large archive legitimately rests here (§23). --}}
            <x-ui.card title="Proses belum selesai">
                <div class="space-y-3 text-sm text-ink-soft">
                    <p>
                        Batch ini diproses secara bertahap agar antrean render tidak jenuh.
                        Lanjutkan untuk memproses baris yang tersisa.
                    </p>

                    <form method="POST" action="{{ route($routePrefix.'.dispatch', $batch) }}">
                        @csrf
                        <x-ui.button type="submit">Lanjutkan Proses</x-ui.button>
                    </form>
                </div>
            </x-ui.card>
        @endif

        <x-ui.card>
            <x-ui.filter-bar :action="route($routePrefix.'.show', $batch)" method="GET">
                <x-ui.select name="item_status" label="Status baris">
                    <option value="">Semua ({{ $batch->total_items }})</option>
                    @foreach ($statusCounts as $option => $count)
                        <option value="{{ $option }}" @selected($itemStatus === $option)>
                            {{ LegacyMassUploadItemStatus::label($option) }} ({{ $count }})
                        </option>
                    @endforeach
                </x-ui.select>

                <x-slot:actions>
                    <x-ui.button type="submit" size="sm">Terapkan</x-ui.button>
                    <x-ui.button :href="route($routePrefix.'.show', $batch)" variant="secondary" size="sm">Atur Ulang</x-ui.button>
                </x-slot:actions>
            </x-ui.filter-bar>
        </x-ui.card>

        @if ($items->isEmpty())
            <x-ui.empty-state
                title="Tidak ada baris"
                description="Tidak ada baris yang cocok dengan filter ini."
            />
        @else
            <x-ui.card>
                <x-ui.table>
                    <x-slot:head>
                        <tr>
                            <th class="px-4 py-3 text-right">Baris</th>
                            <th class="px-4 py-3 text-left">Nomor RM</th>
                            <th class="px-4 py-3 text-left">Berkas</th>
                            <th class="px-4 py-3 text-left">Tanggal</th>
                            <th class="px-4 py-3 text-left">Status</th>
                            <th class="px-4 py-3 text-left">Keterangan</th>
                        </tr>
                    </x-slot:head>

                    @foreach ($items as $item)
                        <tr class="border-t border-hairline">
                            <td class="px-4 py-3 text-right text-ink-soft">{{ $item->row_number }}</td>
                            <td class="px-4 py-3 font-medium text-ink">{{ $item->manifest_medical_record_number }}</td>
                            <td class="px-4 py-3 text-ink-soft">{{ \Illuminate\Support\Str::limit($item->manifest_file_name, 36) }}</td>
                            <td class="px-4 py-3 text-ink-soft">
                                {{ $item->manifest_selected_date }}
                                @if ($item->manifest_latest_date)
                                    <span class="text-ink-muted">&ndash; {{ $item->manifest_latest_date }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                <x-ui.badge>{{ $item->statusLabel() }}</x-ui.badge>
                            </td>
                            <td class="px-4 py-3 text-ink-soft">
                                @if ($item->reason_code)
                                    <span class="block text-xs font-mono text-ink-muted">{{ $item->reason_code }}</span>
                                @endif
                                {{ $item->reasonText() }}
                            </td>
                        </tr>
                    @endforeach
                </x-ui.table>
            </x-ui.card>

            <div>{{ $items->links() }}</div>
        @endif
    </div>
</x-settings-shell>
