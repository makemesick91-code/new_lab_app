{{--
    LEGACY-RME-PDF-ROLL-4 — one migration wave.

    Counts, codes, branch labels and timings only. No patient, no Nomor RM, no
    KTP/NIK, no filename, no document path — including in the QA sample, which is
    a completeness check rather than a clinical review.

    Buttons are presentation. Every action below is re-authorized by the policy
    and re-validated (transition legality, quota bounds, reconciliation) inside
    the governance service under a row lock.
--}}
<x-settings-shell
    eyebrow="Operasi Migrasi"
    :title="'Gelombang ' . $wave->code"
    :subtitle="$wave->name"
>
    @php
        $reconciliation = $overview['reconciliation'] ?? [];
        $quotaToday = $overview['quota_today'] ?? [];
        $storage = $overview['storage'] ?? [];
        $binding = $overview['binding'] ?? [];
        $canManage = auth()->user()?->can('manage_legacy_rme_migration_operations') ?? false;
        $minReason = (int) config('legacy_rme_operations.min_reason_length', 10);
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

    @unless ($binding['binding_matches'] ?? false)
        <x-ui.alert variant="danger" class="mb-4">
            Catatan gelombang ini tidak cocok dengan persetujuan pada deployment.
            Migrasi baru ditolak sampai keduanya diselaraskan.
        </x-ui.alert>
    @endunless

    <x-ui.card class="mb-6">
        <x-slot:header>Ringkasan</x-slot:header>

        <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <dt class="text-xs uppercase tracking-wide text-ink-muted">Status</dt>
                <dd class="mt-1"><x-ui.badge :tone="$wave->isActive() ? 'success' : 'neutral'">{{ $wave->status }}</x-ui.badge></dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wide text-ink-muted">Kuota hari ini</dt>
                <dd class="mt-1 text-sm text-ink">
                    {{ $quotaToday['wave_consumed'] ?? 0 }} /
                    {{ $quotaToday['wave_limit'] ?? 'tanpa batas' }}
                </dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wide text-ink-muted">Diterima</dt>
                <dd class="mt-1 text-sm text-ink">{{ $reconciliation['accepted'] ?? 0 }}</dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wide text-ink-muted">Terbit</dt>
                <dd class="mt-1 text-sm text-ink">{{ $reconciliation['published'] ?? 0 }}</dd>
            </div>
        </dl>
    </x-ui.card>

    {{-- The balance sheet. `unexplained` and `quota_drift` are the two numbers
         that must be zero before anything may be signed off. --}}
    <x-ui.card class="mb-6">
        <x-slot:header>Rekonsiliasi Gelombang</x-slot:header>

        <p class="mb-3 text-sm text-ink-soft">
            Diterima = terbit + dibatalkan + gagal + sedang berjalan. Sisa yang tidak
            terjelaskan dan selisih buku kuota harus nol sebelum penyelesaian.
        </p>

        <dl class="grid grid-cols-2 gap-4 sm:grid-cols-4 lg:grid-cols-7">
            @foreach ([
                'accepted' => 'Diterima',
                'published' => 'Terbit',
                'cancelled' => 'Dibatalkan',
                'failed_unresolved' => 'Gagal',
                'in_flight' => 'Berjalan',
                'unexplained' => 'Tak terjelaskan',
                'quota_drift' => 'Selisih kuota',
            ] as $key => $label)
                <div>
                    <dt class="text-xs uppercase tracking-wide text-ink-muted">{{ $label }}</dt>
                    <dd @class([
                        'mt-1 text-sm font-semibold',
                        'text-danger-700' => in_array($key, ['unexplained', 'quota_drift'], true) && (int) ($reconciliation[$key] ?? 0) !== 0,
                        'text-navy' => ! (in_array($key, ['unexplained', 'quota_drift'], true) && (int) ($reconciliation[$key] ?? 0) !== 0),
                    ])>{{ $reconciliation[$key] ?? 0 }}</dd>
                </div>
            @endforeach
        </dl>

        @if (($storage['measurable'] ?? false))
            <p class="mt-4 border-t border-hairline pt-3 text-sm text-ink-soft">
                Penyimpanan terukur: {{ $storage['documents'] }} dokumen ·
                {{ number_format((int) $storage['source_bytes'] / 1048576, 1) }} MiB sumber ·
                rata-rata {{ $storage['average_source_bytes'] ?? 'n/a' }} byte/dokumen ·
                sisa disk {{ $storage['disk_free_bytes'] === null ? 'tidak terukur' : number_format((int) $storage['disk_free_bytes'] / 1073741824, 2) . ' GiB' }}.
            </p>
        @endif
    </x-ui.card>

    <x-ui.card class="mb-6">
        <x-slot:header>Cabang dalam Gelombang</x-slot:header>

        <x-ui.table>
            <x-slot:head>
                <tr>
                    <th class="px-4 py-2 text-left">Cabang</th>
                    <th class="px-4 py-2 text-left">Status</th>
                    <th class="px-4 py-2 text-right">Kuota/hari</th>
                    <th class="px-4 py-2 text-right">Diterima</th>
                    <th class="px-4 py-2 text-right">Terbit</th>
                    <th class="px-4 py-2 text-right">Berjalan</th>
                    <th class="px-4 py-2 text-right">Gagal</th>
                    <th class="px-4 py-2 text-left">Operator</th>
                    @if ($canManage)
                        <th class="px-4 py-2 text-left">Aksi</th>
                    @endif
                </tr>
            </x-slot:head>

            @foreach ($overview['branches'] ?? [] as $row)
                @php
                    $branchModel = $branches->firstWhere('branch_code', $row['branch_code']);
                @endphp
                <tr>
                    <td class="px-4 py-2 font-medium text-navy">{{ $row['branch_code'] }}</td>
                    <td class="px-4 py-2">
                        <x-ui.badge :tone="$row['ingesting'] ? 'success' : 'neutral'">{{ $row['status'] }}</x-ui.badge>
                    </td>
                    {{-- NULL is "no ceiling declared", not zero. --}}
                    <td class="px-4 py-2 text-right text-ink">{{ $row['daily_quota'] ?? '∞' }}</td>
                    <td class="px-4 py-2 text-right text-ink">{{ $row['accepted'] }}</td>
                    <td class="px-4 py-2 text-right text-ink">
                        {{ $row['published'] }}
                        {{-- A percentage only exists when a human counted the archive. --}}
                        @if ($row['completion_percent'] !== null)
                            <span class="text-xs text-ink-muted">({{ $row['completion_percent'] }}%)</span>
                        @endif
                    </td>
                    <td class="px-4 py-2 text-right text-ink">{{ $row['in_flight'] }}</td>
                    <td @class(['px-4 py-2 text-right', 'text-danger-700' => $row['failed_unresolved'] > 0, 'text-ink' => $row['failed_unresolved'] === 0])>
                        {{ $row['failed_unresolved'] }}
                    </td>
                    <td class="px-4 py-2 text-ink-soft">{{ $row['assigned_operators'] }}</td>

                    @if ($canManage && $branchModel)
                        <td class="px-4 py-2">
                            <form method="POST" action="{{ route('settings.rme.migration-operations.branches.update', [$wave, $branchModel]) }}" class="flex flex-wrap items-center gap-2">
                                @csrf
                                <select name="action" class="rounded-lg border-hairline text-sm focus:border-brand-500">
                                    <option value="pause">Jeda</option>
                                    <option value="resume">Lanjutkan</option>
                                    <option value="drain">Akhiri (drain)</option>
                                    <option value="complete">Selesaikan</option>
                                    <option value="cancel">Batalkan</option>
                                </select>
                                <input
                                    type="text"
                                    name="reason"
                                    placeholder="Alasan (min {{ $minReason }})"
                                    class="rounded-lg border-hairline text-sm focus:border-brand-500"
                                />
                                <x-ui.button type="submit" size="sm" variant="secondary">Terapkan</x-ui.button>
                            </form>

                            @if ($row['blockers'])
                                <p class="mt-1 text-xs text-danger-700">{{ implode(', ', $row['blockers']) }}</p>
                            @endif
                        </td>
                    @endif
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    <x-ui.card class="mb-6">
        <x-slot:header>Operator Ditugaskan</x-slot:header>

        @if ($operators->isEmpty())
            <x-ui.empty-state
                title="Belum ada operator"
                description="Tanpa penugasan, tidak ada pengguna yang dapat memigrasikan cabang pada gelombang ini."
            />
        @else
            <x-ui.table>
                <x-slot:head>
                    <tr>
                        <th class="px-4 py-2 text-left">Operator</th>
                        <th class="px-4 py-2 text-left">Cabang</th>
                        <th class="px-4 py-2 text-left">Status</th>
                        @if ($canManage)
                            <th class="px-4 py-2 text-left">Aksi</th>
                        @endif
                    </tr>
                </x-slot:head>

                @foreach ($operators as $assignment)
                    <tr>
                        <td class="px-4 py-2 text-navy">{{ $assignment->user?->name ?? '—' }}</td>
                        <td class="px-4 py-2 text-ink">{{ $assignment->branch_code }}</td>
                        <td class="px-4 py-2">
                            <x-ui.badge :tone="$assignment->isActive() ? 'success' : 'neutral'">
                                {{ $assignment->isActive() ? 'Aktif' : 'Dicabut' }}
                            </x-ui.badge>
                        </td>
                        @if ($canManage)
                            <td class="px-4 py-2">
                                @if ($assignment->isActive())
                                    <form method="POST" action="{{ route('settings.rme.migration-operations.operators.revoke', [$wave, $assignment]) }}">
                                        @csrf
                                        <x-ui.button type="submit" size="sm" variant="danger">Cabut</x-ui.button>
                                    </form>
                                @endif
                            </td>
                        @endif
                    </tr>
                @endforeach
            </x-ui.table>
        @endif
    </x-ui.card>

    {{-- QA sample: structural completeness of published evidence, never clinical
         content. `source_present` is checked on the private disk rather than
         assumed from the row. --}}
    <x-ui.card class="mb-6">
        <x-slot:header>Sampel QA Arsip Terbit</x-slot:header>

        @if (empty($qaSample))
            <x-ui.empty-state title="Belum ada arsip terbit" description="Sampel QA tersedia setelah dokumen diterbitkan." />
        @else
            <x-ui.table>
                <x-slot:head>
                    <tr>
                        <th class="px-4 py-2 text-left">ID Arsip</th>
                        <th class="px-4 py-2 text-left">Cabang</th>
                        <th class="px-4 py-2 text-left">Rentang Tanggal RME</th>
                        <th class="px-4 py-2 text-right">Halaman</th>
                        <th class="px-4 py-2 text-left">Berkas Sumber</th>
                    </tr>
                </x-slot:head>

                @foreach ($qaSample as $sample)
                    <tr>
                        <td class="px-4 py-2 text-navy">#{{ $sample['record_id'] }}</td>
                        <td class="px-4 py-2 text-ink">{{ $sample['branch_code'] ?? '—' }}</td>
                        <td class="px-4 py-2 text-ink">{{ $sample['rme_date'] }} — {{ $sample['latest_rme_date'] }}</td>
                        <td class="px-4 py-2 text-right text-ink">{{ $sample['page_count'] }}</td>
                        <td class="px-4 py-2">
                            @if ($sample['source_present'] === true)
                                <span class="text-success-700">Tersedia</span>
                            @elseif ($sample['source_present'] === false)
                                <span class="text-danger-700">HILANG</span>
                            @else
                                <span class="text-ink-muted">tidak terukur</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>
        @endif
    </x-ui.card>

    @if ($canManage)
        <x-ui.card>
            <x-slot:header>Tindakan Gelombang</x-slot:header>

            <div class="grid gap-4 sm:grid-cols-2">
                <form method="POST" action="{{ route('settings.rme.migration-operations.transition', $wave) }}" class="space-y-2">
                    @csrf
                    <x-ui.select name="action" label="Tindakan">
                        <option value="pause">Jeda gelombang</option>
                        <option value="resume">Lanjutkan gelombang</option>
                        <option value="drain">Akhiri (drain)</option>
                        <option value="complete">Tutup gelombang</option>
                        <option value="cancel">Batalkan gelombang</option>
                    </x-ui.select>
                    <x-ui.textarea name="reason" label="Alasan" :placeholder="'Minimal ' . $minReason . ' karakter'" rows="2" />
                    <x-ui.button type="submit" variant="secondary">Terapkan</x-ui.button>
                </form>

                <div class="space-y-2">
                    @can('approve_legacy_rme_migration_wave')
                        <form method="POST" action="{{ route('settings.rme.migration-operations.approve', $wave) }}">
                            @csrf
                            <x-ui.button type="submit" variant="primary">Setujui Gelombang</x-ui.button>
                        </form>
                    @endcan

                    <form method="POST" action="{{ route('settings.rme.migration-operations.activate', $wave) }}">
                        @csrf
                        <x-ui.button type="submit" variant="success">Jalankan Gelombang</x-ui.button>
                    </form>
                </div>
            </div>

            <div class="mt-6 border-t border-hairline pt-4">
                <h4 class="mb-2 text-sm font-semibold text-navy">Tugaskan Operator</h4>
                <form method="POST" action="{{ route('settings.rme.migration-operations.operators.assign', $wave) }}" class="flex flex-wrap items-end gap-3">
                    @csrf
                    <x-ui.input name="user_id" type="number" min="1" label="ID Pengguna" required />
                    <x-ui.select name="wave_branch_id" label="Cabang">
                        @foreach ($branches as $branchOption)
                            <option value="{{ $branchOption->getKey() }}">{{ $branchOption->branch_code }}</option>
                        @endforeach
                    </x-ui.select>
                    <x-ui.button type="submit" variant="secondary">Tugaskan</x-ui.button>
                </form>
            </div>
        </x-ui.card>
    @endif
</x-settings-shell>
