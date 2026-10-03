{{--
    FEATURE-LEGACY-BATCH-REVIEW-PUBLISH-1 (PR1) — review queue.

    Shared by both document types; the controller supplies $heading, $routePrefix
    and $importType so the markup exists once. Presentation only: every row was
    already branch-scoped server-side by the canonical workspace scope, and
    KTP/NIK is never rendered here or anywhere in this workspace. The medical
    record number is the only patient identifier shown.

    There is deliberately NO "mark all" control on this page. The only way to
    attest a document is to open it and decide, which is the product rule (§1)
    expressed in the UI as well as in the request shape.
--}}
<x-settings-shell :title="$heading">
    <div class="space-y-6">
        <x-ui.page-header
            :title="$heading"
            subtitle="Tinjau arsip lama satu per satu dengan cepat, lalu kirim seluruh keputusan dalam satu tindakan. Tidak ada dokumen yang ditandai ditinjau tanpa diperiksa."
        >
            <x-slot:breadcrumb>Master Data RME / {{ $heading }}</x-slot:breadcrumb>

            <x-slot:actions>
                @if ($session)
                    <x-ui.button :href="route($routePrefix.'.show', $session->uuid)">
                        Lanjutkan Sesi Tinjauan
                    </x-ui.button>
                @else
                    <form method="POST" action="{{ route($routePrefix.'.store') }}">
                        @csrf
                        <x-ui.button type="submit">Mulai Sesi Tinjauan</x-ui.button>
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

        <x-ui.alert variant="info">
            Menandai dokumen <strong>Ditahan</strong> atau <strong>Perlu perhatian</strong>
            tidak membatalkan apa pun. Dokumen tetap berada pada tahap tinjauan dan hanya
            dikecualikan dari penerbitan sampai peninjau berwenang membebaskannya.
            Pembatalan permanen tetap dilakukan terpisah melalui halaman dokumen.
        </x-ui.alert>

        {{-- §11 counters. READY FOR REVIEW is the canonical queue total for this
             actor's scope; the rest describe the open session's progress. --}}
        <div class="grid grid-cols-2 gap-3 md:grid-cols-4 lg:grid-cols-7">
            <x-ui.kpi-card label="Siap ditinjau" :value="$counters['ready_for_review']" />
            <x-ui.kpi-card label="Ditandai ditinjau" :value="$counters['marked_reviewed']" />
            <x-ui.kpi-card label="Ditahan" :value="$counters['marked_blocked']" />
            <x-ui.kpi-card label="Perlu perhatian" :value="$counters['marked_attention']" />
            <x-ui.kpi-card label="Menunggu dikirim" :value="$counters['pending_submit']" />
            <x-ui.kpi-card label="Diterapkan" :value="$counters['applied']" />
            <x-ui.kpi-card label="Ditolak sistem" :value="$counters['refused']" />
        </div>

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
                title="Tidak ada dokumen siap ditinjau"
                description="Belum ada arsip lama berstatus siap ditinjau pada cakupan cabang Anda."
            />
        @else
            <x-ui.card>
                <x-ui.table>
                    <thead class="bg-navy-50 text-xs uppercase tracking-wide text-ink-soft">
                        <tr>
                            <th class="px-4 py-3 text-left">Pasien</th>
                            <th class="px-4 py-3 text-left">Nomor RM</th>
                            <th class="px-4 py-3 text-left">Cabang</th>
                            <th class="px-4 py-3 text-left">Tanggal dokumen</th>
                            <th class="px-4 py-3 text-left">Halaman</th>
                            <th class="px-4 py-3 text-left">Keputusan</th>
                            <th class="px-4 py-3 text-right">Aksi</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-hairline">

                    @foreach ($items as $item)
                        <tr class="border-t border-hairline">
                            <td class="px-4 py-3 text-ink">{{ $item['patient_name'] ?? '—' }}</td>
                            <td class="px-4 py-3 text-ink-soft">{{ $item['medical_record_number'] ?? '—' }}</td>
                            <td class="px-4 py-3 text-ink-soft">{{ $item['branch_name'] ?? '—' }}</td>
                            <td class="px-4 py-3 text-ink-soft">{{ $item['clinical_date'] ?? '—' }}</td>
                            <td class="px-4 py-3 text-ink-soft">{{ $item['page_count'] }}</td>
                            <td class="px-4 py-3">
                                @if ($item['triage_blocking'])
                                    <x-ui.badge tone="warning">{{ $item['triage_status_label'] }}</x-ui.badge>
                                @elseif ($item['decision'])
                                    <x-ui.badge tone="success">{{ $item['decision_label'] }}</x-ui.badge>
                                @else
                                    <span class="text-ink-muted">Belum ditinjau</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right">
                                @if ($session)
                                    <x-ui.button
                                        size="sm"
                                        :href="route($routePrefix.'.show', ['session' => $session->uuid, 'import' => $item['import_id']])"
                                    >Tinjau</x-ui.button>
                                @else
                                    <x-ui.button
                                        size="sm"
                                        variant="secondary"
                                        :href="route($singleItemRoute, $item['import_id'])"
                                    >Buka Dokumen</x-ui.button>
                                @endif
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
