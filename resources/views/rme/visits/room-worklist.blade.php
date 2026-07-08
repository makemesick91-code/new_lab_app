<x-settings-shell title="Daftar Pasien Ruang Perawatan">
    @php
        // Presentation-only labels. Status tone is resolved by x-ui.badge :status.
        $statusLabels = [
            'registered'  => 'Terdaftar',
            'waiting'     => 'Menunggu',
            'in_progress' => 'Dalam Pemeriksaan',
            'completed'   => 'Selesai',
            'cancelled'   => 'Dibatalkan',
        ];
        // Worklist only ever lists active (non-terminal) statuses.
        $worklistStatuses = array_values(array_filter($statuses, fn ($s) => ! in_array($s, ['completed', 'cancelled', 'cashier_pending'], true)));
        $hasFilter = $filters['search'] || $filters['status'] || $filters['clinic_room_id'];
    @endphp

    <div class="space-y-6">
        <x-ui.page-header
            title="Daftar Pasien Ruang Perawatan"
            subtitle="Pasien yang sudah ditempatkan ke ruang perawatan dan siap dikerjakan.">
            <x-slot:breadcrumb>Rekam Medis Elektronik</x-slot:breadcrumb>
        </x-ui.page-header>

        <x-ui.filter-bar :action="route('rme.treatment-room-worklist.index')" method="GET">
            <div class="w-full md:flex-1 md:min-w-[12rem]">
                <x-ui.input
                    label="Cari pasien"
                    id="worklist-search"
                    name="search"
                    :value="$filters['search']"
                    placeholder="Cari nama, RM, atau no. kunjungan" />
            </div>
            <div class="w-full sm:w-auto">
                <x-ui.select label="Ruangan" id="worklist-room" name="clinic_room_id">
                    <option value="">Semua ruangan</option>
                    @foreach ($rooms as $room)
                        <option value="{{ $room->id }}" @selected((int) $filters['clinic_room_id'] === (int) $room->id)>{{ $room->name }}</option>
                    @endforeach
                </x-ui.select>
            </div>
            <div class="w-full sm:w-auto">
                <x-ui.select label="Status" id="worklist-status" name="status">
                    <option value="">Semua status</option>
                    @foreach ($worklistStatuses as $status)
                        <option value="{{ $status }}" @selected($filters['status'] === $status)>{{ $statusLabels[$status] ?? $status }}</option>
                    @endforeach
                </x-ui.select>
            </div>
            <x-slot:actions>
                <x-ui.button type="submit" variant="primary">Terapkan</x-ui.button>
                @if ($hasFilter)
                    <x-ui.button variant="secondary" :href="route('rme.treatment-room-worklist.index')">Atur Ulang</x-ui.button>
                @endif
            </x-slot:actions>
        </x-ui.filter-bar>

        <x-ui.card padding="">
            <div class="border-b border-hairline px-4 py-3">
                <h3 class="text-base font-semibold text-navy">Pasien di Ruang Perawatan</h3>
                <p class="text-sm text-ink-soft">{{ $visits->total() }} pasien ditemukan.</p>
            </div>

            @if ($visits->isEmpty())
                <div class="px-4 py-8">
                    <x-ui.empty-state
                        title="Belum ada pasien yang sudah ditempatkan ke ruang perawatan."
                        description="Pasien akan muncul setelah Admin Klinik menetapkan ruangan dari antrian." />
                </div>
            @else
                <div class="overflow-x-auto">
                    <x-ui.table>
                        <thead class="bg-navy-50">
                            <tr class="text-left text-ink-soft">
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
                        <tbody class="divide-y divide-hairline">
                            @foreach ($visits as $visit)
                                <tr class="transition-colors hover:bg-navy-50">
                                    <td class="px-4 py-3 text-center font-semibold text-navy">{{ $visit->queue_number }}</td>
                                    <td class="px-3 py-3 font-mono text-ink">{{ $visit->visit_number }}</td>
                                    <td class="px-3 py-3 font-mono text-xs text-ink-muted">{{ $visit->patient?->medical_record_number ?? '—' }}</td>
                                    <td class="px-3 py-3 font-medium text-navy">{{ $visit->patient?->name ?? '—' }}</td>
                                    <td class="px-3 py-3 font-medium text-ink">{{ $visit->clinicRoom?->name ?? '—' }}</td>
                                    <td class="px-3 py-3 text-ink-soft">{{ $visit->doctor?->name ?? '—' }}</td>
                                    <td class="px-3 py-3">
                                        <x-ui.badge :status="$visit->status">
                                            {{ $statusLabels[$visit->status] ?? $visit->status }}
                                        </x-ui.badge>
                                    </td>
                                    <td class="px-3 py-3 text-ink-soft">{{ $visit->visit_date?->format('d/m/Y') }}</td>
                                    <td class="px-4 py-3 text-right">
                                        {{-- Sprint 58.6.2 — open the visit detail page first so Doctor/Perawat
                                             can reach both Rekam Medis and Odontogram from there. --}}
                                        <x-ui.button variant="primary" size="sm" :href="route('rme.visits.show', $visit)">Buka Detail Pasien</x-ui.button>
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
