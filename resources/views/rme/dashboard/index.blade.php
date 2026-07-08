<x-settings-shell title="Dashboard RME">
    @php
        $branchQuery = $selectedBranchId ? ['branch_id' => $selectedBranchId] : [];

        $kpiCards = [
            ['label' => 'Kunjungan Hari Ini',            'value' => number_format($metrics['visits_today'], 0, ',', '.')],
            ['label' => 'Kunjungan Bulan Ini',           'value' => number_format($metrics['visits_month'], 0, ',', '.')],
            ['label' => 'Pasien Baru Bulan Ini',         'value' => number_format($metrics['new_patients_month'], 0, ',', '.')],
            ['label' => 'Kunjungan Kontrol / Follow-up', 'value' => number_format($metrics['follow_up_month'], 0, ',', '.')],
            ['label' => 'Rekam Medis Draft',             'value' => number_format($metrics['medical_records_draft'], 0, ',', '.')],
            ['label' => 'Rekam Medis Finalized',         'value' => number_format($metrics['medical_records_finalized_month'], 0, ',', '.')],
            ['label' => 'Menunggu Kasir',                'value' => number_format($metrics['cashier_pending'], 0, ',', '.')],
            ['label' => 'Piutang RME Aktif',             'value' => number_format($metrics['active_receivables'], 0, ',', '.')],
            ['label' => 'Pembayaran Hari Ini',           'value' => 'Rp ' . number_format($metrics['payments_today_total'], 0, ',', '.')],
        ];

        $shortcuts = [
            ['label' => 'Kunjungan RME',       'desc' => 'Antrian dan riwayat kunjungan',  'href' => route('rme.visits.index', $branchQuery)],
            ['label' => 'Rekam Medis',         'desc' => 'Daftar rekam medis pasien',       'href' => route('rme.medical-records.index')],
            ['label' => 'Kasir RME',           'desc' => 'Penagihan dan pembayaran',        'href' => route('rme.cashier.index')],
            ['label' => 'Laporan Pasien',      'desc' => 'Rekap pasien RME',                'href' => route('rme.reports.patients')],
            ['label' => 'Laporan Pembayaran',  'desc' => 'Rekap pembayaran RME',            'href' => route('rme.reports.payments')],
        ];
    @endphp

    <div class="space-y-6">
        <x-ui.page-header
            title="Dashboard RME"
            subtitle="Ringkasan operasional RME seluruh Cabang RME aktif.">
            <x-slot:breadcrumb>Rekam Medis Elektronik</x-slot:breadcrumb>
        </x-ui.page-header>

        <x-ui.filter-bar :action="route('rme.dashboard')" method="GET">
            <div class="w-full sm:w-auto sm:min-w-[14rem]">
                <x-ui.select label="Cabang RME" id="dashboard-branch" name="branch_id">
                    <option value="">Semua Cabang RME</option>
                    @foreach ($rmeBranches as $branch)
                        <option value="{{ $branch->id }}" @selected((int) $selectedBranchId === (int) $branch->id)>{{ $branch->code }} — {{ $branch->name }}</option>
                    @endforeach
                </x-ui.select>
            </div>
            <x-slot:actions>
                <x-ui.button type="submit" variant="primary">Terapkan</x-ui.button>
                @if ($selectedBranchId)
                    <x-ui.button variant="secondary" :href="route('rme.dashboard')">Atur Ulang</x-ui.button>
                @endif
            </x-slot:actions>
        </x-ui.filter-bar>

        <div>
            <h3 class="mb-3 text-sm font-semibold uppercase tracking-wide text-ink-soft">Ringkasan KPI</h3>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-3">
                @foreach ($kpiCards as $card)
                    <x-ui.kpi-card :label="$card['label']" :value="$card['value']" />
                @endforeach
            </div>
        </div>

        <div>
            <h3 class="mb-3 text-sm font-semibold uppercase tracking-wide text-ink-soft">Pintasan</h3>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($shortcuts as $shortcut)
                    <a href="{{ $shortcut['href'] }}"
                       class="ui-card flex flex-col p-4 transition-shadow hover:border-brand-200 hover:shadow-md focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2">
                        <span class="text-sm font-semibold text-navy">{{ $shortcut['label'] }}</span>
                        <span class="mt-1 text-xs text-ink-soft">{{ $shortcut['desc'] }}</span>
                    </a>
                @endforeach
            </div>
        </div>
    </div>
</x-settings-shell>
