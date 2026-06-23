<x-settings-shell title="Daftar Pasien Ruang Perawatan">
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
        // Worklist only ever lists active (non-terminal) statuses.
        $worklistStatuses = array_values(array_filter($statuses, fn ($s) => ! in_array($s, ['completed', 'cancelled', 'cashier_pending'], true)));
        $hasFilter = $filters['search'] || $filters['status'] || $filters['clinic_room_id'];
    @endphp

    <div class="space-y-6">
        <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">Rekam Medis Elektronik</p>
            <h2 class="mt-1 text-xl font-semibold text-gray-900">Daftar Pasien Ruang Perawatan</h2>
            <p class="mt-1 text-sm text-gray-500">Pasien yang sudah ditempatkan ke ruang perawatan dan siap dikerjakan.</p>
        </div>

        <x-ui.card padding="p-4">
            <form method="GET" action="{{ route('rme.treatment-room-worklist.index') }}">
                <div class="flex flex-wrap items-end gap-2">
                    <div class="min-w-[12rem] flex-1">
                        <label for="worklist-search" class="text-sm font-medium text-gray-700">Cari pasien</label>
                        <input id="worklist-search" type="text" name="search" value="{{ $filters['search'] }}" placeholder="Cari nama, RM, atau no. kunjungan"
                               class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500" />
                    </div>
                    <div>
                        <label for="worklist-room" class="text-sm font-medium text-gray-700">Ruangan</label>
                        <select id="worklist-room" name="clinic_room_id" class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                            <option value="">Semua ruangan</option>
                            @foreach ($rooms as $room)
                                <option value="{{ $room->id }}" @selected((int) $filters['clinic_room_id'] === (int) $room->id)>{{ $room->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="worklist-status" class="text-sm font-medium text-gray-700">Status</label>
                        <select id="worklist-status" name="status" class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                            <option value="">Semua status</option>
                            @foreach ($worklistStatuses as $status)
                                <option value="{{ $status }}" @selected($filters['status'] === $status)>{{ $statusLabels[$status] ?? $status }}</option>
                            @endforeach
                        </select>
                    </div>
                    <x-ui.button type="submit" variant="neutral">Terapkan</x-ui.button>
                    @if ($hasFilter)
                        <x-ui.button variant="secondary" :href="route('rme.treatment-room-worklist.index')">Atur Ulang</x-ui.button>
                    @endif
                </div>
            </form>
        </x-ui.card>

        <x-ui.card padding="">
            <div class="border-b border-gray-200 px-4 py-3">
                <h3 class="text-base font-semibold text-gray-900">Pasien di Ruang Perawatan</h3>
                <p class="text-sm text-gray-500">{{ $visits->total() }} pasien ditemukan.</p>
            </div>

            <x-ui.table>
                <thead class="bg-gray-50">
                    <tr class="text-left text-gray-500">
                        <th scope="col" class="px-4 py-3 font-medium">Antrian</th>
                        <th scope="col" class="px-3 py-3 font-medium">No. Kunjungan</th>
                        <th scope="col" class="px-3 py-3 font-medium">RM</th>
                        <th scope="col" class="px-3 py-3 font-medium">Pasien</th>
                        <th scope="col" class="px-3 py-3 font-medium">Ruangan</th>
                        <th scope="col" class="px-3 py-3 font-medium">Dokter</th>
                        <th scope="col" class="px-3 py-3 font-medium">Status</th>
                        <th scope="col" class="px-3 py-3 font-medium">Tanggal</th>
                        <th scope="col" class="px-4 py-3 text-right font-medium">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($visits as $visit)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 text-center font-semibold text-gray-900">{{ $visit->queue_number }}</td>
                            <td class="px-3 py-3 font-mono text-gray-700">{{ $visit->visit_number }}</td>
                            <td class="px-3 py-3 font-mono text-xs text-gray-500">{{ $visit->patient?->medical_record_number ?? '—' }}</td>
                            <td class="px-3 py-3 font-medium text-gray-900">{{ $visit->patient?->name ?? '—' }}</td>
                            <td class="px-3 py-3 font-medium text-gray-700">{{ $visit->clinicRoom?->name ?? '—' }}</td>
                            <td class="px-3 py-3 text-gray-600">{{ $visit->doctor?->name ?? '—' }}</td>
                            <td class="px-3 py-3">
                                <x-ui.badge :tone="$statusTone[$visit->status] ?? 'neutral'">
                                    {{ $statusLabels[$visit->status] ?? $visit->status }}
                                </x-ui.badge>
                            </td>
                            <td class="px-3 py-3 text-gray-600">{{ $visit->visit_date?->format('d/m/Y') }}</td>
                            <td class="px-4 py-3 text-right">
                                @if ($visit->medicalRecord)
                                    <x-ui.button variant="primary" :href="route('rme.visits.medical-record.show', $visit)" class="!px-3 !py-1.5 !text-xs">Buka Rekam Medis</x-ui.button>
                                @elseif (auth()->user()?->can('create', [\App\Modules\MedicalRecord\Models\MedicalRecord::class, $visit]))
                                    <form method="POST" action="{{ route('rme.visits.medical-record.store', $visit) }}" class="inline">
                                        @csrf
                                        <x-ui.button type="submit" variant="primary" class="!px-3 !py-1.5 !text-xs">Mulai Rekam Medis</x-ui.button>
                                    </form>
                                @else
                                    <span class="text-xs text-gray-400">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="px-4 py-12 text-center">
                                <p class="text-sm font-medium text-gray-900">Belum ada pasien yang sudah ditempatkan ke ruang perawatan.</p>
                                <p class="mt-1 text-sm text-gray-500">Pasien akan muncul setelah Admin Klinik menetapkan ruangan dari antrian.</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </x-ui.table>

            @if ($visits->hasPages())
                <div class="border-t border-gray-200 px-4 py-3">
                    {{ $visits->links() }}
                </div>
            @endif
        </x-ui.card>
    </div>
</x-settings-shell>
