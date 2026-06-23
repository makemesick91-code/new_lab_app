<x-settings-shell title="Antrian Pasien">
    @php
        $statusLabels = [
            'registered'      => 'Terdaftar',
            'waiting'         => 'Menunggu',
            'in_progress'     => 'Dalam Pemeriksaan',
            'cashier_pending' => 'Menunggu Kasir',
            'completed'       => 'Selesai',
            'cancelled'       => 'Dibatalkan',
        ];
        $statusTone = [
            'registered'      => 'info',
            'waiting'         => 'warning',
            'in_progress'     => 'primary',
            'cashier_pending' => 'warning',
            'completed'       => 'success',
            'cancelled'       => 'danger',
        ];
        // Antrian Pasien only lists active (non-terminal) statuses.
        $queueStatuses = array_values(array_filter($statuses, fn ($s) => ! in_array($s, ['cashier_pending', 'completed', 'cancelled'], true)));
        $hasFilter = $filters['search'] || $filters['status'] || $filters['room_status'] || $filters['visit_date'];
    @endphp

    <div class="space-y-6">
        <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">Rekam Medis Elektronik</p>
            <h2 class="mt-1 text-xl font-semibold text-gray-900">Antrian Pasien</h2>
            <p class="mt-1 text-sm text-gray-500">Pasien yang sudah didaftarkan dan menunggu ditempatkan ke ruang perawatan.</p>
        </div>

        <x-ui.card padding="p-4">
            <form method="GET" action="{{ route('rme.patient-queue.index') }}">
                <div class="flex flex-wrap items-end gap-2">
                    <div class="min-w-[12rem] flex-1">
                        <label for="queue-search" class="text-sm font-medium text-gray-700">Cari pasien</label>
                        <input id="queue-search" type="text" name="search" value="{{ $filters['search'] }}" placeholder="Cari nama, RM, atau no. kunjungan"
                               class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500" />
                    </div>
                    <div>
                        <label for="queue-room-status" class="text-sm font-medium text-gray-700">Ruangan</label>
                        <select id="queue-room-status" name="room_status" class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                            <option value="">Semua</option>
                            <option value="unassigned" @selected($filters['room_status'] === 'unassigned')>Belum dipilih</option>
                            <option value="assigned" @selected($filters['room_status'] === 'assigned')>Sudah dipilih</option>
                        </select>
                    </div>
                    <div>
                        <label for="queue-status" class="text-sm font-medium text-gray-700">Status</label>
                        <select id="queue-status" name="status" class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                            <option value="">Semua status</option>
                            @foreach ($queueStatuses as $status)
                                <option value="{{ $status }}" @selected($filters['status'] === $status)>{{ $statusLabels[$status] ?? $status }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="queue-date" class="text-sm font-medium text-gray-700">Tanggal</label>
                        <input id="queue-date" type="date" name="visit_date" value="{{ $filters['visit_date'] }}"
                               class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500" />
                    </div>
                    <x-ui.button type="submit" variant="neutral">Terapkan</x-ui.button>
                    @if ($hasFilter)
                        <x-ui.button variant="secondary" :href="route('rme.patient-queue.index')">Atur Ulang</x-ui.button>
                    @endif
                </div>
            </form>
        </x-ui.card>

        <x-ui.card padding="">
            <div class="border-b border-gray-200 px-4 py-3">
                <h3 class="text-base font-semibold text-gray-900">Daftar Antrian Pasien</h3>
                <p class="text-sm text-gray-500">{{ $visits->total() }} pasien dalam antrian.</p>
            </div>

            <x-ui.table>
                <thead class="bg-gray-50">
                    <tr class="text-left text-gray-500">
                        <th scope="col" class="px-4 py-3 font-medium">Antrian</th>
                        <th scope="col" class="px-3 py-3 font-medium">No. Kunjungan</th>
                        <th scope="col" class="px-3 py-3 font-medium">Waktu Daftar</th>
                        <th scope="col" class="px-3 py-3 font-medium">RM</th>
                        <th scope="col" class="px-3 py-3 font-medium">Pasien</th>
                        <th scope="col" class="px-3 py-3 font-medium">Dokter</th>
                        <th scope="col" class="px-3 py-3 font-medium">Status</th>
                        <th scope="col" class="px-3 py-3 font-medium">Ruangan</th>
                        <th scope="col" class="px-4 py-3 text-right font-medium">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($visits as $visit)
                        @php
                            $branchRooms = ($roomsByBranch ?? collect())->get($visit->branch_id) ?? collect();
                        @endphp
                        <tr class="hover:bg-gray-50 align-top">
                            <td class="px-4 py-3 text-center font-semibold text-gray-900">{{ $visit->queue_number }}</td>
                            <td class="px-3 py-3 font-mono text-gray-700">{{ $visit->visit_number }}</td>
                            <td class="px-3 py-3 text-gray-600">{{ $visit->visit_date?->format('d/m/Y') }}</td>
                            <td class="px-3 py-3 font-mono text-xs text-gray-500">{{ $visit->patient?->medical_record_number ?? '—' }}</td>
                            <td class="px-3 py-3 font-medium text-gray-900">{{ $visit->patient?->name ?? '—' }}</td>
                            <td class="px-3 py-3 text-gray-600">{{ $visit->doctor?->name ?? '—' }}</td>
                            <td class="px-3 py-3">
                                <x-ui.badge :tone="$statusTone[$visit->status] ?? 'neutral'">
                                    {{ $statusLabels[$visit->status] ?? $visit->status }}
                                </x-ui.badge>
                            </td>
                            <td class="px-3 py-3 text-gray-600">
                                @if ($branchRooms->isEmpty() || ! auth()->user()?->can('update', $visit))
                                    <span class="{{ $visit->clinicRoom ? 'text-gray-700' : 'text-gray-400' }}">
                                        {{ $visit->clinicRoom?->name ?? 'Belum dipilih' }}
                                    </span>
                                @else
                                    <form method="POST" action="{{ route('rme.visits.assign-room', $visit) }}"
                                          class="flex flex-wrap items-center gap-1.5">
                                        @csrf
                                        @method('PATCH')
                                        <select name="clinic_room_id"
                                                class="min-w-[8rem] rounded-lg border-gray-300 text-xs focus:border-teal-500 focus:ring-teal-500">
                                            <option value="">- Pilih ruangan -</option>
                                            @foreach ($branchRooms as $room)
                                                <option value="{{ $room->id }}" @selected($visit->clinic_room_id == $room->id)>{{ $room->name }}</option>
                                            @endforeach
                                        </select>
                                        <button type="submit"
                                                class="rounded-lg bg-teal-600 px-2.5 py-1.5 text-xs font-medium text-white hover:bg-teal-700">
                                            Simpan
                                        </button>
                                    </form>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right">
                                <x-ui.button variant="secondary" :href="route('rme.visits.show', $visit)" class="!px-3 !py-1.5 !text-xs">Detail</x-ui.button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="px-4 py-12 text-center">
                                <p class="text-sm font-medium text-gray-900">Belum ada pasien dalam antrian.</p>
                                <p class="mt-1 text-sm text-gray-500">Pasien akan muncul di sini setelah didaftarkan.</p>
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
