<x-settings-shell title="UI Component Catalog (UIX-1)">
    <x-ui.page-header
        title="UI Component Catalog"
        subtitle="Katalog internal design system DaengtisiaMS — luxury healthcare. Dev-only, read-only.">
        <x-slot:actions>
            <x-ui.button variant="secondary" href="{{ route('developer-console.index') }}">Developer Console</x-ui.button>
            <x-ui.button variant="primary">Primary CTA</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Buttons --}}
    <x-ui.card title="Buttons" description="Primary CTA selalu biru. Gold hanya aksen premium, bukan CTA.">
        <div class="flex flex-wrap items-center gap-3">
            <x-ui.button variant="primary">Primary</x-ui.button>
            <x-ui.button variant="secondary">Secondary</x-ui.button>
            <x-ui.button variant="neutral">Neutral</x-ui.button>
            <x-ui.button variant="danger">Danger</x-ui.button>
            <x-ui.button variant="success">Success</x-ui.button>
            <x-ui.button variant="warning">Warning</x-ui.button>
            <x-ui.button variant="ghost">Ghost</x-ui.button>
            <x-ui.button variant="gold">Gold accent</x-ui.button>
            <x-ui.button variant="primary" loading="true">Loading</x-ui.button>
            <x-ui.button variant="primary" disabled="true">Disabled</x-ui.button>
            <x-ui.button variant="primary" size="sm">Small</x-ui.button>
            <x-ui.button variant="primary" size="lg">Large</x-ui.button>
        </div>
    </x-ui.card>

    {{-- KPI cards --}}
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-ui.kpi-card label="Pendapatan Bulan Ini" value="Rp 128,4 jt" delta="12% vs bulan lalu" trend="up" accent="true" />
        <x-ui.kpi-card label="Kunjungan" value="642" delta="4%" trend="up" />
        <x-ui.kpi-card label="Piutang Aktif" value="Rp 9,2 jt" delta="3 pasien" trend="flat" />
        <x-ui.kpi-card label="Lab Berjalan" value="18" delta="1% keterlambatan" trend="down" />
    </div>

    {{-- Badges --}}
    <x-ui.card title="Status Badges" description="Mapping status domain → tone.">
        <div class="flex flex-wrap gap-2">
            @foreach (['draft','waiting','in_progress','cashier_pending','paid','cancelled','low_stock','expired_soon','normal','out_of_stock','pending','approved','rejected','completed','delivered','qc'] as $st)
                <x-ui.badge :status="$st" />
            @endforeach
        </div>
    </x-ui.card>

    {{-- Alerts --}}
    <x-ui.card title="Alerts">
        <div class="space-y-3">
            <x-ui.alert variant="info" title="Info">Pemeriksaan dokter telah selesai.</x-ui.alert>
            <x-ui.alert variant="success" title="Berhasil">Pembayaran tersimpan.</x-ui.alert>
            <x-ui.alert variant="warning" title="Perhatian" dismissible="true">Stok mendekati batas minimum.</x-ui.alert>
            <x-ui.alert variant="danger" title="Gagal">Pasien belum ditempatkan ke ruangan perawatan.</x-ui.alert>
        </div>
    </x-ui.card>

    {{-- Form fields --}}
    <x-ui.card title="Form Fields">
        <div class="grid gap-4 md:grid-cols-3">
            <x-ui.input label="Nama Pasien" name="__demo_name" placeholder="cth. Andi P." help="Wajib diisi." required="true" />
            <x-ui.select label="Status" name="__demo_status">
                <option>registered</option>
                <option>waiting</option>
                <option>in_progress</option>
            </x-ui.select>
            <x-ui.input label="Field dengan error" name="__demo_err" error="Contoh pesan error." />
        </div>
        <div class="mt-4">
            <x-ui.textarea label="Catatan" name="__demo_notes" rows="3" placeholder="Catatan bebas..." />
        </div>
    </x-ui.card>

    {{-- Filter bar --}}
    <x-ui.filter-bar>
        <x-ui.input label="Cari" name="__demo_q" placeholder="No. RM / nama" />
        <x-ui.select label="Status" name="__demo_fstatus">
            <option value="">Semua</option>
            <option>cashier_pending</option>
        </x-ui.select>
        <x-slot:actions>
            <x-ui.button variant="primary" size="sm">Filter</x-ui.button>
            <x-ui.button variant="ghost" size="sm">Reset</x-ui.button>
        </x-slot:actions>
    </x-ui.filter-bar>

    {{-- Table --}}
    <x-ui.card title="Table" padding="p-0">
        <x-ui.table>
            <thead class="bg-navy-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-ink-soft">No. RM</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-ink-soft">Pasien</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-ink-soft">Status</th>
                    <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-ink-soft">Total</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-hairline">
                <tr><td class="px-4 py-3">RM-2026-0142</td><td class="px-4 py-3">Andi P.</td><td class="px-4 py-3"><x-ui.badge status="cashier_pending" /></td><td class="px-4 py-3 text-right">Rp 350.000</td></tr>
                <tr><td class="px-4 py-3">RM-2026-0141</td><td class="px-4 py-3">Siti R.</td><td class="px-4 py-3"><x-ui.badge status="paid" /></td><td class="px-4 py-3 text-right">Rp 1.200.000</td></tr>
            </tbody>
        </x-ui.table>
    </x-ui.card>

    {{-- Modal, empty state, skeleton --}}
    <div class="grid gap-4 md:grid-cols-3">
        <x-ui.card title="Modal">
            <x-ui.modal title="Konfirmasi">
                <x-slot:trigger><x-ui.button variant="primary">Buka Modal</x-ui.button></x-slot:trigger>
                Contoh isi modal (Alpine, aksesibel, teleport ke body).
                <x-slot:footer>
                    <x-ui.button variant="secondary" x-on:click="open=false">Batal</x-ui.button>
                    <x-ui.button variant="primary" x-on:click="open=false">Lanjut</x-ui.button>
                </x-slot:footer>
            </x-ui.modal>
        </x-ui.card>

        <x-ui.card title="Empty State">
            <x-ui.empty-state title="Belum ada kunjungan" description="Kunjungan baru akan tampil di sini.">
                <x-slot:action><x-ui.button variant="primary" size="sm">Tambah Kunjungan</x-ui.button></x-slot:action>
            </x-ui.empty-state>
        </x-ui.card>

        {{-- UIX-20 — permission-aware restricted-action notice (non-submitting; presentation only). --}}
        <x-ui.card title="Restricted Notice (permission-aware)">
            <div class="space-y-3">
                <x-ui.restricted-notice />
                <x-ui.restricted-notice description="Anda tidak memiliki akses untuk menambah produk. Hubungi administrator jika memerlukan akses." />
            </div>
        </x-ui.card>

        <x-ui.card title="Skeleton (loading)">
            <div class="space-y-3">
                <x-ui.skeleton :lines="3" />
                <x-ui.skeleton circle="true" height="h-10" class="w-10" />
            </div>
        </x-ui.card>
    </div>
</x-settings-shell>
