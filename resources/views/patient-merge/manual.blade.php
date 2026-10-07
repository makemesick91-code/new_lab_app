{{-- FEATURE-PATIENT-DUPLICATE-RESOLUTION-MERGE-1 — Pilih Pasien Manual.

     Two independent server-side searches inside the operator's branch scope.
     Merged patients are never offered; an old Nomor RM resolves to the patient
     it was merged into. The comparison shows MASKED values only; full NIK and
     phone numbers are compared on the server and never rendered. --}}
<x-settings-shell title="Duplikasi Pasien">
    <div class="space-y-6">
        <x-ui.page-header title="Pilih Pasien Manual"
            subtitle="Cari dan pilih dua data pasien yang Anda curigai sebagai orang yang sama, lalu buat pengajuan merge.">
            <x-slot:breadcrumb>Duplikasi Pasien / Pilih Pasien Manual</x-slot:breadcrumb>
        </x-ui.page-header>

        @include('patient-merge.partials.nav')

        <div class="grid gap-6 lg:grid-cols-2">
            @foreach (['A' => ['param' => 'a', 'query' => 'qa', 'term' => $queryA, 'results' => $resultsA, 'patient' => $patientA], 'B' => ['param' => 'b', 'query' => 'qb', 'term' => $queryB, 'results' => $resultsB, 'patient' => $patientB]] as $side => $panel)
                @php($otherParam = $panel['param'] === 'a' ? 'b' : 'a')
                <x-ui.card :title="'Pasien '.$side">
                    @if ($panel['patient'])
                        <div class="rounded-lg border border-brand-100 bg-brand-50 p-4 text-sm" data-testid="selected-patient-{{ $panel['param'] }}">
                            <div class="font-semibold text-navy">{{ $panel['patient']->name }}</div>
                            <div class="text-ink-soft">{{ $panel['patient']->medical_record_number ?? 'Belum ada RM' }} · {{ $panel['patient']->branchLabel() }}</div>
                            <a class="mt-2 inline-block text-xs font-medium text-brand-700 hover:underline"
                               href="{{ route('patient-merge.manual.create', array_filter([$otherParam => request()->integer($otherParam) ?: null])) }}">Ganti pasien {{ $side }}</a>
                        </div>
                    @else
                        <form method="GET" action="{{ route('patient-merge.manual.create') }}" class="flex items-end gap-2">
                            @if (request()->integer($otherParam))
                                <input type="hidden" name="{{ $otherParam }}" value="{{ request()->integer($otherParam) }}">
                            @endif
                            <div class="flex-1">
                                <x-ui.input :name="$panel['query']" :value="$panel['term']" :label="'Cari nama atau Nomor RM (min. '.$minLength.' karakter)'" autocomplete="off" />
                            </div>
                            <x-ui.button type="submit">Cari</x-ui.button>
                        </form>

                        @if (mb_strlen(trim($panel['term'])) >= $minLength)
                            @if ($panel['results'] === [])
                                <p class="mt-4 text-sm text-ink-soft">Tidak ada pasien yang cocok dalam cakupan cabang Anda.</p>
                            @else
                                <ul class="mt-4 divide-y divide-hairline rounded-lg border border-hairline" role="list">
                                    @foreach ($panel['results'] as $option)
                                        <li class="flex items-center justify-between gap-3 px-3 py-2 text-sm">
                                            <div>
                                                <div class="font-medium text-navy">{{ $option['name'] }}</div>
                                                <div class="text-xs text-ink-soft">{{ $option['medical_record_number'] ?? 'Belum ada RM' }} · {{ $option['branch_label'] }}</div>
                                                @if ($option['via_alias'])
                                                    <div class="text-xs text-warning-700">RM lama {{ $option['via_alias'] }} telah digabungkan ke pasien ini.</div>
                                                @endif
                                            </div>
                                            <x-ui.button size="sm" variant="secondary"
                                                :href="route('patient-merge.manual.create', array_filter([$panel['param'] => $option['id'], $otherParam => request()->integer($otherParam) ?: null]))">Pilih</x-ui.button>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        @endif
                    @endif
                </x-ui.card>
            @endforeach
        </div>

        @if ($samePatient)
            <x-ui.alert variant="danger" title="Pasien yang sama">Pasien A dan Pasien B adalah data yang sama. Pilih dua data pasien yang berbeda.</x-ui.alert>
        @endif

        @if ($comparison)
            @if ($crossBranch && ! $mayCrossBranch)
                <x-ui.alert variant="warning" title="Lintas cabang">Kedua pasien berasal dari cabang berbeda. Penggabungan lintas cabang hanya dapat diajukan oleh peninjau (Supervisor RME).</x-ui.alert>
            @elseif ($crossBranch)
                <x-ui.alert variant="warning" title="Lintas cabang">Kedua pasien berasal dari cabang berbeda. Riwayat kunjungan dan transaksi tetap tercatat di cabang aslinya.</x-ui.alert>
            @endif

            <x-ui.card title="Perbandingan identitas" description="Nilai sensitif ditampilkan tersamar. Rekonsiliasi per kolom dilakukan setelah pengajuan dibuat." padding="p-0">
                <x-ui.table>
                    <thead class="bg-navy-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-ink-soft">Kolom</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-ink-soft">Pasien A</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-ink-soft">Pasien B</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-ink-soft">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-hairline bg-surface">
                        @foreach ($comparison as $row)
                            <tr>
                                <td class="px-4 py-2 text-sm font-medium text-navy">{{ $row['label'] }}@if ($row['critical'])<span class="text-danger"> *</span>@endif</td>
                                <td class="px-4 py-2 text-sm text-ink">{{ $row['a_display'] ?? '—' }}</td>
                                <td class="px-4 py-2 text-sm text-ink">{{ $row['b_display'] ?? '—' }}</td>
                                <td class="px-4 py-2 text-sm">@include('patient-merge.partials.identity-status', ['status' => $row['status']])</td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-ui.table>
            </x-ui.card>

            @if (! $crossBranch || $mayCrossBranch)
                <x-ui.card title="Buat pengajuan merge">
                    <form method="POST" action="{{ route('patient-merge.cases.store') }}" class="space-y-4">
                        @csrf
                        <input type="hidden" name="patient_a_id" value="{{ $patientA->id }}">
                        <input type="hidden" name="patient_b_id" value="{{ $patientB->id }}">
                        <x-ui.textarea name="request_reason" label="Alasan pengajuan" :value="old('request_reason')" rows="3" required
                            help="Jelaskan mengapa kedua data ini diduga orang yang sama (min. 10 karakter)." :error="$errors->first('request_reason')" />
                        <x-ui.button type="submit">Buat Pengajuan Merge</x-ui.button>
                    </form>
                </x-ui.card>
            @endif
        @endif
    </div>
</x-settings-shell>
