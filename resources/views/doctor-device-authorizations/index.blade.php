{{-- REVISION-DOCTOR-AUTO-DEVICE-APPROVAL-APP-ONLY-LOGIN-1 — Approval Device Dokter.

     The approver's inbox. Deliberately NOT the device registry: this screen
     answers "may this doctor use this device?", nothing else. It shows no KTP,
     no NIK and nothing clinical — an approval decision needs the doctor's name,
     the tablet and the branch, and nothing more. --}}
<x-settings-shell title="Approval Device Dokter">
    @php
        $statusLabels = [
            'pending' => 'Menunggu Persetujuan',
            'active' => 'Disetujui',
            'rejected' => 'Ditolak',
            'revoked' => 'Dicabut',
        ];
        $statusTone = [
            'pending' => 'warning',
            'active' => 'success',
            'rejected' => 'danger',
            'revoked' => 'danger',
        ];
        $hasFilters = $filters['search'] || $filters['branch_id'] || ($filters['status'] ?? 'all') !== 'pending';
    @endphp

    <x-ui.alert variant="info" class="mb-4">
        Persetujuan akses <strong>dokter ke perangkat klinik</strong>. Permintaan dibuat otomatis saat dokter
        pertama kali login dari Aplikasi Klinik pada perangkat yang identitas kuncinya terbukti.
        <strong>Pemberlakuan (enforcement) belum aktif</strong> — dokter tetap dapat login seperti biasa,
        dan permintaan yang masih menunggu tidak memblokir siapa pun.
    </x-ui.alert>

    <x-ui.filter-bar :action="route('doctor-device-authorizations.index')">
        <div class="w-full md:w-64">
            <x-ui.input name="search" :value="$filters['search']" placeholder="Cari dokter / perangkat" aria-label="Cari" />
        </div>
        <div class="w-full md:w-52">
            <x-ui.select name="status" aria-label="Filter status">
                <option value="all" @selected(($filters['status'] ?? 'all') === 'all')>Semua status</option>
                @foreach ($statuses as $status)
                    <option value="{{ $status }}" @selected(($filters['status'] ?? null) === $status)>{{ $statusLabels[$status] ?? $status }}</option>
                @endforeach
            </x-ui.select>
        </div>
        <div class="w-full md:w-56">
            <x-ui.select name="branch_id" aria-label="Filter cabang">
                <option value="">Semua cabang</option>
                @foreach ($branches as $branch)
                    <option value="{{ $branch->id }}" @selected((int) $filters['branch_id'] === (int) $branch->id)>{{ $branch->name }}</option>
                @endforeach
            </x-ui.select>
        </div>
        <x-slot:actions>
            <x-ui.button type="submit" size="sm">Terapkan</x-ui.button>
            @if ($hasFilters)
                <x-ui.button variant="secondary" size="sm" :href="route('doctor-device-authorizations.index')">Atur Ulang</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.filter-bar>

    @if ($authorizations->isEmpty())
        <x-ui.empty-state
            title="Tidak ada permintaan"
            description="Belum ada permintaan akses perangkat dokter pada filter ini." />
    @else
        <x-ui.table>
            <x-slot:head>
                <tr>
                    <th class="px-4 py-3 text-left">Dokter</th>
                    <th class="px-4 py-3 text-left">Perangkat</th>
                    <th class="px-4 py-3 text-left">Cabang</th>
                    <th class="px-4 py-3 text-left">Diminta</th>
                    <th class="px-4 py-3 text-left">Status</th>
                    <th class="px-4 py-3 text-right">Aksi</th>
                </tr>
            </x-slot:head>

            @foreach ($authorizations as $authorization)
                <tr class="border-t border-hairline">
                    <td class="px-4 py-3 font-medium text-ink">{{ $authorization->doctor?->name ?? '—' }}</td>
                    <td class="px-4 py-3 text-ink-soft">{{ $authorization->device?->device_name ?? '—' }}</td>
                    <td class="px-4 py-3 text-ink-soft">{{ $authorization->device?->branch?->name ?? '—' }}</td>
                    <td class="px-4 py-3 text-ink-soft">{{ $authorization->requested_at?->format('d/m/Y H:i') ?? '—' }}</td>
                    <td class="px-4 py-3">
                        <x-ui.badge :tone="$statusTone[$authorization->status] ?? 'neutral'">{{ $statusLabels[$authorization->status] ?? $authorization->status }}</x-ui.badge>
                    </td>
                    <td class="px-4 py-3 text-right">
                        <x-ui.button size="sm" variant="secondary" :href="route('doctor-device-authorizations.show', $authorization)">Tinjau</x-ui.button>
                    </td>
                </tr>
            @endforeach
        </x-ui.table>

        <div class="mt-4">{{ $authorizations->links() }}</div>
    @endif
</x-settings-shell>
