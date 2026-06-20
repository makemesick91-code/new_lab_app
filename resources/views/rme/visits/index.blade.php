<x-settings-shell title="Kunjungan Pasien">
    @php
        $statusLabels = [
            'registered'  => 'Terdaftar',
            'waiting'     => 'Menunggu',
            'in_progress' => 'Dalam Pemeriksaan',
            'completed'   => 'Selesai',
            'cancelled'   => 'Dibatalkan',
        ];
        $statusTone = [
            'registered'  => 'info',
            'waiting'     => 'warning',
            'in_progress' => 'primary',
            'completed'   => 'success',
            'cancelled'   => 'danger',
        ];
    @endphp

    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">Rekam Medis Elektronik</p>
                <h2 class="mt-1 text-xl font-semibold text-gray-900">Kunjungan Pasien</h2>
                <p class="mt-1 text-sm text-gray-500">Antrian dan riwayat kunjungan seluruh Cabang RME aktif.</p>
            </div>
            @can('create', \App\Modules\ClinicVisit\Models\ClinicVisit::class)
                <x-ui.button variant="primary" :href="route('rme.visits.create')">+ Daftar Kunjungan</x-ui.button>
            @endcan
        </div>

        @include('rme.partials.cross-branch-rm-lookup')

        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
            @php
                $branchQuery = $filters['branch_id'] ? ['branch_id' => $filters['branch_id']] : [];
                $rmeWidgetDefs = [
                    [
                        'label' => 'Kunjungan Hari Ini',
                        'value' => $rmeWidgets['visits_today'] ?? 0,
                        'href'  => route('rme.visits.index', $branchQuery),
                    ],
                    [
                        'label' => 'Menunggu',
                        'value' => $rmeWidgets['waiting'] ?? 0,
                        'href'  => route('rme.visits.index', $branchQuery + ['status' => 'waiting']),
                    ],
                    [
                        'label' => 'Sedang Dilayani',
                        'value' => $rmeWidgets['in_progress'] ?? 0,
                        'href'  => route('rme.visits.index', $branchQuery + ['status' => 'in_progress']),
                    ],
                    [
                        'label' => 'RM Draft',
                        'value' => $rmeWidgets['draft_medical_records'] ?? 0,
                        'href'  => route('rme.medical-records.index', ['status' => 'draft']),
                    ],
                    [
                        'label' => 'RM Final Hari Ini',
                        'value' => $rmeWidgets['finalized_today'] ?? 0,
                        'href'  => route('rme.medical-records.index', ['status' => 'final']),
                    ],
                ];
            @endphp
            @foreach ($rmeWidgetDefs as $widget)
                <a href="{{ $widget['href'] }}"
                   class="ui-card flex flex-col p-4 transition-shadow hover:border-teal-300 hover:shadow-md">
                    <span class="text-2xl font-bold text-gray-900">{{ $widget['value'] }}</span>
                    <span class="mt-1 text-xs font-medium text-gray-500">{{ $widget['label'] }}</span>
                </a>
            @endforeach
        </div>

        <x-ui.card padding="p-4">
            <form method="GET" action="{{ route('rme.visits.index') }}">
                <div class="flex flex-wrap items-end gap-2">
                    <div class="min-w-[12rem] flex-1">
                        <label for="visit-search" class="text-sm font-medium text-gray-700">Cari kunjungan</label>
                        <input id="visit-search" type="text" name="search" value="{{ $filters['search'] }}" placeholder="Cari nomor atau nama pasien"
                               class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500" />
                    </div>
                    <div>
                        <label for="visit-date" class="text-sm font-medium text-gray-700">Tanggal</label>
                        <input id="visit-date" type="date" name="visit_date" value="{{ $filters['visit_date'] }}"
                               class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500" />
                    </div>
                    <div>
                        <label for="visit-status" class="text-sm font-medium text-gray-700">Status</label>
                        <select id="visit-status" name="status" class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                            <option value="">Semua status</option>
                            @foreach ($statuses as $status)
                                <option value="{{ $status }}" @selected($filters['status'] === $status)>{{ $statusLabels[$status] ?? $status }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="visit-branch" class="text-sm font-medium text-gray-700">Cabang RME</label>
                        <select id="visit-branch" name="branch_id" class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                            <option value="">Semua Cabang RME</option>
                            @foreach ($rmeBranches as $branch)
                                <option value="{{ $branch->id }}" @selected((int) $filters['branch_id'] === (int) $branch->id)>{{ $branch->code }} — {{ $branch->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <x-ui.button type="submit" variant="neutral">Terapkan</x-ui.button>
                    @if ($filters['search'] || $filters['status'] || $filters['visit_date'] || $filters['branch_id'])
                        <x-ui.button variant="secondary" :href="route('rme.visits.index')">Atur Ulang</x-ui.button>
                    @endif
                </div>
            </form>
        </x-ui.card>

        <x-ui.card padding="">
            <div class="border-b border-gray-200 px-4 py-3">
                <h3 class="text-base font-semibold text-gray-900">Daftar Kunjungan</h3>
                <p class="text-sm text-gray-500">{{ $visits->total() }} kunjungan ditemukan.</p>
            </div>

            <x-ui.table>
                <thead class="bg-gray-50">
                    <tr class="text-left text-gray-500">
                        <th scope="col" class="px-4 py-3 font-medium">No. Kunjungan</th>
                        <th scope="col" class="px-3 py-3 font-medium">Antrian</th>
                        <th scope="col" class="px-3 py-3 font-medium">Pasien</th>
                        <th scope="col" class="px-3 py-3 font-medium">Klinik/Cabang</th>
                        <th scope="col" class="px-3 py-3 font-medium">Dokter</th>
                        <th scope="col" class="px-3 py-3 font-medium">Tanggal</th>
                        <th scope="col" class="px-3 py-3 font-medium">Status</th>
                        <th scope="col" class="px-4 py-3 text-right font-medium">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($visits as $visit)
                        @php
                            $isTerminal = in_array($visit->status, [
                                \App\Modules\ClinicVisit\Models\ClinicVisit::STATUS_COMPLETED,
                                \App\Modules\ClinicVisit\Models\ClinicVisit::STATUS_CANCELLED,
                            ]);
                        @endphp
                        <tr class="hover:bg-gray-50 {{ $isTerminal ? 'opacity-60' : '' }}">
                            <td class="px-4 py-3 font-mono text-gray-700">{{ $visit->visit_number }}</td>
                            <td class="px-3 py-3 text-center font-semibold text-gray-900">{{ $visit->queue_number }}</td>
                            <td class="px-3 py-3 font-medium text-gray-900">
                                {{ $visit->patient?->name ?? '—' }}
                                @if ($visit->patient?->medical_record_number)
                                    <span class="block font-mono text-xs font-normal text-gray-400">{{ $visit->patient->medical_record_number }}</span>
                                @endif
                            </td>
                            <td class="px-3 py-3 text-gray-600">{{ $visit->branch ? $visit->branch->code.' — '.$visit->branch->name : '—' }}</td>
                            <td class="px-3 py-3 text-gray-600">{{ $visit->doctor?->name ?? '—' }}</td>
                            <td class="px-3 py-3 text-gray-600">{{ $visit->visit_date?->format('d/m/Y') }}</td>
                            <td class="px-3 py-3">
                                <x-ui.badge :tone="$statusTone[$visit->status] ?? 'neutral'">
                                    {{ $statusLabels[$visit->status] ?? $visit->status }}
                                </x-ui.badge>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap items-center justify-end gap-2">
                                    <x-ui.button variant="secondary" :href="route('rme.visits.show', $visit)" class="!px-3 !py-1.5 !text-xs">Detail</x-ui.button>
                                    @if (!$isTerminal)
                                        @can('update', $visit)
                                            <x-ui.button variant="primary" :href="route('rme.visits.edit', $visit)" class="!px-3 !py-1.5 !text-xs">Ubah</x-ui.button>
                                        @endcan
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-4 py-12 text-center">
                                <p class="text-sm font-medium text-gray-900">Belum ada kunjungan.</p>
                                <p class="mt-1 text-sm text-gray-500">Daftarkan kunjungan baru untuk memulai antrian hari ini.</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </x-ui.table>

            <div class="border-t border-gray-200 px-4 py-3">{{ $visits->links() }}</div>
        </x-ui.card>
    </div>
</x-settings-shell>
