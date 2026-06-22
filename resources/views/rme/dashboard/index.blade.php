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
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">Rekam Medis Elektronik</p>
                <h2 class="mt-1 text-xl font-semibold text-gray-900">Dashboard RME</h2>
                <p class="mt-1 text-sm text-gray-500">Ringkasan operasional RME seluruh Cabang RME aktif.</p>
            </div>
        </div>

        <x-ui.card padding="p-4">
            <form method="GET" action="{{ route('rme.dashboard') }}">
                <div class="flex flex-wrap items-end gap-2">
                    <div class="min-w-[14rem]">
                        <label for="dashboard-branch" class="text-sm font-medium text-gray-700">Cabang RME</label>
                        <select id="dashboard-branch" name="branch_id"
                                class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                            <option value="">Semua Cabang RME</option>
                            @foreach ($rmeBranches as $branch)
                                <option value="{{ $branch->id }}" @selected((int) $selectedBranchId === (int) $branch->id)>{{ $branch->code }} — {{ $branch->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <x-ui.button type="submit" variant="neutral">Terapkan</x-ui.button>
                    @if ($selectedBranchId)
                        <x-ui.button variant="secondary" :href="route('rme.dashboard')">Atur Ulang</x-ui.button>
                    @endif
                </div>
            </form>
        </x-ui.card>

        <div>
            <h3 class="mb-3 text-sm font-semibold uppercase tracking-wide text-gray-500">Ringkasan KPI</h3>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-3">
                @foreach ($kpiCards as $card)
                    <div class="ui-card flex flex-col p-4">
                        <span class="text-2xl font-bold text-gray-900">{{ $card['value'] }}</span>
                        <span class="mt-1 text-xs font-medium text-gray-500">{{ $card['label'] }}</span>
                    </div>
                @endforeach
            </div>
        </div>

        <div>
            <h3 class="mb-3 text-sm font-semibold uppercase tracking-wide text-gray-500">Pintasan</h3>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($shortcuts as $shortcut)
                    <a href="{{ $shortcut['href'] }}"
                       class="ui-card flex flex-col p-4 transition-shadow hover:border-teal-300 hover:shadow-md">
                        <span class="text-sm font-semibold text-gray-900">{{ $shortcut['label'] }}</span>
                        <span class="mt-1 text-xs text-gray-500">{{ $shortcut['desc'] }}</span>
                    </a>
                @endforeach
            </div>
        </div>
    </div>
</x-settings-shell>
