{{--
    FEATURE-LEGACY-BATCH-REVIEW-PUBLISH-1 (PR2) — the REVIEWED publish queue.

    Shared by both archives; the controller supplies $heading, $routePrefix and
    $importType so the markup exists once. Presentation only: every row was
    already branch-scoped server-side by the canonical workspace scope, and
    KTP/NIK is never rendered here or anywhere in this workspace. The medical
    record number is the only patient identifier shown.

    §15: a document withheld by a PR1 triage annotation is shown WITHOUT a
    checkbox and labelled as withheld — the UI never offers a selection the
    server would refuse, and there is no clear-and-publish shortcut anywhere on
    this page.
--}}
<x-settings-shell :title="$heading">
    <div class="space-y-6">
        <x-ui.page-header
            :title="$heading"
            subtitle="Publikasikan banyak arsip lama yang sudah ditinjau dalam satu tindakan. Setiap dokumen tetap diperiksa ulang satu per satu tepat sebelum diterbitkan."
        >
            <x-slot:breadcrumb>Master Data RME / {{ $heading }}</x-slot:breadcrumb>

            <x-slot:actions>
                @if ($run)
                    <x-ui.button :href="route($routePrefix.'.show', $run->uuid)">
                        Lanjutkan Sesi Publikasi
                    </x-ui.button>
                @else
                    <form method="POST" action="{{ route($routePrefix.'.store') }}">
                        @csrf
                        <x-ui.button type="submit">Mulai Sesi Publikasi</x-ui.button>
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

        <x-ui.alert variant="warning">
            Publikasi bersifat <strong>permanen</strong>. Arsip yang sudah terbit tidak dapat
            diubah; koreksi dilakukan melalui pembatalan (VOID) dan impor ulang pada halaman
            dokumen. Dokumen yang masih ditahan peninjau tidak dapat dipilih di sini.
        </x-ui.alert>

        @include('settings.rme.legacy-batch-publish._counters')

        <x-ui.card>
            <x-ui.filter-bar :action="route($routePrefix.'.index')" method="GET">
                <x-ui.input
                    name="patient"
                    label="Cari pasien"
                    :value="request('patient')"
                    placeholder="Nama atau Nomor RM"
                />

                <x-slot:actions>
                    <x-ui.button type="submit" size="sm">Terapkan</x-ui.button>
                    <x-ui.button :href="route($routePrefix.'.index')" variant="secondary" size="sm">
                        Atur Ulang
                    </x-ui.button>
                </x-slot:actions>
            </x-ui.filter-bar>
        </x-ui.card>

        @if ($items === [])
            <x-ui.empty-state
                title="Tidak ada dokumen siap dipublikasikan"
                description="Belum ada arsip lama berstatus sudah ditinjau pada cakupan cabang Anda."
            />
        @else
            <x-ui.card padding="p-0">
                <x-ui.table>
                    <thead class="bg-navy-50 text-xs uppercase tracking-wide text-ink-soft">
                        <tr>
                            <th class="px-4 py-3 text-left">Pasien</th>
                            <th class="px-4 py-3 text-left">Nomor RM</th>
                            <th class="px-4 py-3 text-left">Cabang</th>
                            <th class="px-4 py-3 text-left">Tanggal dokumen</th>
                            <th class="px-4 py-3 text-left">Status</th>
                            <th class="px-4 py-3 text-right">Aksi</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-hairline">
                        @foreach ($items as $item)
                            <tr>
                                <td class="px-4 py-3 text-ink">{{ $item['patient_name'] ?? '—' }}</td>
                                <td class="px-4 py-3 text-ink-soft">{{ $item['medical_record_number'] ?? '—' }}</td>
                                <td class="px-4 py-3 text-ink-soft">{{ $item['branch_name'] ?? '—' }}</td>
                                <td class="px-4 py-3 text-ink-soft">{{ $item['clinical_date'] ?? '—' }}</td>
                                <td class="px-4 py-3">
                                    @if ($item['triage_blocking'])
                                        <x-ui.badge tone="warning">{{ $item['triage_status_label'] }}</x-ui.badge>
                                    @elseif ($item['item_status'] === 'PUBLISHED')
                                        <x-ui.badge tone="success">Dipublikasikan</x-ui.badge>
                                    @elseif ($item['item_status'] === 'REFUSED')
                                        <x-ui.badge tone="danger">{{ $item['item_reason_label'] }}</x-ui.badge>
                                    @else
                                        <x-ui.badge tone="info">Sudah ditinjau</x-ui.badge>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <x-ui.button
                                        size="sm"
                                        variant="secondary"
                                        :href="route($singleItemRoute, $item['import_id'])"
                                    >Buka Dokumen</x-ui.button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-ui.table>
            </x-ui.card>

            {{ $paginator->links() }}
        @endif
    </div>
</x-settings-shell>
