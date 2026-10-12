<x-settings-shell :title="$mode === 'patient' ? 'Riwayat RME Pasien' : 'Detail RME'">
    <x-ui.page-header
        :title="$mode === 'patient' ? 'Riwayat RME Pasien' : 'Detail RME'"
        :subtitle="$mode === 'patient'
            ? 'Masukkan Nomor RM pasien untuk membuka riwayat RME native dan arsip lama lintas cabang.'
            : 'Masukkan nomor kunjungan untuk membuka detail RME kunjungan tersebut.'">
        <x-slot:breadcrumb>Finance &amp; Data Analyst · Rekam Medis Elektronik</x-slot:breadcrumb>
    </x-ui.page-header>

    @include('oversight.partials.readonly-notice')

    <x-ui.filter-bar :action="route('oversight.rme.lookup')">
        <input type="hidden" name="mode" value="{{ $mode }}">
        @if ($mode === 'patient')
            <div class="w-full md:w-80">
                <x-ui.input name="medical_record_number" label="Nomor RM" :value="$rmNumber" placeholder="DG-XXXX-2026-123" />
            </div>
        @else
            <div class="w-full md:w-80">
                <x-ui.input name="visit_number" label="Nomor Kunjungan" :value="$visitNumber" />
            </div>
        @endif
        <x-slot:actions>
            <x-ui.button type="submit" variant="primary" size="sm">Buka</x-ui.button>
            <x-ui.button variant="secondary" size="sm" :href="route('oversight.rme.index')">Cari di Semua RME</x-ui.button>
        </x-slot:actions>
    </x-ui.filter-bar>

    @if ($notFound)
        <x-ui.alert variant="warning">
            {{ $mode === 'patient' ? 'Nomor RM' : 'Nomor kunjungan' }} tidak ditemukan pada cabang RME yang dapat Anda lihat. Pencarian memakai kecocokan persis.
        </x-ui.alert>
    @endif
</x-settings-shell>
