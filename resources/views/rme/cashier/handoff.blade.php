<x-settings-shell title="Sinkronisasi Dokter–Kasir">
    @php
        $cashierPendingStatus = \App\Modules\ClinicVisit\Models\ClinicVisit::STATUS_CASHIER_PENDING;
        $mrFinalStatus = \App\Modules\MedicalRecord\Models\MedicalRecord::STATUS_FINAL;

        $hasFilter = !empty($filters['search']) || !empty($filters['branch_id']) || !empty($filters['group']);

        $rmeStatusLabel = function ($visit) use ($mrFinalStatus) {
            $record = $visit?->medicalRecord;
            if ($record === null) {
                return ['Belum ada', 'neutral'];
            }
            return $record->status === $mrFinalStatus
                ? ['Final', 'success']
                : ['Draft', 'warning'];
        };

        $paymentStatusLabel = [
            'DRAFT' => ['Draft', 'info'],
            'UNPAID' => ['Belum Dibayar', 'warning'],
            'PARTIAL' => ['Partial', 'info'],
            'PAID' => ['Lunas', 'success'],
            'VOID' => ['Dibatalkan', 'danger'],
        ];
    @endphp

    <div class="space-y-6">
        <x-ui.page-header
            title="Sinkronisasi Dokter–Kasir"
            subtitle="Pantau status setiap kunjungan dari input dokter sampai siap dibayar — tanpa perlu konfirmasi manual ke dokter.">
            <x-slot:breadcrumb>Rekam Medis Elektronik</x-slot:breadcrumb>
        </x-ui.page-header>

        {{-- Ringkasan per status (klik untuk filter) --}}
        <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-5">
            @foreach ($groupKeys as $key)
                @php
                    $meta = $groupMeta[$key];
                    $isActive = ($filters['group'] ?? null) === $key;
                @endphp
                <a href="{{ route('rme.cashier.handoff', array_merge(request()->only('search', 'branch_id'), $isActive ? [] : ['group' => $key])) }}"
                   class="rounded-xl border p-3 transition {{ $isActive ? 'border-brand-500 bg-brand-50 ring-1 ring-brand-100' : 'border-hairline bg-white hover:border-hairline' }}">
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-2xl font-semibold text-navy">{{ $counts[$key] ?? 0 }}</span>
                        <x-ui.badge :tone="$meta['tone']">&nbsp;</x-ui.badge>
                    </div>
                    <p class="mt-1 text-xs font-medium text-ink">{{ $meta['label'] }}</p>
                </a>
            @endforeach
        </div>

        <x-ui.card padding="p-4">
            <form method="GET" action="{{ route('rme.cashier.handoff') }}">
                <div class="flex flex-wrap items-end gap-2">
                    <div class="min-w-[12rem] flex-1">
                        <label for="handoff-search" class="text-sm font-medium text-ink">Cari kunjungan</label>
                        <input id="handoff-search" type="text" name="search" value="{{ $filters['search'] ?? '' }}"
                               placeholder="No. kunjungan, nama pasien, atau No. RM"
                               class="mt-1 block w-full rounded-lg border-hairline text-sm focus:border-brand-500 focus:ring-brand-500" />
                    </div>
                    <div class="min-w-[10rem]">
                        <label for="handoff-branch" class="text-sm font-medium text-ink">Cabang</label>
                        <select id="handoff-branch" name="branch_id"
                                class="mt-1 block w-full rounded-lg border-hairline text-sm focus:border-brand-500 focus:ring-brand-500">
                            <option value="">Semua Cabang RME</option>
                            @foreach ($branches as $branch)
                                <option value="{{ $branch->id }}" @selected(($filters['branch_id'] ?? null) === $branch->id)>
                                    {{ $branch->code }} — {{ $branch->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    @if ($filters['group'] ?? null)
                        <input type="hidden" name="group" value="{{ $filters['group'] }}" />
                    @endif
                    <x-ui.button type="submit" variant="neutral">Terapkan</x-ui.button>
                    @if ($hasFilter)
                        <x-ui.button variant="secondary" :href="route('rme.cashier.handoff')">Atur Ulang</x-ui.button>
                    @endif
                </div>
            </form>
        </x-ui.card>

        @forelse ($groupKeys as $key)
            @php $visits = $groups[$key] ?? collect(); @endphp
            @if ($visits->isNotEmpty())
                @php $meta = $groupMeta[$key]; @endphp
                <x-ui.card padding="">
                    <div class="flex items-center justify-between gap-3 border-b border-hairline px-4 py-3">
                        <div>
                            <div class="flex items-center gap-2">
                                <x-ui.badge :tone="$meta['tone']">{{ $meta['label'] }}</x-ui.badge>
                                <span class="text-sm text-ink-soft">{{ $visits->count() }} kunjungan</span>
                            </div>
                            <p class="mt-1 text-xs text-ink-soft">{{ $meta['hint'] }}</p>
                        </div>
                    </div>

                    <x-ui.table>
                        <thead class="bg-navy-50">
                            <tr class="text-left text-ink-soft">
                                <th scope="col" class="px-4 py-3 font-medium">No. RM / Pasien</th>
                                <th scope="col" class="px-3 py-3 font-medium">Cabang</th>
                                <th scope="col" class="px-3 py-3 font-medium">Ruangan</th>
                                <th scope="col" class="px-3 py-3 font-medium">Dokter</th>
                                <th scope="col" class="px-3 py-3 font-medium">Waktu Kunjungan</th>
                                <th scope="col" class="px-3 py-3 font-medium">Status Kunjungan</th>
                                <th scope="col" class="px-3 py-3 font-medium">RME</th>
                                <th scope="col" class="px-3 py-3 font-medium">Pembayaran</th>
                                <th scope="col" class="px-4 py-3 text-right font-medium">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-hairline">
                            @foreach ($visits as $visit)
                                @php
                                    $invoice = $visit->rmeInvoice ?? null;
                                    [$rmeLabel, $rmeTone] = $rmeStatusLabel($visit);
                                    $isCashierPending = $visit->status === $cashierPendingStatus;
                                @endphp
                                <tr class="hover:bg-navy-50">
                                    <td class="px-4 py-3">
                                        <span class="block font-medium text-navy">{{ $visit->patient?->name ?? '—' }}</span>
                                        <span class="block font-mono text-xs text-ink-muted">
                                            {{ $visit->patient?->medical_record_number ?? 'Belum ada RM' }}
                                            · {{ $visit->visit_number }}
                                        </span>
                                    </td>
                                    <td class="px-3 py-3 text-ink-soft">{{ $visit->branch ? $visit->branch->code : '—' }}</td>
                                    <td class="px-3 py-3">
                                        @if ($visit->clinicRoom)
                                            <span class="text-ink">{{ $visit->clinicRoom->name }}</span>
                                        @elseif ($visit->requiresRoomBeforeExam())
                                            <x-ui.badge tone="warning">Menunggu Ruangan</x-ui.badge>
                                            <span class="mt-0.5 block text-xs text-warning-700">Belum siap diperiksa</span>
                                        @else
                                            <span class="text-xs text-ink-muted">—</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-3 text-ink-soft">{{ $visit->doctor?->name ?? '—' }}</td>
                                    <td class="px-3 py-3 text-ink-soft">
                                        {{ $visit->visit_date?->format('d/m/Y') ?? '—' }}
                                        @if ($visit->queue_number)
                                            <span class="block text-xs text-ink-muted">Antrean #{{ $visit->queue_number }}</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-3">
                                        <x-ui.badge tone="neutral">{{ ucfirst(str_replace('_', ' ', $visit->status)) }}</x-ui.badge>
                                    </td>
                                    <td class="px-3 py-3"><x-ui.badge :tone="$rmeTone">{{ $rmeLabel }}</x-ui.badge></td>
                                    <td class="px-3 py-3">
                                        @if ($invoice)
                                            @php [$payLabel, $payTone] = $paymentStatusLabel[$invoice->status] ?? [$invoice->status, 'neutral']; @endphp
                                            <x-ui.badge :tone="$payTone">{{ $payLabel }}</x-ui.badge>
                                        @else
                                            <span class="text-xs text-ink-muted">Belum ditagih</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="flex flex-wrap items-center justify-end gap-2">
                                            @if ($isCashierPending && $invoice)
                                                <x-ui.button variant="secondary" :href="route('rme.cashier.show', [$visit, $invoice])" class="!px-3 !py-1.5 !text-xs">Lihat Tagihan</x-ui.button>
                                            @elseif ($isCashierPending)
                                                <x-ui.button variant="primary" :href="route('rme.cashier.create', $visit)" class="!px-3 !py-1.5 !text-xs">Buat Tagihan</x-ui.button>
                                            @else
                                                <span class="text-xs text-ink-muted">Menunggu dokter</span>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </x-ui.table>
                </x-ui.card>
            @endif
        @empty
        @endforelse

        @if ($total === 0)
            <x-ui.empty-state
                title="Tidak ada kunjungan aktif pada antrean ini."
                description="Semua kunjungan sudah selesai atau belum ada kunjungan baru hari ini." />
        @endif
    </div>
</x-settings-shell>
