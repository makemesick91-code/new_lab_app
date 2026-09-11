{{--
    DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1 — filing a temporary branch cover.

    Presentation only. The service refuses a doctor filing cover for themself, a
    doctor with no home lock, a period outside the configured bounds and a period
    that overlaps an approved cover — the last of which is serialised on the
    doctor's home-lock row, because no index on either engine can express a range
    overlap. The table below shows existing covers so an operator can SEE a
    collision before submitting one; it does not prevent it.
--}}
<x-settings-shell title="Cover Cabang Sementara">
    <div class="mx-auto max-w-3xl space-y-6">
        <x-ui.page-header title="Cover Cabang Sementara">
            <x-slot:breadcrumb>Konteks Kerja — Kunci Cabang Dokter</x-slot:breadcrumb>
            <x-slot:subtitle>
                Cover memberi dokter wewenang operasional sementara di cabang lain. Cabang tetap
                dokter TIDAK berubah, dan setelah periode berakhir dokter otomatis kembali ke
                cabang tetap.
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

        <x-ui.alert variant="info">
            Dokter tidak dapat mengajukan cover untuk dirinya sendiri. Menyetujui cover akan
            mengakhiri sesi login dokter. Cover hanya dapat diajukan untuk dokter yang cabang
            tetapnya sudah ditetapkan.
        </x-ui.alert>

        <x-ui.card>
            <form method="POST" action="{{ route('rme.doctor-branch-covers.store') }}" class="space-y-5">
                @csrf
                <p class="text-sm font-semibold text-navy">Ajukan Cover</p>

                <x-ui.select name="doctor_id" label="Dokter" required
                    help="Cabang tetap ditampilkan di samping nama, sehingga terlihat apa yang akan ditimpa sementara oleh cover.">
                    <option value="">- Pilih dokter -</option>
                    @foreach ($doctors as $doctor)
                        <option value="{{ $doctor->id }}"
                            @selected(old('doctor_id', $selectedDoctorId) == $doctor->id)>
                            {{ $doctor->name }} — cabang tetap: {{ $homeBranchNames[$doctor->id] ?? 'Belum ditetapkan' }}
                        </option>
                    @endforeach
                </x-ui.select>

                <x-ui.select name="target_branch_id" label="Cabang cover" required>
                    <option value="">- Pilih cabang cover -</option>
                    @foreach ($branches as $branch)
                        <option value="{{ $branch->id }}" @selected(old('target_branch_id') == $branch->id)>
                            {{ $branch->code }} — {{ $branch->name }}
                        </option>
                    @endforeach
                </x-ui.select>

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.input type="datetime-local" name="starts_at" label="Mulai" required
                        :value="old('starts_at')"
                        help="Waktu mengikuti zona waktu klinik ({{ $clinicalTimezone }})." />
                    <x-ui.input type="datetime-local" name="ends_at" label="Selesai" required
                        :value="old('ends_at')"
                        help="Waktu mengikuti zona waktu klinik ({{ $clinicalTimezone }}). Batas akhir bersifat eksklusif." />
                </div>

                <x-ui.textarea name="reason" label="Alasan" rows="3" required
                    :value="old('reason')"
                    placeholder="Jelaskan alasan cover cabang sementara." />

                <p class="text-xs text-ink-soft">
                    Durasi cover maksimal {{ $maxDays }} hari. Batas ini diperiksa dua kali —
                    saat diajukan dan sekali lagi di dalam transaksi persetujuan.
                </p>

                <div class="flex justify-end border-t border-hairline pt-4">
                    <x-ui.button type="submit" variant="primary">Ajukan Cover</x-ui.button>
                </div>
            </form>
        </x-ui.card>

        <x-ui.card>
            <p class="mb-1 text-sm font-semibold text-navy">Cover Disetujui untuk Dokter Ini</p>
            <p class="mb-3 text-sm text-ink-soft">
                Status Terjadwal / Sedang Berlaku / Sudah Berakhir dihitung dari periode terhadap
                waktu sekarang, bukan dari kolom yang tersimpan.
            </p>

            <form method="GET" action="{{ route('rme.doctor-branch-covers.create') }}"
                class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-end">
                <div class="grow">
                    <x-ui.select name="doctor_id" id="inspect_doctor_id" label="Lihat cover dokter">
                        <option value="">- Pilih dokter -</option>
                        @foreach ($doctors as $doctor)
                            <option value="{{ $doctor->id }}" @selected($selectedDoctorId == $doctor->id)>
                                {{ $doctor->name }}
                            </option>
                        @endforeach
                    </x-ui.select>
                </div>
                <x-ui.button type="submit" variant="secondary">Tampilkan</x-ui.button>
            </form>

            @if ($existingCovers->isEmpty())
                <x-ui.empty-state
                    title="Belum ada cover disetujui"
                    description="Tidak ada cover yang tercatat untuk dokter yang dipilih." />
            @else
                <x-ui.table>
                    <thead class="bg-navy-50 text-left text-ink">
                        <tr>
                            <th class="px-3 py-2 font-medium">Cabang Cover</th>
                            <th class="px-3 py-2 font-medium">Mulai</th>
                            <th class="px-3 py-2 font-medium">Selesai</th>
                            <th class="px-3 py-2 font-medium">Keadaan</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-hairline">
                        @foreach ($existingCovers as $cover)
                            <tr>
                                <td class="px-3 py-2 text-ink">{{ $cover->targetBranch?->name ?? '—' }}</td>
                                <td class="px-3 py-2 text-ink-soft">
                                    {{ $cover->starts_at?->copy()->setTimezone($clinicalTimezone)->format('d M Y H:i') ?? '—' }}
                                </td>
                                <td class="px-3 py-2 text-ink-soft">
                                    {{ $cover->ends_at?->copy()->setTimezone($clinicalTimezone)->format('d M Y H:i') ?? '—' }}
                                </td>
                                <td class="px-3 py-2">
                                    <x-ui.badge tone="neutral">{{ $coverStates[$cover->id] ?? '—' }}</x-ui.badge>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-ui.table>
            @endif
        </x-ui.card>
    </div>
</x-settings-shell>
