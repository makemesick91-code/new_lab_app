{{-- FEATURE-PATIENT-DUPLICATE-RESOLUTION-MERGE-1 — Deteksi Duplikat.

     POSSIBLE duplicates only. Nothing on this page merges anything; a pair
     becomes a merge request only when an operator opens one. NIK/KTP and
     phone are masked; no address, no clinical content. --}}
@php
    use App\Modules\PatientMerge\Services\PatientDuplicateDetectionService as Detection;

    $signalLabels = ['match' => 'Sama', 'similar' => 'Mirip', 'conflict' => 'Berbeda', 'missing' => 'Kosong'];
    $signalTone = ['match' => 'success', 'similar' => 'info', 'conflict' => 'danger', 'missing' => 'neutral'];
    $riskLabels = ['ktp_conflict' => 'Konflik NIK', 'dob_conflict' => 'Konflik tgl lahir', 'gender_conflict' => 'Konflik gender', 'cross_branch' => 'Lintas cabang'];
@endphp

<x-settings-shell title="Duplikasi Pasien">
    <div class="space-y-6">
        <x-ui.page-header title="Deteksi Duplikat"
            subtitle="Pasangan pasien yang mungkin merupakan orang yang sama. Skor hanya membantu urutan peninjauan — bukan keputusan identitas.">
            <x-slot:breadcrumb>Duplikasi Pasien / Deteksi Duplikat</x-slot:breadcrumb>
        </x-ui.page-header>

        @include('patient-merge.partials.nav')

        <x-ui.filter-bar :action="route('patient-merge.candidates.index')">
            <div class="md:w-48">
                <x-ui.select name="confidence" label="Tingkat kemiripan">
                    <option value="">Semua</option>
                    @foreach (Detection::CONFIDENCE_LABELS as $value => $label)
                        <option value="{{ $value }}" @selected(($filters['confidence'] ?? '') === $value)>{{ $label }}</option>
                    @endforeach
                </x-ui.select>
            </div>
            <div class="md:w-48">
                <x-ui.select name="signal" label="Kolom yang sama">
                    <option value="">Semua</option>
                    <option value="date_of_birth" @selected(($filters['signal'] ?? '') === 'date_of_birth')>Tanggal lahir</option>
                    <option value="phone" @selected(($filters['signal'] ?? '') === 'phone')>No. HP / WA</option>
                    <option value="name" @selected(($filters['signal'] ?? '') === 'name')>Nama (hampir sama)</option>
                </x-ui.select>
            </div>
            @if ($branches->count() > 1)
                <div class="md:w-56">
                    <x-ui.select name="branch_id" label="Cabang">
                        <option value="">Semua cabang dalam cakupan</option>
                        @foreach ($branches as $branch)
                            <option value="{{ $branch->id }}" @selected((int) ($filters['branch_id'] ?? 0) === $branch->id)>{{ $branch->code }} — {{ $branch->name }}</option>
                        @endforeach
                    </x-ui.select>
                </div>
            @endif
            <x-slot:actions>
                <x-ui.button type="submit">Terapkan</x-ui.button>
                <x-ui.button variant="secondary" :href="route('patient-merge.candidates.index')">Reset</x-ui.button>
            </x-slot:actions>
        </x-ui.filter-bar>

        <x-ui.card padding="p-0">
            @if ($pairs->isEmpty())
                <div class="p-6"><x-ui.empty-state title="Tidak ada kandidat duplikat" description="Tidak ditemukan pasangan pasien yang cocok dengan filter ini dalam cakupan cabang Anda." /></div>
            @else
                <x-ui.table>
                    <thead class="bg-navy-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-ink-soft">Pasien A</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-ink-soft">Pasien B</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-ink-soft">Indikator</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-ink-soft">Kemiripan</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-ink-soft">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-hairline bg-surface">
                        @foreach ($pairs as $pair)
                            <tr>
                                @foreach (['a', 'b'] as $side)
                                    <td class="px-4 py-3 align-top text-sm">
                                        <div class="font-semibold text-navy">{{ $pair[$side]['name'] }}</div>
                                        <div class="text-ink-soft">{{ $pair[$side]['medical_record_number'] ?? 'Belum ada RM' }}</div>
                                        <div class="text-xs text-ink-muted">{{ $pair[$side]['branch_label'] }} · Lahir {{ $pair[$side]['date_of_birth'] ?? '—' }}</div>
                                        <div class="text-xs text-ink-muted">NIK {{ $pair[$side]['ktp_masked'] ?? '—' }} · HP {{ $pair[$side]['phone_masked'] ?? '—' }}</div>
                                    </td>
                                @endforeach
                                <td class="px-4 py-3 align-top">
                                    <div class="flex flex-wrap gap-1">
                                        @foreach (['name' => 'Nama', 'date_of_birth' => 'Lahir', 'phone' => 'HP', 'gender' => 'Gender', 'ktp_number' => 'NIK'] as $key => $label)
                                            @php($signal = $pair['signals'][$key] ?? 'missing')
                                            <x-ui.badge :tone="$signalTone[$signal]">{{ $label }}: {{ $signalLabels[$signal] }}</x-ui.badge>
                                        @endforeach
                                    </div>
                                    @if ($pair['risk_flags'] !== [])
                                        <div class="mt-2 flex flex-wrap gap-1">
                                            @foreach ($pair['risk_flags'] as $flag)
                                                <x-ui.badge tone="warning">{{ $riskLabels[$flag] ?? $flag }}</x-ui.badge>
                                            @endforeach
                                        </div>
                                    @endif
                                </td>
                                <td class="px-4 py-3 align-top text-sm">
                                    <div class="font-semibold text-navy">{{ Detection::CONFIDENCE_LABELS[$pair['confidence']] }}</div>
                                    <div class="text-xs text-ink-muted">Skor {{ $pair['score'] }}/100 · nama {{ (int) round($pair['name_similarity'] * 100) }}%</div>
                                </td>
                                <td class="px-4 py-3 text-right align-top">
                                    @if ($pair['open_case_uuid'])
                                        <x-ui.button size="sm" variant="secondary" :href="route('patient-merge.cases.show', $pair['open_case_uuid'])">Lihat pengajuan</x-ui.button>
                                    @else
                                        @can('request_patient_merge')
                                            <x-ui.button size="sm" :href="route('patient-merge.manual.create', ['a' => $pair['a']['id'], 'b' => $pair['b']['id']])">Tinjau</x-ui.button>
                                        @endcan
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-ui.table>
                <div class="border-t border-hairline p-4">{{ $pairs->links() }}</div>
            @endif
        </x-ui.card>

        <p class="text-xs text-ink-muted">
            Kandidat dihitung saat halaman dibuka, hanya di antara pasien yang berbagi tanggal lahir atau nomor HP (maksimum {{ Detection::MAX_PAIRS }} pasangan).
            Kemiripan tanggal lahir saja tidak pernah dianggap duplikat.
        </p>
    </div>
</x-settings-shell>
