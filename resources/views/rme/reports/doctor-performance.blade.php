<x-settings-shell title="Kinerja & Pendapatan Dokter">
    @php
        /**
         * FIX-PRE-68-45 Scope C — Doctor Performance / Income report.
         * Presentation only; all scoping + aggregation is server-side
         * (DoctorPerformanceReportService). Sources RME invoice/payment truth.
         * Never renders KTP/NIK, scanned documents, or raw medical notes.
         */
        $kpis = $report['kpis'] ?? [];
        $viewMode = $report['view_mode'] ?? 'summary';
        $mode = $access['mode'] ?? 'denied';
        $ownDoctor = $access['own_doctor'] ?? null;
    @endphp

    <div class="space-y-6">
        <x-ui.page-header title="Kinerja & Pendapatan Dokter" subtitle="Ringkasan performa & pendapatan berbasis data RME (kunjungan, tagihan, dan pembayaran).">
            <x-slot:breadcrumb>Laporan RME</x-slot:breadcrumb>
        </x-ui.page-header>

        @if ($mode === 'own' && $ownDoctor)
            <x-ui.alert variant="info">
                Anda melihat data pribadi Anda sebagai <span class="font-semibold">{{ $ownDoctor->name }}</span>.
                Laporan ini hanya mencakup pasien, kunjungan, dan tindakan yang Anda tangani sendiri.
            </x-ui.alert>
        @endif

        <x-ui.filter-bar :action="route('rme.reports.doctor-performance')">
            @if ($access['can_pick_branch'] ?? false)
                <x-ui.select name="branch_id" label="Cabang">
                    <option value="">Semua cabang RME</option>
                    @foreach ($branchOptions as $branch)
                        <option value="{{ $branch->id }}" @selected((string) ($filters['branch_id'] ?? '') === (string) $branch->id)>{{ $branch->name }}</option>
                    @endforeach
                </x-ui.select>
            @endif

            @if ($access['can_pick_doctor'] ?? false)
                <x-ui.select name="doctor_id" label="Dokter">
                    <option value="">Semua dokter (ringkasan)</option>
                    @foreach ($doctorOptions as $doctor)
                        <option value="{{ $doctor->id }}" @selected((string) ($filters['doctor_id'] ?? '') === (string) $doctor->id)>{{ $doctor->name }}</option>
                    @endforeach
                </x-ui.select>
            @endif

            <x-ui.input type="date" name="date_from" label="Tanggal Kunjungan Dari" :value="$filters['date_from'] ?? ''" />
            <x-ui.input type="date" name="date_to" label="Tanggal Kunjungan Sampai" :value="$filters['date_to'] ?? ''" />

            <x-ui.select name="treatment_id" label="Tindakan / Perawatan">
                <option value="">Semua tindakan</option>
                @foreach ($treatmentOptions as $treatment)
                    <option value="{{ $treatment->id }}" @selected((string) ($filters['treatment_id'] ?? '') === (string) $treatment->id)>{{ $treatment->name }}</option>
                @endforeach
            </x-ui.select>

            <x-ui.select name="status" label="Status Pembayaran">
                <option value="">Semua status</option>
                @foreach ($invoiceStatusOptions as $value => $label)
                    <option value="{{ $value }}" @selected((string) ($filters['status'] ?? '') === (string) $value)>{{ $label }}</option>
                @endforeach
            </x-ui.select>

            <x-slot:actions>
                <x-ui.button type="submit" variant="primary">Filter</x-ui.button>
                <x-ui.button variant="secondary" :href="route('rme.reports.doctor-performance')">Atur Ulang</x-ui.button>
            </x-slot:actions>
        </x-ui.filter-bar>

        {{-- Headline KPIs (scoped doctor(s)). Gold accent reserved for revenue. --}}
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <x-ui.kpi-card label="Kunjungan Ditangani" :value="format_number_id($kpis['visits'] ?? 0)" />
            <x-ui.kpi-card label="Pasien Ditangani" :value="format_number_id($kpis['patients'] ?? 0)" />
            <x-ui.kpi-card label="Total Ditagih" :value="format_currency_id($kpis['billed'] ?? 0)" />
            <x-ui.kpi-card label="Total Dibayar (Pendapatan)" :value="format_currency_id($kpis['paid'] ?? 0)" :accent="true" />
            <x-ui.kpi-card label="Sisa / Piutang" :value="format_currency_id($kpis['outstanding'] ?? 0)" />
        </div>

        @if ($viewMode === 'detail')
            {{-- Treatment breakdown for the scoped doctor. --}}
            <x-ui.card title="Rincian per Tindakan / Perawatan">
                @if (count($report['treatment_breakdown'] ?? []))
                    <x-ui.table>
                        <thead>
                            <tr class="bg-navy-50 text-left text-ink-soft">
                                <th class="px-3 py-2 text-left">Tindakan / Perawatan</th>
                                <th class="px-3 py-2 text-right">Jumlah Item</th>
                                <th class="px-3 py-2 text-right">Item pada Invoice Lunas</th>
                                <th class="px-3 py-2 text-right">Nilai Ditagih</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-hairline">
                            @foreach ($report['treatment_breakdown'] as $row)
                                <tr>
                                    <td class="px-3 py-2 font-medium text-navy">{{ $row['treatment_name'] }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums text-ink">{{ format_number_id($row['item_count']) }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums text-ink">{{ format_number_id($row['paid_item_count']) }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums text-ink">{{ format_currency_id($row['billed']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </x-ui.table>
                @else
                    <x-ui.empty-state title="Belum ada tindakan pada periode ini." description="Sesuaikan rentang tanggal atau filter untuk melihat rincian tindakan." />
                @endif
            </x-ui.card>
        @else
            {{-- Per-doctor summary table (executive). --}}
            <x-ui.card title="Ringkasan per Dokter">
                @if (count($report['summary_rows'] ?? []))
                    <x-ui.table>
                        <thead>
                            <tr class="bg-navy-50 text-left text-ink-soft">
                                <th class="px-3 py-2 text-left">Dokter</th>
                                <th class="px-3 py-2 text-right">Kunjungan</th>
                                <th class="px-3 py-2 text-right">Pasien</th>
                                <th class="px-3 py-2 text-right">Ditagih</th>
                                <th class="px-3 py-2 text-right">Dibayar</th>
                                <th class="px-3 py-2 text-right">Sisa</th>
                                <th class="px-3 py-2 text-right">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-hairline">
                            @foreach ($report['summary_rows'] as $row)
                                <tr>
                                    <td class="px-3 py-2 font-medium text-navy">{{ $row['doctor_name'] }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums text-ink">{{ format_number_id($row['visits']) }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums text-ink">{{ format_number_id($row['patients']) }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums text-ink">{{ format_currency_id($row['billed']) }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums text-ink">{{ format_currency_id($row['paid']) }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums text-ink">{{ format_currency_id($row['outstanding']) }}</td>
                                    <td class="px-3 py-2 text-right">
                                        <x-ui.button variant="secondary" size="sm" :href="route('rme.reports.doctor-performance', array_merge(request()->query(), ['doctor_id' => $row['doctor_id']]))">Rincian</x-ui.button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </x-ui.table>
                @else
                    <x-ui.empty-state title="Belum ada data dokter pada periode ini." description="Sesuaikan rentang tanggal atau cabang untuk melihat ringkasan dokter." />
                @endif
            </x-ui.card>
        @endif
    </div>
</x-settings-shell>
