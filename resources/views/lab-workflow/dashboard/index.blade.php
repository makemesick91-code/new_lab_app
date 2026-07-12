@php
    /**
     * LAB-WORKFLOW-V2 Phase 8/9 — read-only operational dashboard + SLA baseline.
     * Presentation only; all data is server-scoped in the two lab services.
     */
    $fmtMinutes = function ($minutes): string {
        if ($minutes === null) {
            return '—';
        }
        if ($minutes >= 60) {
            return number_format($minutes / 60, 1).' jam';
        }

        return number_format($minutes, 1).' mnt';
    };
@endphp

<x-settings-shell title="Dasbor Operasional Lab">
        <x-ui.page-header
            title="Dasbor Operasional Lab"
            subtitle="Ringkasan status alur Lab Workflow V2 + baseline waktu siklus (pilot).">
            <x-slot:breadcrumb>Laboratorium / Dasbor Operasional Lab</x-slot:breadcrumb>
            <x-slot:actions>
                @if (Route::has('lab-v2-orders.index'))
                    <x-ui.button :href="route('lab-v2-orders.index')" variant="secondary" size="sm">Pipeline Lab V2</x-ui.button>
                @endif
            </x-slot:actions>
        </x-ui.page-header>

        @unless ($overview['v2_active'])
            <x-ui.alert variant="info" class="mb-4">
                Lab Workflow V2 belum diaktifkan sebagai engine order baru. Angka di bawah tetap mencerminkan order V2 yang ada.
            </x-ui.alert>
        @endunless

        {{-- Filter bar (GET) --}}
        <x-ui.filter-bar :action="route('lab-workflow-dashboard.index')">
            <x-ui.input type="date" name="from" label="Dari (tanggal order)" :value="$filters['from']" />
            <x-ui.input type="date" name="to" label="Sampai (tanggal order)" :value="$filters['to']" />

            @if ($overview['sees_all'] && ! empty($overview['branch_options']))
                <x-ui.select name="branch_id" label="Cabang">
                    <option value="">Semua Cabang</option>
                    @foreach ($overview['branch_options'] as $branch)
                        <option value="{{ $branch['id'] }}" @selected((int) $filters['branch_id'] === (int) $branch['id'])>
                            {{ $branch['name'] }}
                        </option>
                    @endforeach
                </x-ui.select>
            @endif

            <x-slot:actions>
                <x-ui.button type="submit" size="sm">Terapkan</x-ui.button>
                <x-ui.button href="{{ route('lab-workflow-dashboard.index') }}" variant="secondary" size="sm">Atur Ulang</x-ui.button>
            </x-slot:actions>
        </x-ui.filter-bar>

        <p class="mb-5 text-xs text-ink-soft">
            Cakupan:
            <span class="font-medium text-ink">{{ $overview['sees_all'] ? ($overview['branch_id'] ? 'Cabang terpilih' : 'Semua cabang') : 'Cabang Anda' }}</span>
            · Terakhir diperbarui: {{ $overview['generated_at']->translatedFormat('d M Y H:i') }}
        </p>

        {{-- Headline KPIs --}}
        <div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
            <x-ui.kpi-card label="Order Aktif" :value="number_format($overview['active_total'])" />
            <x-ui.kpi-card label="Terkirim Hari Ini" :value="number_format($overview['delivered_today'])" />
            <x-ui.kpi-card label="Terlambat (>3 hari)" :value="number_format($overview['overdue'])" />
            <x-ui.kpi-card label="Order dengan Rework" :value="number_format($baseline['rework_orders'])" />
        </div>

        {{-- Operational status buckets --}}
        <x-ui.card title="Status Operasional (Lab Workflow V2)" class="mb-6">
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                @foreach ($overview['buckets'] as $bucket)
                    @php $hasLink = Route::has($bucket['route']); @endphp
                    @if ($hasLink)
                        <a href="{{ route($bucket['route']) }}"
                           class="ui-card block p-4 transition-colors hover:border-brand-200 focus:outline-none focus:ring-2 focus:ring-brand-100">
                            <div class="flex items-start justify-between gap-2">
                                <p class="text-xs font-medium text-ink-soft">{{ $bucket['label'] }}</p>
                                <x-ui.badge :tone="$bucket['tone']">{{ number_format($bucket['count']) }}</x-ui.badge>
                            </div>
                            <p class="mt-3 text-2xl font-bold text-navy">{{ number_format($bucket['count']) }}</p>
                        </a>
                    @else
                        <div class="ui-card p-4">
                            <div class="flex items-start justify-between gap-2">
                                <p class="text-xs font-medium text-ink-soft">{{ $bucket['label'] }}</p>
                                <x-ui.badge :tone="$bucket['tone']">{{ number_format($bucket['count']) }}</x-ui.badge>
                            </div>
                            <p class="mt-3 text-2xl font-bold text-navy">{{ number_format($bucket['count']) }}</p>
                        </div>
                    @endif
                @endforeach
            </div>
        </x-ui.card>

        <div class="mb-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
            {{-- Internal production per step --}}
            <x-ui.card title="Produksi Internal per Step">
                <x-ui.table>
                    <thead>
                        <tr class="bg-navy-50 text-left text-xs uppercase tracking-wide text-ink-soft">
                            <th class="px-4 py-2 font-medium">Tahap</th>
                            <th class="px-4 py-2 text-right font-medium">Order</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-hairline">
                        @foreach ($overview['production_breakdown'] as $row)
                            <tr>
                                <td class="px-4 py-2 text-ink">{{ $row['label'] }}</td>
                                <td class="px-4 py-2 text-right font-semibold text-navy">{{ number_format($row['count']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-ui.table>
            </x-ui.card>

            {{-- Recent activity --}}
            <x-ui.card title="Aktivitas Terbaru">
                @if (empty($overview['recent_activity']))
                    <x-ui.empty-state title="Belum ada aktivitas" description="Transisi status Lab Workflow V2 akan muncul di sini." />
                @else
                    <x-ui.table>
                        <thead>
                            <tr class="bg-navy-50 text-left text-xs uppercase tracking-wide text-ink-soft">
                                <th class="px-4 py-2 font-medium">Order</th>
                                <th class="px-4 py-2 font-medium">Pasien</th>
                                <th class="px-4 py-2 font-medium">Status</th>
                                <th class="px-4 py-2 font-medium">Waktu</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-hairline">
                            @foreach ($overview['recent_activity'] as $activity)
                                <tr>
                                    <td class="px-4 py-2 font-medium text-navy">{{ $activity['order_number'] ?? '—' }}</td>
                                    <td class="px-4 py-2 text-ink">{{ $activity['patient_name'] }}</td>
                                    <td class="px-4 py-2"><x-ui.badge :status="$activity['new_status']">{{ $activity['new_status'] }}</x-ui.badge></td>
                                    <td class="px-4 py-2 text-ink-soft">{{ $activity['changed_at']?->translatedFormat('d M Y H:i') ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </x-ui.table>
                @endif
            </x-ui.card>
        </div>

        {{-- Phase 9 — SLA / cycle-time baseline --}}
        <x-ui.card>
            <x-slot:actions>
                <x-ui.badge tone="warning">Baseline pilot</x-ui.badge>
            </x-slot:actions>
            <x-slot:title>Baseline Waktu Siklus (SLA)</x-slot:title>

            <x-ui.alert variant="warning" class="mb-4">
                {{ $baseline['note'] }}. Dihitung dari {{ number_format($baseline['orders_analyzed']) }} order V2
                (rework: {{ number_format($baseline['rework_count']) }} kejadian pada {{ number_format($baseline['rework_orders']) }} order;
                terlambat: {{ number_format($baseline['overdue']) }} order).
            </x-ui.alert>

            @if ($baseline['orders_analyzed'] === 0)
                <x-ui.empty-state title="Belum ada data siklus" description="Baseline SLA akan tersedia setelah ada order Lab Workflow V2 dengan riwayat status." />
            @else
                <x-ui.table>
                    <thead>
                        <tr class="bg-navy-50 text-left text-xs uppercase tracking-wide text-ink-soft">
                            <th class="px-4 py-2 font-medium">Tahap</th>
                            <th class="px-4 py-2 text-right font-medium">Sampel</th>
                            <th class="px-4 py-2 text-right font-medium">Rata-rata</th>
                            <th class="px-4 py-2 text-right font-medium">Median</th>
                            <th class="px-4 py-2 text-right font-medium">Min</th>
                            <th class="px-4 py-2 text-right font-medium">Maks</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-hairline">
                        @foreach ($baseline['stages'] as $stage)
                            <tr>
                                <td class="px-4 py-2 text-ink">{{ $stage['label'] }}</td>
                                <td class="px-4 py-2 text-right text-ink-soft">{{ number_format($stage['count']) }}</td>
                                <td class="px-4 py-2 text-right font-semibold text-navy">{{ $fmtMinutes($stage['avg_minutes']) }}</td>
                                <td class="px-4 py-2 text-right text-ink">{{ $fmtMinutes($stage['median_minutes']) }}</td>
                                <td class="px-4 py-2 text-right text-ink-soft">{{ $fmtMinutes($stage['min_minutes']) }}</td>
                                <td class="px-4 py-2 text-right text-ink-soft">{{ $fmtMinutes($stage['max_minutes']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-ui.table>
            @endif
        </x-ui.card>
</x-settings-shell>
