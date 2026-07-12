@php
    /** LAB-PROD-3 — Capacity configuration management. No PII rendered. */
    $dayLabels = [1 => 'Sen', 2 => 'Sel', 3 => 'Rab', 4 => 'Kam', 5 => 'Jum', 6 => 'Sab', 7 => 'Min'];
@endphp

<x-settings-shell title="Konfigurasi Kapasitas Teknisi">
    <x-ui.page-header
        title="Konfigurasi Perencanaan Kapasitas"
        subtitle="Profil kapasitas teknisi, workload layanan, kapabilitas, dan override ketersediaan. Semua efektif berbasis tanggal.">
        <x-slot:breadcrumb>Laboratorium / Kapasitas Teknisi / Konfigurasi</x-slot:breadcrumb>
        <x-slot:actions>
            <x-ui.button :href="route('lab-capacity-planning.index')" variant="secondary" size="sm">Kembali ke Perencanaan</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if (session('success'))
        <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
    @endif
    @if ($errors->any())
        <x-ui.alert variant="danger" title="Periksa input">
            <ul class="list-disc pl-5 text-sm">
                @foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach
            </ul>
        </x-ui.alert>
    @endif

    {{-- Capacity profiles --}}
    <x-ui.card title="Profil Kapasitas Teknisi" class="mt-4">
        <form method="POST" action="{{ route('lab-capacity-planning.capacity-profiles.store') }}" class="mb-4 grid grid-cols-1 gap-3 md:grid-cols-6">
            @csrf
            <x-ui.select name="technician_id" label="Teknisi" required>
                @foreach ($technicians as $t)<option value="{{ $t['id'] }}">{{ $t['name'] }}</option>@endforeach
            </x-ui.select>
            <x-ui.select name="planning_unit" label="Unit">
                @foreach ($planningUnits as $u)<option value="{{ $u }}">{{ $u }}</option>@endforeach
            </x-ui.select>
            <x-ui.input type="number" step="0.01" min="0" name="daily_capacity" label="Kapasitas/Hari" required />
            <x-ui.input type="date" name="effective_from" label="Berlaku Dari" :value="now()->toDateString()" required />
            <x-ui.input type="date" name="effective_until" label="Berlaku Sampai" />
            <div class="flex items-end">
                <x-ui.button type="submit" variant="primary" size="sm">Tambah</x-ui.button>
            </div>
            <div class="md:col-span-6">
                <span class="text-xs text-ink-muted">Hari kerja:</span>
                @foreach ($dayLabels as $num => $lbl)
                    <label class="mr-2 text-xs"><input type="checkbox" name="working_days[]" value="{{ $num }}" @checked(in_array($num, $defaultWorkingDays))> {{ $lbl }}</label>
                @endforeach
            </div>
        </form>
        <x-ui.table>
            <thead class="bg-navy-50 text-ink"><tr>
                <th class="px-3 py-2 text-left">Teknisi</th><th class="px-3 py-2 text-left">Unit</th>
                <th class="px-3 py-2 text-right">Kapasitas/Hari</th><th class="px-3 py-2 text-left">Efektif</th>
                <th class="px-3 py-2 text-left">Aktif</th><th class="px-3 py-2 text-right">Aksi</th>
            </tr></thead>
            <tbody>
            @forelse ($capacityProfiles as $p)
                <tr class="border-t border-hairline">
                    <td class="px-3 py-2">{{ $p->technician?->name }}</td>
                    <td class="px-3 py-2">{{ $p->planning_unit }}</td>
                    <td class="px-3 py-2 text-right">{{ number_format((float) $p->daily_capacity, 2) }}</td>
                    <td class="px-3 py-2">{{ $p->effective_from?->toDateString() }} — {{ $p->effective_until?->toDateString() ?? '∞' }}</td>
                    <td class="px-3 py-2"><x-ui.badge :tone="$p->is_active ? 'success' : 'neutral'">{{ $p->is_active ? 'Aktif' : 'Nonaktif' }}</x-ui.badge></td>
                    <td class="px-3 py-2 text-right">
                        @if ($p->is_active)
                            <form method="POST" action="{{ route('lab-capacity-planning.capacity-profiles.deactivate', $p) }}" class="inline">
                                @csrf @method('DELETE')
                                <x-ui.button type="submit" variant="ghost" size="sm">Nonaktifkan</x-ui.button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-3 py-5 text-center text-ink-muted">Belum ada profil kapasitas.</td></tr>
            @endforelse
            </tbody>
        </x-ui.table>
    </x-ui.card>

    {{-- Workload profiles --}}
    <x-ui.card title="Profil Workload Layanan" class="mt-6">
        <form method="POST" action="{{ route('lab-capacity-planning.workload-profiles.store') }}" class="mb-4 grid grid-cols-1 gap-3 md:grid-cols-6">
            @csrf
            <x-ui.select name="lab_service_id" label="Layanan" required>
                @foreach ($services as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach
            </x-ui.select>
            <x-ui.select name="planning_unit" label="Unit">
                @foreach ($planningUnits as $u)<option value="{{ $u }}">{{ $u }}</option>@endforeach
            </x-ui.select>
            <x-ui.input type="number" step="0.01" min="0" name="planned_workload" label="Workload/Item" required />
            <x-ui.input type="date" name="effective_from" label="Berlaku Dari" :value="now()->toDateString()" required />
            <x-ui.input type="date" name="effective_until" label="Berlaku Sampai" />
            <div class="flex items-end"><x-ui.button type="submit" variant="primary" size="sm">Tambah</x-ui.button></div>
        </form>
        <x-ui.table>
            <thead class="bg-navy-50 text-ink"><tr>
                <th class="px-3 py-2 text-left">Layanan</th><th class="px-3 py-2 text-left">Unit</th>
                <th class="px-3 py-2 text-right">Workload/Item</th><th class="px-3 py-2 text-left">Efektif</th>
                <th class="px-3 py-2 text-left">Aktif</th><th class="px-3 py-2 text-right">Aksi</th>
            </tr></thead>
            <tbody>
            @forelse ($workloadProfiles as $p)
                <tr class="border-t border-hairline">
                    <td class="px-3 py-2">{{ $p->labService?->name }}</td>
                    <td class="px-3 py-2">{{ $p->planning_unit }}</td>
                    <td class="px-3 py-2 text-right">{{ number_format((float) $p->planned_workload, 2) }}</td>
                    <td class="px-3 py-2">{{ $p->effective_from?->toDateString() }} — {{ $p->effective_until?->toDateString() ?? '∞' }}</td>
                    <td class="px-3 py-2"><x-ui.badge :tone="$p->is_active ? 'success' : 'neutral'">{{ $p->is_active ? 'Aktif' : 'Nonaktif' }}</x-ui.badge></td>
                    <td class="px-3 py-2 text-right">
                        @if ($p->is_active)
                            <form method="POST" action="{{ route('lab-capacity-planning.workload-profiles.deactivate', $p) }}" class="inline">
                                @csrf @method('DELETE')
                                <x-ui.button type="submit" variant="ghost" size="sm">Nonaktifkan</x-ui.button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-3 py-5 text-center text-ink-muted">Belum ada profil workload.</td></tr>
            @endforelse
            </tbody>
        </x-ui.table>
    </x-ui.card>

    {{-- Capability mapping --}}
    <x-ui.card title="Kapabilitas Teknisi ↔ Layanan" class="mt-6">
        <form method="POST" action="{{ route('lab-capacity-planning.capabilities.store') }}" class="mb-4 grid grid-cols-1 gap-3 md:grid-cols-5">
            @csrf
            <x-ui.select name="technician_id" label="Teknisi" required>
                @foreach ($technicians as $t)<option value="{{ $t['id'] }}">{{ $t['name'] }}</option>@endforeach
            </x-ui.select>
            <x-ui.select name="lab_service_id" label="Layanan" required>
                @foreach ($services as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach
            </x-ui.select>
            <x-ui.input type="date" name="effective_from" label="Berlaku Dari" :value="now()->toDateString()" required />
            <x-ui.input type="date" name="effective_until" label="Berlaku Sampai" />
            <div class="flex items-end"><x-ui.button type="submit" variant="primary" size="sm">Tambah</x-ui.button></div>
        </form>
        <x-ui.table>
            <thead class="bg-navy-50 text-ink"><tr>
                <th class="px-3 py-2 text-left">Teknisi</th><th class="px-3 py-2 text-left">Layanan</th>
                <th class="px-3 py-2 text-left">Efektif</th><th class="px-3 py-2 text-right">Aksi</th>
            </tr></thead>
            <tbody>
            @forelse ($capabilities as $c)
                <tr class="border-t border-hairline">
                    <td class="px-3 py-2">{{ $c->technician?->name }}</td>
                    <td class="px-3 py-2">{{ $c->labService?->name }}</td>
                    <td class="px-3 py-2">{{ $c->effective_from?->toDateString() }} — {{ $c->effective_until?->toDateString() ?? '∞' }}</td>
                    <td class="px-3 py-2 text-right">
                        <form method="POST" action="{{ route('lab-capacity-planning.capabilities.remove', $c) }}" class="inline">
                            @csrf @method('DELETE')
                            <x-ui.button type="submit" variant="ghost" size="sm">Hapus</x-ui.button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="px-3 py-5 text-center text-ink-muted">Belum ada kapabilitas.</td></tr>
            @endforelse
            </tbody>
        </x-ui.table>
    </x-ui.card>

    {{-- Availability overrides --}}
    <x-ui.card title="Override Ketersediaan" class="mt-6">
        <form method="POST" action="{{ route('lab-capacity-planning.availability.store') }}" class="mb-4 grid grid-cols-1 gap-3 md:grid-cols-6">
            @csrf
            <x-ui.select name="technician_id" label="Teknisi" required>
                @foreach ($technicians as $t)<option value="{{ $t['id'] }}">{{ $t['name'] }}</option>@endforeach
            </x-ui.select>
            <x-ui.input type="date" name="override_date" label="Tanggal" required />
            <x-ui.input type="number" step="0.01" min="0" name="capacity_override" label="Kapasitas Absolut" />
            <x-ui.input type="number" step="0.01" min="0" name="capacity_reduction" label="Pengurangan" />
            <x-ui.select name="reason_category" label="Kategori">
                @foreach ($reasonCategories as $r)<option value="{{ $r }}">{{ $r }}</option>@endforeach
            </x-ui.select>
            <div class="flex items-end"><x-ui.button type="submit" variant="primary" size="sm">Tambah</x-ui.button></div>
        </form>
        <x-ui.table>
            <thead class="bg-navy-50 text-ink"><tr>
                <th class="px-3 py-2 text-left">Teknisi</th><th class="px-3 py-2 text-left">Tanggal</th>
                <th class="px-3 py-2 text-right">Absolut</th><th class="px-3 py-2 text-right">Pengurangan</th>
                <th class="px-3 py-2 text-left">Kategori</th><th class="px-3 py-2 text-right">Aksi</th>
            </tr></thead>
            <tbody>
            @forelse ($overrides as $o)
                <tr class="border-t border-hairline">
                    <td class="px-3 py-2">{{ $o->technician?->name }}</td>
                    <td class="px-3 py-2">{{ $o->override_date?->toDateString() }}</td>
                    <td class="px-3 py-2 text-right">{{ $o->capacity_override !== null ? number_format((float) $o->capacity_override, 2) : '—' }}</td>
                    <td class="px-3 py-2 text-right">{{ $o->capacity_reduction !== null ? number_format((float) $o->capacity_reduction, 2) : '—' }}</td>
                    <td class="px-3 py-2">{{ $o->reason_category }}</td>
                    <td class="px-3 py-2 text-right">
                        <form method="POST" action="{{ route('lab-capacity-planning.availability.remove', $o) }}" class="inline">
                            @csrf @method('DELETE')
                            <x-ui.button type="submit" variant="ghost" size="sm">Hapus</x-ui.button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-3 py-5 text-center text-ink-muted">Belum ada override.</td></tr>
            @endforelse
            </tbody>
        </x-ui.table>
    </x-ui.card>
</x-settings-shell>
