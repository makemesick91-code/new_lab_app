{{--
    FEATURE-LEGACY-RME-ODONTOGRAM-MASS-UPLOAD-1 — mass batch list.

    Shared by both surfaces; the concrete view passes $heading and $routePrefix
    so the markup exists once. Presentation only: every row was already
    branch-scoped server-side, and KTP/NIK is never rendered here or anywhere in
    this workspace. The medical record number is the only patient identifier
    that appears, on the detail page, because the operator typed it themselves.
--}}
<x-settings-shell :title="$heading">
    <div class="space-y-6">
        <x-ui.page-header
            :title="$heading"
            subtitle="Unggah banyak arsip lama sekaligus melalui satu paket ZIP dan satu manifest. Setiap baris diperiksa lebih dahulu; tidak ada dokumen yang terbit otomatis."
        >
            <x-slot:breadcrumb>Master Data RME / {{ $heading }}</x-slot:breadcrumb>

            <x-slot:actions>
                <x-ui.button :href="route($routePrefix.'.create')">Unggah Paket Baru</x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>

        @if (session('status'))
            <x-ui.alert variant="success">{{ session('status') }}</x-ui.alert>
        @endif

        <x-ui.alert variant="info">
            Mass upload menyiapkan dokumen untuk ditinjau. Penerbitan (publish) tetap
            dilakukan per dokumen oleh petugas berwenang yang berbeda dari pengunggah.
        </x-ui.alert>

        <x-ui.card>
            <x-ui.filter-bar :action="route($routePrefix.'.index')" method="GET">
                <x-ui.select name="status" label="Status batch">
                    <option value="">Semua status</option>
                    @foreach (\App\Modules\LegacyImport\MassUpload\Support\LegacyMassUploadBatchStatus::all() as $option)
                        <option value="{{ $option }}" @selected($status === $option)>
                            {{ \App\Modules\LegacyImport\MassUpload\Support\LegacyMassUploadBatchStatus::label($option) }}
                        </option>
                    @endforeach
                </x-ui.select>

                <x-slot:actions>
                    <x-ui.button type="submit" size="sm">Terapkan</x-ui.button>
                    <x-ui.button :href="route($routePrefix.'.index')" variant="secondary" size="sm">Atur Ulang</x-ui.button>
                </x-slot:actions>
            </x-ui.filter-bar>
        </x-ui.card>

        @if ($batches->isEmpty())
            <x-ui.empty-state
                title="Belum ada batch"
                description="Belum ada paket arsip lama yang diunggah pada cakupan Anda."
            >
                <x-slot:action>
                    <x-ui.button :href="route($routePrefix.'.create')">Unggah Paket Baru</x-ui.button>
                </x-slot:action>
            </x-ui.empty-state>
        @else
            <x-ui.card>
                <x-ui.table>
                    <x-slot:head>
                        <tr>
                            <th class="px-4 py-3 text-left">Batch</th>
                            <th class="px-4 py-3 text-left">Status</th>
                            <th class="px-4 py-3 text-right">Total</th>
                            <th class="px-4 py-3 text-right">Layak</th>
                            <th class="px-4 py-3 text-right">Ditolak</th>
                            <th class="px-4 py-3 text-right">Diproses</th>
                            <th class="px-4 py-3 text-left">Diunggah</th>
                            <th class="px-4 py-3 text-right">Aksi</th>
                        </tr>
                    </x-slot:head>

                    @foreach ($batches as $batch)
                        <tr class="border-t border-hairline">
                            <td class="px-4 py-3 font-medium text-ink">
                                {{ \Illuminate\Support\Str::limit($batch->package_original_name ?? 'paket.zip', 40) }}
                                <div class="text-xs text-ink-muted">{{ \Illuminate\Support\Str::limit($batch->uuid, 8, '') }}</div>
                            </td>
                            <td class="px-4 py-3">
                                <x-ui.badge>{{ $batch->statusLabel() }}</x-ui.badge>
                            </td>
                            <td class="px-4 py-3 text-right">{{ $batch->total_items }}</td>
                            <td class="px-4 py-3 text-right">{{ $batch->eligible_items + $batch->warning_items }}</td>
                            <td class="px-4 py-3 text-right">{{ $batch->blocked_items }}</td>
                            <td class="px-4 py-3 text-right">{{ $batch->dispatched_items }}</td>
                            <td class="px-4 py-3 text-ink-soft">{{ $batch->created_at?->format('d/m/Y H:i') }}</td>
                            <td class="px-4 py-3 text-right">
                                <x-ui.button :href="route($routePrefix.'.show', $batch)" size="sm" variant="secondary">
                                    Lihat
                                </x-ui.button>
                            </td>
                        </tr>
                    @endforeach
                </x-ui.table>
            </x-ui.card>

            <div>{{ $batches->links() }}</div>
        @endif
    </div>
</x-settings-shell>
