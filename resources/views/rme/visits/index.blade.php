<x-settings-shell title="Kunjungan Pasien">
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
        $hasActiveFilters = $filters['search'] || $filters['status'] || $filters['visit_date'] || $filters['branch_id'];
    @endphp

    <div class="space-y-6">
        <x-ui.page-header
            title="Kunjungan Pasien"
            subtitle="Antrian dan riwayat kunjungan seluruh Cabang RME aktif.">
            <x-slot:breadcrumb>Rekam Medis Elektronik</x-slot:breadcrumb>
            @can('create', \App\Modules\ClinicVisit\Models\ClinicVisit::class)
                <x-slot:actions>
                    <x-ui.button variant="primary" :href="route('rme.visits.create')">+ Daftar Kunjungan</x-ui.button>
                </x-slot:actions>
            @endcan
        </x-ui.page-header>

        @include('rme.partials.cross-branch-rm-lookup')

        {{-- KPI quick-jump cards (drilldowns preserve the existing status filter params). --}}
        @php
            $branchQuery = $filters['branch_id'] ? ['branch_id' => $filters['branch_id']] : [];
            $rmeWidgetDefs = [
                ['label' => 'Kunjungan Hari Ini', 'value' => $rmeWidgets['visits_today'] ?? 0, 'href' => route('rme.visits.index', $branchQuery)],
                ['label' => 'Menunggu', 'value' => $rmeWidgets['waiting'] ?? 0, 'href' => route('rme.visits.index', $branchQuery + ['status' => 'waiting'])],
                ['label' => 'Sedang Dilayani', 'value' => $rmeWidgets['in_progress'] ?? 0, 'href' => route('rme.visits.index', $branchQuery + ['status' => 'in_progress'])],
                ['label' => 'RM Draft', 'value' => $rmeWidgets['draft_medical_records'] ?? 0, 'href' => route('rme.medical-records.index', ['status' => 'draft'])],
                ['label' => 'RM Final Hari Ini', 'value' => $rmeWidgets['finalized_today'] ?? 0, 'href' => route('rme.medical-records.index', ['status' => 'final'])],
            ];
        @endphp
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
            @foreach ($rmeWidgetDefs as $widget)
                <a href="{{ $widget['href'] }}"
                   class="group rounded-xl focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2">
                    <x-ui.kpi-card
                        :label="$widget['label']"
                        :value="$widget['value']"
                        class="h-full transition-shadow group-hover:shadow-md" />
                </a>
            @endforeach
        </div>

        {{-- Filter bar (search / date / status / branch). Same GET params as before. --}}
        <x-ui.filter-bar :action="route('rme.visits.index')" method="GET">
            <div class="w-full md:flex-1 md:min-w-[14rem]">
                <x-ui.input
                    label="Cari kunjungan"
                    id="visit-search"
                    name="search"
                    :value="$filters['search']"
                    placeholder="Cari nomor atau nama pasien" />
            </div>
            <div class="w-full sm:w-auto">
                <x-ui.input label="Tanggal" id="visit-date" name="visit_date" type="date" :value="$filters['visit_date']" />
            </div>
            <div class="w-full sm:w-auto">
                <x-ui.select label="Status" id="visit-status" name="status">
                    <option value="">Semua status</option>
                    @foreach ($statuses as $status)
                        <option value="{{ $status }}" @selected($filters['status'] === $status)>{{ $statusLabels[$status] ?? $status }}</option>
                    @endforeach
                </x-ui.select>
            </div>
            <div class="w-full sm:w-auto">
                <x-ui.select label="Cabang RME" id="visit-branch" name="branch_id">
                    <option value="">Semua Cabang RME</option>
                    @foreach ($rmeBranches as $branch)
                        <option value="{{ $branch->id }}" @selected((int) $filters['branch_id'] === (int) $branch->id)>{{ $branch->code }} — {{ $branch->name }}</option>
                    @endforeach
                </x-ui.select>
            </div>
            <x-slot:actions>
                <x-ui.button type="submit" variant="primary">Terapkan</x-ui.button>
                @if ($hasActiveFilters)
                    <x-ui.button variant="secondary" :href="route('rme.visits.index')">Atur Ulang</x-ui.button>
                @endif
            </x-slot:actions>
        </x-ui.filter-bar>

        {{-- Quick status tabs — presentation-only, drive the existing `status` param. --}}
        @php
            $tabBaseParams = array_filter([
                'search' => $filters['search'],
                'visit_date' => $filters['visit_date'],
                'branch_id' => $filters['branch_id'],
            ], fn ($v) => $v !== null && $v !== '');
            $statusTabs = ['' => 'Semua'];
            foreach ($statuses as $status) {
                $statusTabs[$status] = $statusLabels[$status] ?? $status;
            }
        @endphp
        <div class="flex flex-wrap gap-2">
            @foreach ($statusTabs as $value => $label)
                @php $tabActive = (string) ($filters['status'] ?? '') === (string) $value; @endphp
                <a href="{{ route('rme.visits.index', $value !== '' ? $tabBaseParams + ['status' => $value] : $tabBaseParams) }}"
                   class="rounded-full px-3 py-1.5 text-xs font-medium ring-1 ring-inset transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 {{ $tabActive ? 'bg-brand-600 text-white ring-brand-600' : 'bg-surface text-ink-soft ring-hairline hover:bg-navy-50' }}">
                    {{ $label }}
                </a>
            @endforeach
        </div>

        <x-ui.card padding="">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-hairline px-4 py-3">
                <div>
                    <h3 class="text-base font-semibold text-navy">Daftar Kunjungan</h3>
                    <p class="text-sm text-ink-soft">{{ $visits->total() }} kunjungan ditemukan.</p>
                </div>
            </div>

            @if ($visits->isEmpty())
                <div class="px-4 py-8">
                    <x-ui.empty-state
                        title="Belum ada kunjungan."
                        description="Daftarkan kunjungan baru untuk memulai antrian hari ini, atau sesuaikan filter pencarian.">
                        @can('create', \App\Modules\ClinicVisit\Models\ClinicVisit::class)
                            <x-slot:action>
                                <x-ui.button variant="primary" :href="route('rme.visits.create')">+ Daftar Kunjungan</x-ui.button>
                            </x-slot:action>
                        @endcan
                    </x-ui.empty-state>
                </div>
            @else
                <div class="overflow-x-auto">
                    <x-ui.table>
                        <thead class="bg-navy-50">
                            <tr class="text-left text-ink-soft">
                                <th scope="col" class="px-4 py-3 font-medium">No. Kunjungan</th>
                                <th scope="col" class="px-3 py-3 font-medium">Antrian</th>
                                <th scope="col" class="px-3 py-3 font-medium">Pasien</th>
                                <th scope="col" class="px-3 py-3 font-medium">Klinik/Cabang</th>
                                <th scope="col" class="px-3 py-3 font-medium">Dokter</th>
                                <th scope="col" class="px-3 py-3 font-medium">Ruangan</th>
                                <th scope="col" class="px-3 py-3 font-medium">Tanggal</th>
                                <th scope="col" class="px-3 py-3 font-medium">Status</th>
                                <th scope="col" class="px-4 py-3 text-right font-medium">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-hairline">
                            @foreach ($visits as $visit)
                                @php
                                    $isTerminal = in_array($visit->status, [
                                        \App\Modules\ClinicVisit\Models\ClinicVisit::STATUS_COMPLETED,
                                        \App\Modules\ClinicVisit\Models\ClinicVisit::STATUS_CANCELLED,
                                    ]);
                                @endphp
                                <tr class="transition-colors hover:bg-navy-50 {{ $isTerminal ? 'opacity-60' : '' }}">
                                    <td class="px-4 py-3 font-mono text-ink">{{ $visit->visit_number }}</td>
                                    <td class="px-3 py-3 text-center font-semibold text-navy">{{ $visit->queue_number }}</td>
                                    <td class="px-3 py-3 font-medium text-navy">
                                        {{ $visit->patient?->name ?? '—' }}
                                        @if ($visit->patient?->medical_record_number)
                                            <span class="block font-mono text-xs font-normal text-ink-muted">{{ $visit->patient->medical_record_number }}</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-3 text-ink-soft">{{ $visit->branch ? $visit->branch->code.' — '.$visit->branch->name : '—' }}</td>
                                    <td class="px-3 py-3 text-ink-soft">{{ $visit->doctor?->name ?? '—' }}</td>
                                    <td class="px-3 py-3 text-ink-soft">
                                        @php
                                            $branchRooms = ($roomsByBranch ?? collect())->get($visit->branch_id) ?? collect();
                                        @endphp
                                        @if ($isTerminal || $branchRooms->isEmpty())
                                            <span class="{{ $visit->clinicRoom ? 'text-ink' : 'text-ink-muted' }}">
                                                {{ $visit->clinicRoom?->name ?? 'Belum dipilih' }}
                                            </span>
                                        @elseif (auth()->user()?->can('update', $visit))
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
                                        @else
                                            <span class="{{ $visit->clinicRoom ? 'text-ink' : 'text-ink-muted' }}">
                                                {{ $visit->clinicRoom?->name ?? 'Belum dipilih' }}
                                            </span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-3 text-ink-soft">{{ $visit->visit_date?->format('d/m/Y') }}</td>
                                    <td class="px-3 py-3">
                                        <x-ui.badge :status="$visit->status">
                                            {{ $statusLabels[$visit->status] ?? $visit->status }}
                                        </x-ui.badge>
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="flex flex-wrap items-center justify-end gap-2">
                                            <x-ui.button variant="secondary" size="sm" :href="route('rme.visits.show', $visit)">Detail</x-ui.button>
                                            @if (!$isTerminal)
                                                @can('update', $visit)
                                                    <x-ui.button variant="primary" size="sm" :href="route('rme.visits.edit', $visit)">Ubah</x-ui.button>
                                                @endcan
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </x-ui.table>
                </div>

                <div class="border-t border-hairline px-4 py-3">{{ $visits->links() }}</div>
            @endif
        </x-ui.card>
    </div>
</x-settings-shell>
