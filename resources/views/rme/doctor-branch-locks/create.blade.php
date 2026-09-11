{{--
    DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1 — filing an initial assignment or
    a permanent transfer of a doctor's home branch.

    Presentation only. Which doctor a non-approver may file for is decided
    server-side by the controller, which discards a submitted doctor_id for
    anybody who may not file on another doctor's behalf; the `exists` rules in
    the FormRequest are usability filters, not the boundary. Nothing on this page
    changes a branch — approval does, in one transaction.
--}}
<x-settings-shell title="Cabang Tetap Dokter">
    <div class="mx-auto max-w-2xl space-y-6">
        <x-ui.page-header title="Cabang Tetap Dokter">
            <x-slot:breadcrumb>Konteks Kerja — Kunci Cabang Dokter</x-slot:breadcrumb>
            <x-slot:subtitle>
                Cabang tetap dokter hanya berubah melalui persetujuan. Persetujuan akan
                mengakhiri sesi login dokter, dan dokter harus login ulang.
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

        @unless ($mayFileForOthers)
            {{-- The subject's own standing state. "Belum ditetapkan" is the
                 COMPATIBILITY state, not missing data: an unset doctor keeps the
                 exact behaviour they had before this capability existed. --}}
            <x-ui.card>
                <div class="space-y-1">
                    <p class="text-xs font-medium uppercase tracking-wide text-ink-muted">Cabang tetap</p>
                    <p class="text-lg font-semibold text-navy">
                        {{ $currentLock?->homeBranch?->name ?? 'Belum ditetapkan (mengikuti perilaku lama)' }}
                    </p>
                    @if ($selfDoctor === null)
                        <p class="text-sm text-ink-soft">
                            Akun Anda belum terhubung ke data dokter. Hubungi admin untuk
                            menghubungkan user ke master dokter.
                        </p>
                    @elseif ($currentLock === null)
                        <p class="text-sm text-ink-soft">
                            Selama belum ditetapkan, daftar operasional dan pemilihan cabang Anda
                            berjalan seperti sebelumnya.
                        </p>
                    @endif
                </div>
            </x-ui.card>
        @endunless

        @if ($pendingRequest)
            <x-ui.card>
                <div class="space-y-3">
                    <p class="text-sm font-semibold text-navy">
                        {{ $pendingRequest->sourceBranch?->name ?? 'Belum ditetapkan' }}
                        &rarr; {{ $pendingRequest->destinationBranch?->name ?? '—' }}
                    </p>
                    <x-ui.alert variant="warning">
                        Status: <strong>Menunggu Persetujuan</strong>. Cabang tetap Anda belum berubah.
                    </x-ui.alert>
                    <p class="text-sm text-ink-soft">Alasan: {{ $pendingRequest->reason }}</p>
                    <form method="POST" action="{{ route('rme.doctor-branch-locks.cancel', $pendingRequest) }}">
                        @csrf
                        <x-ui.button type="submit" variant="secondary" size="sm">Batalkan Permintaan</x-ui.button>
                    </form>
                </div>
            </x-ui.card>
        @else
            <x-ui.card>
                <form method="POST" action="{{ route('rme.doctor-branch-locks.store') }}" class="space-y-5">
                    @csrf
                    <p class="text-sm font-semibold text-navy">Ajukan Cabang Tetap</p>

                    @if ($mayFileForOthers)
                        <x-ui.select name="doctor_id" label="Dokter" required
                            help="Permintaan diajukan atas nama dokter ini. Dokter tidak dapat menyetujui permintaan untuk akunnya sendiri.">
                            <option value="">- Pilih dokter -</option>
                            @foreach ($doctors as $doctor)
                                <option value="{{ $doctor->id }}" @selected(old('doctor_id') == $doctor->id)>
                                    {{ $doctor->name }}
                                </option>
                            @endforeach
                        </x-ui.select>
                    @endif

                    <x-ui.select name="destination_branch_id" label="Cabang tujuan" required
                        help="Cabang tetap menentukan daftar operasional dokter. Membaca arsip rekam medis lintas cabang tidak terpengaruh.">
                        <option value="">- Pilih cabang tujuan -</option>
                        @foreach ($branches as $branch)
                            <option value="{{ $branch->id }}" @selected(old('destination_branch_id') == $branch->id)>
                                {{ $branch->code }} — {{ $branch->name }}
                            </option>
                        @endforeach
                    </x-ui.select>

                    <x-ui.textarea name="reason" label="Alasan" rows="3" required
                        :value="old('reason')"
                        placeholder="Jelaskan alasan penetapan atau perpindahan cabang tetap." />

                    <div class="flex justify-end border-t border-hairline pt-4">
                        <x-ui.button type="submit" variant="primary">Ajukan Persetujuan</x-ui.button>
                    </div>
                </form>
            </x-ui.card>
        @endif

        @if ($history->isNotEmpty())
            <x-ui.card>
                <p class="mb-3 text-sm font-semibold text-navy">Riwayat Permintaan</p>
                <x-ui.table>
                    <thead class="bg-navy-50 text-left text-ink">
                        <tr>
                            <th class="px-3 py-2 font-medium">Jenis</th>
                            <th class="px-3 py-2 font-medium">Perubahan</th>
                            <th class="px-3 py-2 font-medium">Status</th>
                            <th class="px-3 py-2 font-medium">Keputusan</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-hairline">
                        @foreach ($history as $item)
                            <tr>
                                <td class="px-3 py-2 text-ink-soft">{{ $item->typeLabel() }}</td>
                                <td class="px-3 py-2 text-ink">
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
                            </tr>
                        @endforeach
                    </tbody>
                </x-ui.table>
            </x-ui.card>
        @endif
    </div>
</x-settings-shell>
