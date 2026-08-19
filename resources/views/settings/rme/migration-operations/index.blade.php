{{--
    LEGACY-RME-PDF-ROLL-4 — migration operations index.

    READ-ONLY BY CONSTRUCTION apart from registering a wave. Counts, codes,
    branch labels and timings only: no patient, no Nomor RM, no KTP/NIK, no
    filename, no document path.

    "Not measurable" is rendered as such and never as 0 — a fabricated zero here
    would read as "healthy, nothing pending", which is the most dangerous thing
    an operations panel can say.
--}}
<x-settings-shell
    eyebrow="Master Data RME"
    title="Operasi Migrasi Arsip RME Lama"
    subtitle="Gelombang migrasi, kuota harian, antrean pemrosesan dan rekonsiliasi."
>
    @php
        $wave = $overview['wave'] ?? null;
        $binding = $overview['binding'] ?? [];
        $admission = $overview['admission'] ?? [];
        $queue = $overview['queue'] ?? [];
    @endphp

    @if (session('success'))
        <x-ui.alert variant="success" class="mb-4">{{ session('success') }}</x-ui.alert>
    @endif

    @if ($errors->any())
        <x-ui.alert variant="danger" class="mb-4">
            <ul class="list-disc pl-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </x-ui.alert>
    @endif

    {{-- The two gates side by side. ROLL-3 decides WHETHER a branch may migrate;
         ROLL-4 decides how it is operated. Showing both is what lets a reader
         tell which one refused a document. --}}
    <x-ui.card class="mb-6">
        <x-slot:header>Status Gelombang</x-slot:header>

        <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <dt class="text-xs uppercase tracking-wide text-ink-muted">Gelombang berjalan</dt>
                <dd class="mt-1 text-sm font-semibold text-navy">
                    {{ $wave['code'] ?? 'Belum terdaftar' }}
                    @if ($wave)
                        <x-ui.badge :tone="($wave['ingesting'] ?? false) ? 'success' : 'warning'">
                            {{ $wave['status'] }}
                        </x-ui.badge>
                    @endif
                </dd>
            </div>

            <div>
                <dt class="text-xs uppercase tracking-wide text-ink-muted">Menerima dokumen baru</dt>
                <dd class="mt-1 text-sm font-semibold text-navy">
                    {{ ($wave['ingesting'] ?? false) ? 'Ya' : 'Tidak' }}
                </dd>
            </div>

            <div>
                <dt class="text-xs uppercase tracking-wide text-ink-muted">Lapisan operasi</dt>
                <dd class="mt-1 text-sm font-semibold text-navy">
                    {{ ($overview['operations']['enforced'] ?? true) ? 'Ditegakkan' : 'TIDAK ditegakkan' }}
                </dd>
            </div>

            <div>
                <dt class="text-xs uppercase tracking-wide text-ink-muted">Ikatan persetujuan</dt>
                <dd class="mt-1 text-sm font-semibold text-navy">
                    @if (($binding['binding_matches'] ?? false))
                        <span class="text-success-700">Sesuai persetujuan deployment</span>
                    @else
                        <span class="text-danger-700">Tidak cocok</span>
                    @endif
                </dd>
            </div>
        </dl>

        <div class="mt-4 grid grid-cols-1 gap-4 border-t border-hairline pt-4 sm:grid-cols-2">
            <div>
                <dt class="text-xs uppercase tracking-wide text-ink-muted">Cabang diizinkan (ROLL-3)</dt>
                <dd class="mt-1 text-sm text-ink">
                    {{ empty($admission['admitted_branch_codes']) ? '(tidak ada — tertutup)' : implode(', ', $admission['admitted_branch_codes']) }}
                </dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wide text-ink-muted">Referensi persetujuan</dt>
                <dd class="mt-1 text-sm text-ink">
                    {{ $binding['declared_approval_reference'] ?? '(belum tercatat)' }}
                </dd>
            </div>
        </div>

        @if (! empty($admission['unapproved_admitted']))
            <x-ui.alert variant="danger" class="mt-4">
                Cabang berikut diizinkan tanpa tercakup persetujuan:
                {{ implode(', ', $admission['unapproved_admitted']) }}.
            </x-ui.alert>
        @endif
    </x-ui.card>

    <x-ui.card class="mb-6">
        <x-slot:header>Antrean Pemrosesan</x-slot:header>

        <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <dt class="text-xs uppercase tracking-wide text-ink-muted">Kapasitas</dt>
                <dd class="mt-1 text-sm font-semibold text-navy">
                    {{ ($queue['available'] ?? true) ? 'Tersedia' : 'Penuh (' . ($queue['code'] ?? '-') . ')' }}
                </dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wide text-ink-muted">Antrean render</dt>
                <dd class="mt-1 text-sm text-ink">{{ $queue['render_queue'] ?? '-' }}</dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wide text-ink-muted">Job tertunda</dt>
                {{-- NULL means the probe could not run; it is never shown as 0. --}}
                <dd class="mt-1 text-sm text-ink">
                    {{ $queue['pending_jobs'] ?? 'tidak terukur' }}
                </dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wide text-ink-muted">Menunggu review</dt>
                <dd class="mt-1 text-sm text-ink">
                    {{ ($overview['backlog']['measurable'] ?? false) ? $overview['backlog']['awaiting_review'] : 'tidak terukur' }}
                </dd>
            </div>
        </dl>
    </x-ui.card>

    <x-ui.card>
        <x-slot:header>Gelombang Terdaftar</x-slot:header>

        @if ($waves->isEmpty())
            <x-ui.empty-state
                title="Belum ada gelombang migrasi"
                description="Gelombang migrasi harus terdaftar sebelum dokumen arsip dapat diterima."
            />
        @else
            <x-ui.table>
                <x-slot:head>
                    <tr>
                        <th class="px-4 py-2 text-left">Kode</th>
                        <th class="px-4 py-2 text-left">Nama</th>
                        <th class="px-4 py-2 text-left">Status</th>
                        <th class="px-4 py-2 text-left">Referensi Persetujuan</th>
                        <th class="px-4 py-2 text-left">Aksi</th>
                    </tr>
                </x-slot:head>

                @foreach ($waves as $row)
                    <tr>
                        <td class="px-4 py-2 font-medium text-navy">{{ $row->code }}</td>
                        <td class="px-4 py-2 text-ink">{{ $row->name }}</td>
                        <td class="px-4 py-2">
                            <x-ui.badge :tone="$row->isActive() ? 'success' : 'neutral'">{{ $row->status }}</x-ui.badge>
                        </td>
                        <td class="px-4 py-2 text-ink-soft">{{ $row->approval_reference ?? '—' }}</td>
                        <td class="px-4 py-2">
                            <x-ui.button
                                size="sm"
                                variant="secondary"
                                href="{{ route('settings.rme.migration-operations.show', $row) }}"
                            >Detail</x-ui.button>
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>
        @endif
    </x-ui.card>

    @can('manage_legacy_rme_migration_operations')
        <x-ui.card class="mt-6">
            <x-slot:header>Daftarkan Gelombang</x-slot:header>

            {{-- The branch set is authorized server-side against the deployment's
                 approval and the live RME-enabled branch list; this form only
                 collects it. --}}
            <form method="POST" action="{{ route('settings.rme.migration-operations.store') }}" class="grid gap-4 sm:grid-cols-2">
                @csrf

                <x-ui.input name="code" label="Kode Gelombang" placeholder="WAVE-2" required />
                <x-ui.input name="name" label="Nama Gelombang" placeholder="Migrasi Gelombang 2" required />

                <div class="sm:col-span-2">
                    <x-ui.input
                        name="branch_codes[]"
                        label="Kode Cabang"
                        placeholder="ATG3"
                        help="Satu cabang per isian. Cabang harus tercakup dalam persetujuan deployment."
                        required
                    />
                </div>

                <x-ui.input
                    name="daily_quota"
                    type="number"
                    min="0"
                    label="Kuota Harian Gelombang"
                    help="Kosongkan bila tidak ada batas."
                />
                <x-ui.input
                    name="per_branch_daily_quota"
                    type="number"
                    min="0"
                    label="Kuota Harian per Cabang"
                    help="Kosongkan bila tidak ada batas."
                />

                {{-- FIX-LEGACY-RME-ROUTINE-OPS-1 — a routine batch is
                     time-bounded. These were previously collectable only over
                     HTTP-by-hand: the request already validated them and the
                     service already persisted them, but the form never offered
                     them, so every batch opened from this page carried an
                     approval with no expiry. The rule itself lives in
                     LegacyRmeWaveGovernanceService, not here — this form only
                     collects it, exactly like the branch set above. --}}
                <x-ui.input
                    name="planned_start_date"
                    type="date"
                    label="Tanggal Mulai Batch"
                    :value="old('planned_start_date')"
                    help="Hari pertama batch dijalankan."
                    :required="$batchWindowRequired ?? true"
                />
                <x-ui.input
                    name="planned_end_date"
                    type="date"
                    label="Tanggal Berakhir Batch"
                    :value="old('planned_end_date')"
                    help="Hari terakhir batch berlaku, sudah termasuk. Persetujuan batch berakhir pada tanggal ini."
                    :required="$batchWindowRequired ?? true"
                />

                <div class="sm:col-span-2">
                    <x-ui.button type="submit" variant="primary">Daftarkan Gelombang</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    @endcan
</x-settings-shell>
