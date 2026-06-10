<x-settings-shell title="Kunjungan Pasien">
    @php
        $statusLabels = [
            'registered'  => 'Terdaftar',
            'waiting'     => 'Menunggu',
            'in_progress' => 'Dalam Pemeriksaan',
            'completed'   => 'Selesai',
            'cancelled'   => 'Dibatalkan',
        ];
        $statusBadge = [
            'registered'  => 'bg-blue-50 text-blue-700',
            'waiting'     => 'bg-amber-50 text-amber-700',
            'in_progress' => 'bg-indigo-50 text-indigo-700',
            'completed'   => 'bg-green-50 text-green-700',
            'cancelled'   => 'bg-red-50 text-red-700',
        ];
    @endphp

    <div class="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
        @php
            $rmeWidgetDefs = [
                [
                    'label' => 'Kunjungan Hari Ini',
                    'value' => $rmeWidgets['visits_today'] ?? 0,
                    'href'  => route('rme.visits.index'),
                ],
                [
                    'label' => 'Menunggu',
                    'value' => $rmeWidgets['waiting'] ?? 0,
                    'href'  => route('rme.visits.index', ['status' => 'waiting']),
                ],
                [
                    'label' => 'Sedang Dilayani',
                    'value' => $rmeWidgets['in_progress'] ?? 0,
                    'href'  => route('rme.visits.index', ['status' => 'in_progress']),
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
               class="flex flex-col rounded-lg border border-gray-200 bg-white p-4 shadow-sm hover:border-indigo-300 hover:shadow-md transition-shadow">
                <span class="text-2xl font-bold text-gray-900">{{ $widget['value'] }}</span>
                <span class="mt-1 text-xs font-medium text-gray-500">{{ $widget['label'] }}</span>
            </a>
        @endforeach
    </div>

    <div class="bg-white shadow-sm sm:rounded-lg">
        <div class="p-6 space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <form method="GET" action="{{ route('rme.visits.index') }}" class="flex flex-wrap items-center gap-2">
                    <input type="text" name="search" value="{{ $filters['search'] }}" placeholder="Cari nomor atau nama pasien"
                           class="rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                    <input type="date" name="visit_date" value="{{ $filters['visit_date'] }}"
                           class="rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                    <select name="status" class="rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">Semua status</option>
                        @foreach ($statuses as $status)
                            <option value="{{ $status }}" @selected($filters['status'] === $status)>{{ $statusLabels[$status] ?? $status }}</option>
                        @endforeach
                    </select>
                    <button type="submit" class="inline-flex items-center rounded-md bg-gray-800 px-3 py-2 text-sm font-medium text-white hover:bg-gray-700">Terapkan</button>
                    @if ($filters['search'] || $filters['status'] || $filters['visit_date'])
                        <a href="{{ route('rme.visits.index') }}" class="text-sm text-gray-500 hover:text-gray-700">Atur Ulang</a>
                    @endif
                </form>
                @can('create', \App\Modules\ClinicVisit\Models\ClinicVisit::class)
                    <a href="{{ route('rme.visits.create') }}" class="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">+ Daftar Kunjungan</a>
                @endcan
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead>
                        <tr class="text-left text-gray-500">
                            <th class="px-3 py-2 font-medium">No. Kunjungan</th>
                            <th class="px-3 py-2 font-medium">Antrian</th>
                            <th class="px-3 py-2 font-medium">Pasien</th>
                            <th class="px-3 py-2 font-medium">Dokter</th>
                            <th class="px-3 py-2 font-medium">Tanggal</th>
                            <th class="px-3 py-2 font-medium">Status</th>
                            <th class="px-3 py-2 font-medium text-right">Aksi</th>
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
                            <tr class="{{ $isTerminal ? 'opacity-60' : '' }}">
                                <td class="px-3 py-2 font-mono text-gray-700">{{ $visit->visit_number }}</td>
                                <td class="px-3 py-2 text-center font-semibold text-gray-900">{{ $visit->queue_number }}</td>
                                <td class="px-3 py-2 font-medium text-gray-900">{{ $visit->patient?->name ?? '—' }}</td>
                                <td class="px-3 py-2 text-gray-600">{{ $visit->doctor?->name ?? '—' }}</td>
                                <td class="px-3 py-2 text-gray-600">{{ $visit->visit_date?->format('d/m/Y') }}</td>
                                <td class="px-3 py-2">
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $statusBadge[$visit->status] ?? 'bg-gray-100 text-gray-600' }}">
                                        {{ $statusLabels[$visit->status] ?? $visit->status }}
                                    </span>
                                </td>
                                <td class="px-3 py-2">
                                    <div class="flex items-center justify-end gap-2">
                                        <a href="{{ route('rme.visits.show', $visit) }}" class="text-indigo-600 hover:text-indigo-500">Detail</a>
                                        @if (!$isTerminal)
                                            @can('update', $visit)
                                                <a href="{{ route('rme.visits.edit', $visit) }}" class="text-amber-600 hover:text-amber-500">Ubah</a>
                                            @endcan
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-3 py-6 text-center text-gray-400">Belum ada kunjungan.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div>{{ $visits->links() }}</div>
        </div>
    </div>
</x-settings-shell>
