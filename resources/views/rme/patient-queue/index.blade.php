<x-settings-shell title="Antrian Pasien">
    @php
        // Presentation-only labels. Status tone is resolved by x-ui.badge :status
        // (UIX design-system status map). No business logic here.
        $statusLabels = [
            'registered'      => 'Terdaftar',
            'waiting'         => 'Menunggu',
            'in_progress'     => 'Dalam Pemeriksaan',
            'cashier_pending' => 'Menunggu Kasir',
            'completed'       => 'Selesai',
            'cancelled'       => 'Dibatalkan',
        ];
        // Antrian Pasien only lists active (non-terminal) statuses.
        $queueStatuses = array_values(array_filter($statuses, fn ($s) => ! in_array($s, ['cashier_pending', 'completed', 'cancelled'], true)));
        $hasFilter = $filters['search'] || $filters['status'] || $filters['room_status'] || $filters['visit_date'];
    @endphp

    <div class="space-y-6">
        <x-ui.page-header
            title="Antrian Pasien"
            subtitle="Pasien yang sudah didaftarkan dan menunggu ditempatkan ke ruang perawatan.">
            <x-slot:breadcrumb>Rekam Medis Elektronik</x-slot:breadcrumb>
        </x-ui.page-header>

        {{-- Filter bar (search / room / status / date). Same GET params as before. --}}
        <x-ui.filter-bar :action="route('rme.patient-queue.index')" method="GET">
            <div class="w-full md:flex-1 md:min-w-[12rem]">
                <x-ui.input
                    label="Cari pasien"
                    id="queue-search"
                    name="search"
                    :value="$filters['search']"
                    placeholder="Cari nama, RM, atau no. kunjungan" />
            </div>
            <div class="w-full sm:w-auto">
                <x-ui.select label="Ruangan" id="queue-room-status" name="room_status">
                    <option value="">Semua</option>
                    <option value="unassigned" @selected($filters['room_status'] === 'unassigned')>Belum dipilih</option>
                    <option value="assigned" @selected($filters['room_status'] === 'assigned')>Sudah dipilih</option>
                </x-ui.select>
            </div>
            <div class="w-full sm:w-auto">
                <x-ui.select label="Status" id="queue-status" name="status">
                    <option value="">Semua status</option>
                    @foreach ($queueStatuses as $status)
                        <option value="{{ $status }}" @selected($filters['status'] === $status)>{{ $statusLabels[$status] ?? $status }}</option>
                    @endforeach
                </x-ui.select>
            </div>
            <div class="w-full sm:w-auto">
                <x-ui.input label="Tanggal" id="queue-date" name="visit_date" type="date" :value="$filters['visit_date']" />
            </div>
            <x-slot:actions>
                <x-ui.button type="submit" variant="primary">Terapkan</x-ui.button>
                @if ($hasFilter)
                    <x-ui.button variant="secondary" :href="route('rme.patient-queue.index')">Atur Ulang</x-ui.button>
                @endif
            </x-slot:actions>
        </x-ui.filter-bar>

        <x-ui.card padding="">
            <div class="border-b border-hairline px-4 py-3">
                <h3 class="text-base font-semibold text-navy">Daftar Antrian Pasien</h3>
                <p class="text-sm text-ink-soft">{{ $visits->total() }} pasien dalam antrian.</p>
            </div>

            @if ($visits->isEmpty())
                <div class="px-4 py-8">
                    <x-ui.empty-state
                        title="Belum ada pasien dalam antrian."
                        description="Pasien akan muncul di sini setelah didaftarkan, atau sesuaikan filter pencarian." />
                </div>
            @else
                <div class="overflow-x-auto">
                    <x-ui.table>
                        <thead class="bg-navy-50">
                            <tr class="text-left text-ink-soft">
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
                        <tbody class="divide-y divide-hairline">
                            @foreach ($visits as $visit)
                                @php
                                    $branchRooms = ($roomsByBranch ?? collect())->get($visit->branch_id) ?? collect();
                                @endphp
                                <tr class="align-top transition-colors hover:bg-navy-50">
                                    <td class="px-4 py-3 text-center font-semibold text-navy">{{ $visit->queue_number }}</td>
                                    <td class="px-3 py-3 font-mono text-ink">{{ $visit->visit_number }}</td>
                                    <td class="px-3 py-3 text-ink-soft">{{ $visit->visit_date?->format('d/m/Y') }}</td>
                                    <td class="px-3 py-3 font-mono text-xs text-ink-muted">{{ $visit->patient?->medical_record_number ?? '—' }}</td>
                                    <td class="px-3 py-3 font-medium text-navy">{{ $visit->patient?->name ?? '—' }}</td>
                                    <td class="px-3 py-3 text-ink-soft">{{ $visit->doctor?->name ?? '—' }}</td>
                                    <td class="px-3 py-3">
                                        <x-ui.badge :status="$visit->status">
                                            {{ $statusLabels[$visit->status] ?? $visit->status }}
                                        </x-ui.badge>
                                    </td>
                                    <td class="px-3 py-3 text-ink-soft">
                                        @unless ($visit->clinicRoom)
                                            <x-ui.badge status="waiting" class="mb-1">Menunggu Penempatan Ruangan</x-ui.badge>
                                        @endunless
                                        @if ($branchRooms->isEmpty() || ! auth()->user()?->can('update', $visit))
                                            <span class="{{ $visit->clinicRoom ? 'text-ink' : 'text-ink-muted' }}">
                                                {{ $visit->clinicRoom?->name ?? 'Belum dipilih' }}
                                            </span>
                                        @else
                                            <form method="POST" action="{{ route('rme.visits.assign-room', $visit) }}"
                                                  class="flex flex-wrap items-center gap-1.5">
                                                @csrf
                                                @method('PATCH')
                                                <select name="clinic_room_id"
                                                        class="min-w-[8rem] rounded-lg border-hairline bg-surface text-xs text-navy focus:border-brand-500 focus:ring-brand-500">
                                                    <option value="">- Pilih ruangan -</option>
                                                    @foreach ($branchRooms as $room)
                                                        <option value="{{ $room->id }}" @selected($visit->clinic_room_id == $room->id)>{{ $room->name }}</option>
                                                    @endforeach
                                                </select>
                                                <x-ui.button type="submit" variant="primary" size="sm">Simpan</x-ui.button>
                                            </form>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        <x-ui.button variant="secondary" size="sm" :href="route('rme.visits.show', $visit)">Detail</x-ui.button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </x-ui.table>
                </div>

                @if ($visits->hasPages())
                    <div class="border-t border-hairline px-4 py-3">
                        {{ $visits->links() }}
                    </div>
                @endif
            @endif
        </x-ui.card>
    </div>
</x-settings-shell>
