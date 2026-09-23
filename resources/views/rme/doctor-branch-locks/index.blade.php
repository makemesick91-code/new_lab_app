{{--
    DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1 — the approver queue.

    NOTHING ON THIS PAGE IS A BOUNDARY. The presence badge, the acknowledgement
    checkbox and the confirm() dialog are all re-checked server-side inside the
    approval transaction, which re-reads presence, the doctor row, the live lock
    and the destination branch under a row lock. A screen rendered ten minutes
    ago can never decide anything.

    Every state is carried by TEXT, never by colour alone.

    Two things this page exists to make visible:
      * ruling P11 — the subject doctor's live presence, because approving ends
        their session while they may be mid-treatment;
      * finding U3 — a lock that has silently STOPPED APPLYING because its
        branch is no longer an active RME branch. The degradation is handled
        (the doctor falls back to legacy behaviour rather than being stopped
        dead) but it is not audited on every page view, so this is where an
        admin finds out.
--}}
<x-settings-shell title="Kunci Cabang Dokter">
    <div class="space-y-6">
        <x-ui.page-header title="Kunci Cabang Dokter">
            <x-slot:breadcrumb>Konteks Kerja — Kunci Cabang Dokter</x-slot:breadcrumb>
            <x-slot:subtitle>
                Cabang tetap dokter dan cover sementara. Menyetujui langsung mengakhiri sesi
                login dokter — dokter harus login ulang. Perangkat, otorisasi dan kredensial
                WebAuthn tidak dicabut.
            </x-slot:subtitle>
        </x-ui.page-header>

        @if (session('status'))
            <x-ui.alert variant="success">{{ session('status') }}</x-ui.alert>
        @endif

        @if ($errors->any())
            <x-ui.alert variant="danger">
                <ul class="list-inside list-disc space-y-1">
                    @foreach ($errors->all() as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            </x-ui.alert>
        @endif

        {{-- FINDING U3. Rendered first and unconditionally when non-empty: a
             lock that has stopped applying is the one thing on this page an
             admin would otherwise never be told about. --}}
        @if (! empty($degradedLocks))
            <x-ui.card>
                <p class="mb-1 text-sm font-semibold text-navy">Kunci Cabang Tidak Berlaku</p>
                <p class="mb-3 text-sm text-ink-soft">
                    Baris kunci berikut tersimpan tetapi sedang tidak mengikat dokternya, sehingga
                    dokter kembali ke perilaku lama (tidak dikunci). Ini bukan kegagalan sistem —
                    ini penurunan status yang ditangani — tetapi perlu ditindaklanjuti.
                </p>
                <x-ui.table>
                    <thead class="bg-navy-50 text-left text-ink">
                        <tr>
                            <th class="px-3 py-2 font-medium">Dokter</th>
                            <th class="px-3 py-2 font-medium">Cabang Tetap Tersimpan</th>
                            <th class="px-3 py-2 font-medium">Status</th>
                            <th class="px-3 py-2 font-medium">Kode Alasan</th>
                            <th class="px-3 py-2 font-medium">Penjelasan</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-hairline">
                        @foreach ($degradedLocks as $row)
                            <tr>
                                <td class="px-3 py-2 text-ink">{{ $row['doctor_name'] ?? '—' }}</td>
                                <td class="px-3 py-2 text-ink-soft">
                                    {{ $row['home_branch_name'] ?? '—' }}
                                    @if ($row['home_branch_id'] !== null)
                                        <span class="block text-xs text-ink-muted">ID cabang: {{ $row['home_branch_id'] }}</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2">
                                    <x-ui.badge tone="warning">TIDAK BERLAKU</x-ui.badge>
                                </td>
                                <td class="px-3 py-2 text-ink-soft"><code class="text-xs">{{ $row['reason_code'] }}</code></td>
                                <td class="px-3 py-2 text-ink-soft">{{ $row['reason_label'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-ui.table>
            </x-ui.card>
        @endif

        {{-- 1. Pending assignments and transfers. --}}
        <x-ui.card>
            <p class="mb-3 text-sm font-semibold text-navy">Menunggu Persetujuan — Penetapan &amp; Perpindahan</p>

            @if ($pendingRequests->isEmpty())
                <x-ui.empty-state
                    title="Tidak ada permintaan kunci cabang"
                    description="Penetapan awal dan perpindahan cabang tetap yang menunggu keputusan akan muncul di sini." />
            @else
                <x-ui.table>
                    <thead class="bg-navy-50 text-left text-ink">
                        <tr>
                            <th class="px-3 py-2 font-medium">Dokter</th>
                            <th class="px-3 py-2 font-medium">Kehadiran</th>
                            <th class="px-3 py-2 font-medium">Jenis</th>
                            <th class="px-3 py-2 font-medium">Cabang Sekarang</th>
                            <th class="px-3 py-2 font-medium">Cabang Tujuan</th>
                            <th class="px-3 py-2 font-medium">Alasan</th>
                            <th class="px-3 py-2 font-medium">Diajukan Oleh</th>
                            <th class="px-3 py-2 font-medium">Waktu</th>
                            <th class="px-3 py-2 font-medium">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-hairline">
                        @foreach ($pendingRequests as $item)
                            @php($subjectId = (int) $item->doctor_id)
                            @php($subjectPresence = $presence[$subjectId] ?? ['online' => false, 'linked' => true, 'branch' => null, 'room' => null])
                            @php($subjectName = $item->doctor?->name ?? 'Dokter')
                            <tr>
                                <td class="px-3 py-2 text-ink">{{ $subjectName }}</td>
                                <td class="px-3 py-2">
                                    <x-rme.doctor-presence-cell :presence="$subjectPresence" />
                                </td>
                                <td class="px-3 py-2 text-ink-soft">{{ $item->typeLabel() }}</td>
                                <td class="px-3 py-2 text-ink-soft">{{ $item->sourceBranch?->name ?? 'Belum ditetapkan' }}</td>
                                <td class="px-3 py-2 font-medium text-ink">{{ $item->destinationBranch?->name ?? '—' }}</td>
                                <td class="px-3 py-2 text-ink-soft">{{ $item->reason }}</td>
                                <td class="px-3 py-2 text-ink-soft">{{ $item->requester?->name ?? '—' }}</td>
                                <td class="px-3 py-2 text-ink-soft">
                                    {{ $item->requested_at?->copy()->setTimezone($clinicalTimezone)->format('d M Y H:i') ?? '—' }}
                                </td>
                                <td class="px-3 py-2">
                                    @if ($mayDecide)
                                        <div class="flex flex-col gap-2">
                                            <form method="POST"
                                                action="{{ route('rme.doctor-branch-locks.approve', $item) }}"
                                                class="space-y-2"
                                                onsubmit="return confirm(@js($subjectPresence['online']
                                                    ? 'Setujui dan akhiri sesi '.$subjectName.' sekarang? Dokter sedang online.'
                                                    : 'Setujui perubahan cabang untuk '.$subjectName.'? Sesi login dokter akan diakhiri.'));">
                                                @csrf
                                                @if ($subjectPresence['online'])
                                                    <x-rme.doctor-online-acknowledgement :name="$subjectName" :field="'lock-'.$item->id" />
                                                @endif
                                                <x-ui.button type="submit" variant="success" size="sm">Setujui</x-ui.button>
                                            </form>
                                            {{-- A rejection requires a reason: the service refuses one
                                                 without it, so the box is here rather than leaving the
                                                 approver to discover that from a validation error. --}}
                                            <form method="POST"
                                                action="{{ route('rme.doctor-branch-locks.reject', $item) }}"
                                                class="space-y-2"
                                                onsubmit="return confirm(@js('Tolak permintaan ini? Cabang tetap '.$subjectName.' tidak berubah dan sesinya tidak diakhiri.'));">
                                                @csrf
                                                <x-ui.input name="decision_note" label="Alasan penolakan"
                                                    placeholder="Minimal {{ $reasonMinLength }} karakter" />
                                                <x-ui.button type="submit" variant="danger" size="sm">Tolak</x-ui.button>
                                            </form>
                                        </div>
                                    @else
                                        <span class="text-xs text-ink-muted">Hanya dapat dilihat</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-ui.table>
            @endif
        </x-ui.card>

        {{-- 2. Pending covers. --}}
        <x-ui.card>
            <p class="mb-3 text-sm font-semibold text-navy">Menunggu Persetujuan — Cover Sementara</p>

            @if ($pendingCovers->isEmpty())
                <x-ui.empty-state
                    title="Tidak ada pengajuan cover"
                    description="Cover memberi dokter wewenang operasional sementara di cabang lain tanpa mengubah cabang tetapnya." />
            @else
                <x-ui.table>
                    <thead class="bg-navy-50 text-left text-ink">
                        <tr>
                            <th class="px-3 py-2 font-medium">Dokter</th>
                            <th class="px-3 py-2 font-medium">Kehadiran</th>
                            <th class="px-3 py-2 font-medium">Cabang Tetap</th>
                            <th class="px-3 py-2 font-medium">Cabang Cover</th>
                            <th class="px-3 py-2 font-medium">Mulai</th>
                            <th class="px-3 py-2 font-medium">Selesai</th>
                            <th class="px-3 py-2 font-medium">Alasan</th>
                            <th class="px-3 py-2 font-medium">Diajukan Oleh</th>
                            <th class="px-3 py-2 font-medium">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-hairline">
                        @foreach ($pendingCovers as $cover)
                            @php($subjectId = (int) $cover->doctor_id)
                            @php($subjectPresence = $presence[$subjectId] ?? ['online' => false, 'linked' => true, 'branch' => null, 'room' => null])
                            @php($subjectName = $cover->doctor?->name ?? 'Dokter')
                            <tr>
                                <td class="px-3 py-2 text-ink">{{ $subjectName }}</td>
                                <td class="px-3 py-2">
                                    <x-rme.doctor-presence-cell :presence="$subjectPresence" />
                                </td>
                                <td class="px-3 py-2 text-ink-soft">{{ $cover->sourceHomeBranch?->name ?? '—' }}</td>
                                <td class="px-3 py-2 font-medium text-ink">{{ $cover->targetBranch?->name ?? '—' }}</td>
                                <td class="px-3 py-2 text-ink-soft">
                                    {{ $cover->starts_at?->copy()->setTimezone($clinicalTimezone)->format('d M Y H:i') ?? '—' }}
                                </td>
                                <td class="px-3 py-2 text-ink-soft">
                                    {{ $cover->ends_at?->copy()->setTimezone($clinicalTimezone)->format('d M Y H:i') ?? '—' }}
                                </td>
                                <td class="px-3 py-2 text-ink-soft">{{ $cover->reason }}</td>
                                <td class="px-3 py-2 text-ink-soft">{{ $cover->requester?->name ?? '—' }}</td>
                                <td class="px-3 py-2">
                                    <div class="flex flex-col gap-2">
                                        @if ($mayDecide)
                                            <form method="POST"
                                                action="{{ route('rme.doctor-branch-covers.approve', $cover) }}"
                                                class="space-y-2"
                                                onsubmit="return confirm(@js($subjectPresence['online']
                                                    ? 'Setujui cover dan akhiri sesi '.$subjectName.' sekarang? Dokter sedang online.'
                                                    : 'Setujui cover untuk '.$subjectName.'? Sesi login dokter akan diakhiri.'));">
                                                @csrf
                                                @if ($subjectPresence['online'])
                                                    <x-rme.doctor-online-acknowledgement :name="$subjectName" :field="'cover-'.$cover->id" />
                                                @endif
                                                <x-ui.button type="submit" variant="success" size="sm">Setujui</x-ui.button>
                                            </form>
                                            {{-- Same rule as the lock request above: the service refuses a
                                                 rejection with no reason, so the box is offered here. --}}
                                            <form method="POST"
                                                action="{{ route('rme.doctor-branch-covers.reject', $cover) }}"
                                                class="space-y-2"
                                                onsubmit="return confirm(@js('Tolak pengajuan cover untuk '.$subjectName.'? Cabang dokter tidak berubah.'));">
                                                @csrf
                                                <x-ui.input name="decision_note" label="Alasan penolakan"
                                                    placeholder="Minimal {{ $reasonMinLength }} karakter" />
                                                <x-ui.button type="submit" variant="danger" size="sm">Tolak</x-ui.button>
                                            </form>
                                        @endif
                                        <form method="POST"
                                            action="{{ route('rme.doctor-branch-covers.cancel', $cover) }}"
                                            class="space-y-2"
                                            onsubmit="return confirm(@js('Batalkan pengajuan cover untuk '.$subjectName.'?'));">
                                            @csrf
                                            <x-ui.input name="cancellation_reason" label="Alasan pembatalan"
                                                placeholder="Minimal {{ $reasonMinLength }} karakter" />
                                            <x-ui.button type="submit" variant="secondary" size="sm">Batalkan</x-ui.button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-ui.table>
            @endif
        </x-ui.card>

        {{-- 3. Current locks, live cover, and the lease-release action. --}}
        <x-ui.card>
            <p class="mb-3 text-sm font-semibold text-navy">Cabang Terkunci Saat Ini</p>

            @if ($currentLocks->isEmpty())
                <x-ui.empty-state
                    title="Belum ada dokter yang dikunci"
                    description="Setiap dokter dimulai dari keadaan belum ditetapkan, yang berarti perilaku lama tetap berlaku sampai penetapan pertama disetujui." />
            @else
                <x-ui.table>
                    <thead class="bg-navy-50 text-left text-ink">
                        <tr>
                            <th class="px-3 py-2 font-medium">Dokter</th>
                            <th class="px-3 py-2 font-medium">Kehadiran</th>
                            <th class="px-3 py-2 font-medium">Cabang Tetap</th>
                            <th class="px-3 py-2 font-medium">Cover Aktif</th>
                            <th class="px-3 py-2 font-medium">Ditetapkan Oleh</th>
                            <th class="px-3 py-2 font-medium">Waktu</th>
                            <th class="px-3 py-2 font-medium">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-hairline">
                        @foreach ($currentLocks as $lock)
                            @php($subjectId = (int) $lock->doctor_id)
                            @php($subjectPresence = $presence[$subjectId] ?? ['online' => false, 'linked' => true, 'branch' => null, 'room' => null])
                            @php($subjectName = $lock->doctor?->name ?? 'Dokter')
                            @php($activeCover = $activeCovers[$subjectId] ?? null)
                            <tr>
                                <td class="px-3 py-2 text-ink">{{ $subjectName }}</td>
                                <td class="px-3 py-2">
                                    <x-rme.doctor-presence-cell :presence="$subjectPresence" />
                                </td>
                                <td class="px-3 py-2 font-medium text-ink">{{ $lock->homeBranch?->name ?? '—' }}</td>
                                <td class="px-3 py-2 text-ink-soft">
                                    @if ($activeCover)
                                        <x-ui.badge tone="info">Aktif</x-ui.badge>
                                        <span class="ml-1">
                                            {{ $activeCover->targetBranch?->name ?? '—' }}
                                            s/d {{ $activeCover->ends_at?->copy()->setTimezone($clinicalTimezone)->format('d M Y H:i') ?? '—' }}
                                        </span>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-ink-soft">{{ $lock->establishedBy?->name ?? '—' }}</td>
                                <td class="px-3 py-2 text-ink-soft">
                                    {{ $lock->established_at?->copy()->setTimezone($clinicalTimezone)->format('d M Y H:i') ?? '—' }}
                                </td>
                                <td class="px-3 py-2">
                                    @if ($mayReleaseSession)
                                        <form method="POST"
                                            action="{{ route('rme.doctor-branch-locks.release-session', $subjectId) }}"
                                            class="space-y-2"
                                            onsubmit="return confirm(@js('Akhiri sesi login dokter '.$subjectName.'? Dokter harus login ulang. Perangkat, otorisasi dan kredensial WebAuthn TIDAK dicabut.'));">
                                            @csrf
                                            <x-ui.input name="reason" label="Alasan mengakhiri sesi" required
                                                placeholder="Minimal {{ $reasonMinLength }} karakter" />
                                            <x-ui.button type="submit" variant="secondary" size="sm">Akhiri Sesi Dokter</x-ui.button>
                                        </form>
                                    @else
                                        <span class="text-xs text-ink-muted">Hanya dapat dilihat</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-ui.table>
            @endif
        </x-ui.card>

        {{-- 4. Decision history. --}}
        <x-ui.card>
            <p class="mb-3 text-sm font-semibold text-navy">Riwayat Keputusan</p>

            @if ($decidedRequests->isEmpty() && $decidedCovers->isEmpty())
                <x-ui.empty-state title="Belum ada keputusan tercatat" />
            @else
                <x-ui.table>
                    <thead class="bg-navy-50 text-left text-ink">
                        <tr>
                            <th class="px-3 py-2 font-medium">Dokter</th>
                            <th class="px-3 py-2 font-medium">Perubahan</th>
                            <th class="px-3 py-2 font-medium">Status</th>
                            <th class="px-3 py-2 font-medium">Diputuskan Oleh</th>
                            <th class="px-3 py-2 font-medium">Waktu</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-hairline">
                        @foreach ($decidedRequests as $item)
                            <tr>
                                <td class="px-3 py-2 text-ink">{{ $item->doctor?->name ?? '—' }}</td>
                                <td class="px-3 py-2 text-ink-soft">
                                    {{ $item->typeLabel() }}:
                                    {{ $item->sourceBranch?->name ?? 'Belum ditetapkan' }}
                                    &rarr; {{ $item->destinationBranch?->name ?? '—' }}
                                </td>
                                <td class="px-3 py-2 text-ink-soft">{{ $item->statusLabel() }}</td>
                                <td class="px-3 py-2 text-ink-soft">
                                    {{ $item->decidedBy?->name ?? '—' }}
                                    @if ($item->decision_note)
                                        <span class="block text-xs text-ink-muted">{{ $item->decision_note }}</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-ink-soft">
                                    {{ $item->decided_at?->copy()->setTimezone($clinicalTimezone)->format('d M Y H:i') ?? '—' }}
                                </td>
                            </tr>
                        @endforeach
                        @foreach ($decidedCovers as $cover)
                            <tr>
                                <td class="px-3 py-2 text-ink">{{ $cover->doctor?->name ?? '—' }}</td>
                                <td class="px-3 py-2 text-ink-soft">
                                    Cover: {{ $cover->targetBranch?->name ?? '—' }}
                                    ({{ $cover->starts_at?->copy()->setTimezone($clinicalTimezone)->format('d M Y H:i') ?? '—' }}
                                    &ndash; {{ $cover->ends_at?->copy()->setTimezone($clinicalTimezone)->format('d M Y H:i') ?? '—' }})
                                </td>
                                <td class="px-3 py-2 text-ink-soft">
                                    {{ $cover->statusLabel() }}
                                    @if (isset($coverStates[$cover->id]))
                                        <span class="block text-xs text-ink-muted">{{ $coverStates[$cover->id] }}</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-ink-soft">
                                    {{ $cover->decidedBy?->name ?? '—' }}
                                    @if ($cover->decision_note)
                                        <span class="block text-xs text-ink-muted">{{ $cover->decision_note }}</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-ink-soft">
                                    {{ $cover->decided_at?->copy()->setTimezone($clinicalTimezone)->format('d M Y H:i') ?? '—' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-ui.table>
            @endif
        </x-ui.card>
    </div>
</x-settings-shell>
