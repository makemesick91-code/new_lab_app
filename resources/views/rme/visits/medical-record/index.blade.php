{{--
    FEATURE-RME-MEDICAL-RECORDS-UNIFIED-NATIVE-LEGACY-1 — unified, patient-centric
    clinical read index. One row per patient holding a native medical record OR a
    PUBLISHED legacy RME OR a PUBLISHED legacy odontogram the viewer may read.
    Native and legacy storage stay separate; this page only reads them.
    Every value here is computed server-side; never render a full NIK.
--}}
<x-settings-shell title="Daftar Rekam Medis">
    @php
        $statusLabels = [
            \App\Modules\MedicalRecord\Models\MedicalRecord::STATUS_DRAFT => 'Draft',
            \App\Modules\MedicalRecord\Models\MedicalRecord::STATUS_FINAL => 'Final',
        ];
        $statusTone = [
            \App\Modules\MedicalRecord\Models\MedicalRecord::STATUS_DRAFT => 'warning',
            \App\Modules\MedicalRecord\Models\MedicalRecord::STATUS_FINAL => 'success',
        ];
        $hasFilter = $filters['search'] || $filters['status'] || $filters['visit_date_from'] || $filters['visit_date_to']
            || $filters['source'] !== \App\Modules\MedicalRecord\Support\UnifiedMedicalRecordSource::ALL;
    @endphp

    <div class="space-y-6">
        <x-ui.page-header
            title="Daftar Rekam Medis"
            subtitle="Pasien dengan rekam medis native dan/atau arsip legacy yang dapat Anda akses."
        >
            <x-slot:breadcrumb>Rekam Medis Elektronik</x-slot:breadcrumb>
        </x-ui.page-header>

        @include('rme.partials.cross-branch-rm-lookup')

        <x-ui.filter-bar :action="route('rme.medical-records.index')">
            <x-ui.input
                name="search"
                label="Cari rekam medis"
                :value="$filters['search']"
                placeholder="Cari nama, nomor RM, dokter, atau no. kunjungan"
                class="min-w-[14rem] flex-1"
            />
            <x-ui.select name="source" label="Sumber">
                @foreach ($sources as $value => $label)
                    <option value="{{ $value }}" @selected($filters['source'] === $value)>{{ $label }}</option>
                @endforeach
            </x-ui.select>
            <x-ui.select name="status" label="Status RM native">
                <option value="">Semua status</option>
                @foreach ($statuses as $status)
                    <option value="{{ $status }}" @selected($filters['status'] === $status)>{{ $statusLabels[$status] ?? $status }}</option>
                @endforeach
            </x-ui.select>
            <x-ui.input type="date" name="visit_date_from" label="Kunjungan dari" :value="$filters['visit_date_from']" />
            <x-ui.input type="date" name="visit_date_to" label="Kunjungan sampai" :value="$filters['visit_date_to']" />
            <x-slot:actions>
                <x-ui.button type="submit" variant="primary">Terapkan</x-ui.button>
                @if ($hasFilter)
                    <x-ui.button variant="secondary" :href="route('rme.medical-records.index')">Atur Ulang</x-ui.button>
                @endif
            </x-slot:actions>
        </x-ui.filter-bar>

        <div class="grid grid-cols-2 gap-3 md:grid-cols-4" data-unified-summary>
            <x-ui.card>
                <p class="text-xs text-ink-soft">Total pasien</p>
                <p class="text-xl font-semibold text-navy" data-summary="total">{{ $summary['total'] }}</p>
            </x-ui.card>
            <x-ui.card>
                <p class="text-xs text-ink-soft">Native</p>
                <p class="text-xl font-semibold text-navy" data-summary="native">{{ $summary['native'] }}</p>
            </x-ui.card>
            <x-ui.card>
                <p class="text-xs text-ink-soft">Legacy</p>
                <p class="text-xl font-semibold text-navy" data-summary="legacy">{{ $summary['legacy'] }}</p>
            </x-ui.card>
            <x-ui.card>
                <p class="text-xs text-ink-soft">Native + Legacy</p>
                <p class="text-xl font-semibold text-navy" data-summary="native_and_legacy">{{ $summary['native_and_legacy'] }}</p>
            </x-ui.card>
        </div>

        <x-ui.card padding="">
            <div class="border-b border-hairline px-4 py-3">
                <h3 class="text-base font-semibold text-navy">Rekam Medis</h3>
                <p class="text-sm text-ink-soft">{{ $patients->total() }} pasien ditemukan.</p>
                <p class="mt-1 text-xs text-ink-muted">
                    Sumber: <strong>Native</strong> = rekam medis sistem ·
                    <strong>Legacy RME</strong> / <strong>Legacy Odontogram</strong> = arsip lama yang sudah dipublikasi (hanya baca).
                </p>
            </div>

            <x-ui.table>
                <thead class="bg-navy-50">
                    <tr class="text-left text-ink-soft">
                        <th scope="col" class="px-4 py-3 font-medium">Pasien</th>
                        <th scope="col" class="px-3 py-3 font-medium">Cabang</th>
                        <th scope="col" class="px-3 py-3 font-medium">Native RME</th>
                        <th scope="col" class="px-3 py-3 font-medium">Kunjungan Terakhir</th>
                        <th scope="col" class="px-3 py-3 font-medium">Ruangan</th>
                        <th scope="col" class="px-3 py-3 font-medium">Legacy RME</th>
                        <th scope="col" class="px-3 py-3 font-medium">Legacy Odontogram</th>
                        <th scope="col" class="px-3 py-3 font-medium">Terakhir Diperbarui</th>
                        <th scope="col" class="px-4 py-3 text-right font-medium">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-hairline">
                    @forelse ($patients as $patient)
                        @php
                            $latest = $latestNative->get($patient->id);
                            $nativeCount = (int) $patient->native_count;
                            $lastActivity = $patient->last_activity_at && ! str_starts_with((string) $patient->last_activity_at, '1970-01-01')
                                ? \Illuminate\Support\Carbon::parse($patient->last_activity_at)
                                : null;
                        @endphp
                        <tr class="hover:bg-navy-50" data-patient-row="{{ $patient->id }}">
                            <td class="px-4 py-3">
                                <span class="font-medium text-navy">{{ $patient->name }}</span>
                                <span class="block font-mono text-xs text-ink-soft">{{ $patient->medical_record_number ?? '—' }}</span>
                            </td>
                            <td class="px-3 py-3 text-ink-soft">{{ $patient->branch?->code ?? '—' }}</td>
                            <td class="px-3 py-3">
                                @if ($nativeCount > 0)
                                    <x-ui.badge tone="info">Native RME · {{ $nativeCount }} sheet</x-ui.badge>
                                    @if ($latest)
                                        <x-ui.badge :tone="$statusTone[$latest->status] ?? 'neutral'">
                                            {{ $statusLabels[$latest->status] ?? $latest->status }}
                                        </x-ui.badge>
                                    @endif
                                @else
                                    <span class="text-ink-muted" data-native="none">—</span>
                                @endif
                            </td>
                            <td class="px-3 py-3 text-ink-soft">
                                @if ($latest?->clinicVisit)
                                    {{ $latest->clinicVisit->visit_date?->format('d/m/Y') ?? '—' }}
                                    <span class="block font-mono text-xs">{{ $latest->clinicVisit->visit_number ?? '—' }}</span>
                                    <span class="block text-xs">{{ $latest->doctor?->name ?? '—' }}</span>
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-3 py-3 text-ink-soft">{{ $latest?->clinicVisit?->clinicRoom?->name ?? '—' }}</td>
                            <td class="px-3 py-3">
                                @if ((int) $patient->legacy_rme_count > 0)
                                    <x-ui.badge tone="success" data-source="legacy-rme">Legacy RME · Tersedia</x-ui.badge>
                                @else
                                    <span class="text-ink-muted">—</span>
                                @endif
                            </td>
                            <td class="px-3 py-3">
                                @if ((int) $patient->legacy_odontogram_count > 0)
                                    <x-ui.badge tone="success" data-source="legacy-odontogram">Legacy Odontogram · Tersedia</x-ui.badge>
                                @else
                                    <span class="text-ink-muted">—</span>
                                @endif
                            </td>
                            <td class="px-3 py-3 text-ink-soft">{{ $lastActivity?->format('d/m/Y H:i') ?? '—' }}</td>
                            <td class="px-4 py-3 text-right">
                                <div class="flex flex-wrap justify-end gap-2">
                                    @if ($latest?->clinicVisit)
                                        {{-- Native daily path preserved: one click into the RM workspace. --}}
                                        <x-ui.button variant="secondary" size="sm" :href="route('rme.visits.medical-record.show', $latest->clinicVisit)">Ruang Kerja RM</x-ui.button>
                                    @endif
                                    <x-ui.button variant="secondary" size="sm" :href="route('rme.medical-records.patients.show', $patient->id)">Buka</x-ui.button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="px-4 py-10">
                                <x-ui.empty-state
                                    title="Belum ada rekam medis."
                                    description="Pasien muncul setelah memiliki rekam medis native atau arsip legacy yang dipublikasi."
                                />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </x-ui.table>

            @if ($patients->hasPages())
                <div class="border-t border-hairline px-4 py-3">
                    {{ $patients->links() }}
                </div>
            @endif
        </x-ui.card>
    </div>
</x-settings-shell>
