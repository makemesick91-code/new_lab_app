{{-- SATUSEHAT-4B — branch-scoped structured diagnosis rollout configuration.
     Gated by configure_diagnosis_rollout. There is NO global enforcement
     switch: pilot_enforced is per-branch, reasoned, and audited. --}}
<x-settings-shell title="Rollout Diagnosis Terstruktur">
    <x-ui.page-header
        title="Rollout Diagnosis Terstruktur per Cabang"
        subtitle="Mode default cabang tanpa konfigurasi: {{ $defaultMode }}. Enforcement (pilot_enforced) hanya dapat diaktifkan per cabang dengan alasan tertulis — tidak ada enforcement global.">
        <x-slot:breadcrumb>SATUSEHAT</x-slot:breadcrumb>
    </x-ui.page-header>

    @if (session('status'))
        <x-ui.alert variant="success">{{ session('status') }}</x-ui.alert>
    @endif
    @if ($errors->any())
        <x-ui.alert variant="danger" title="Validasi gagal">{{ $errors->first() }}</x-ui.alert>
    @endif

    <x-ui.alert variant="info">
        <strong>disabled</strong>: tanpa indikator; <strong>informational</strong>: kelengkapan ditampilkan tanpa peringatan;
        <strong>warning</strong>: peringatan sebelum finalisasi (tidak memblokir); <strong>pilot_enforced</strong>:
        finalisasi RME diblokir tanpa diagnosis utama aktif (override darurat beralasan tersedia dan teraudit).
    </x-ui.alert>

    <x-ui.card title="Mode per Cabang RME">
        <div class="overflow-x-auto">
            <x-ui.table>
                <thead>
                    <tr>
                        <th class="px-3 py-2 text-left">Cabang</th>
                        <th class="px-3 py-2 text-left">Mode Saat Ini</th>
                        <th class="px-3 py-2 text-left">Alasan Terakhir</th>
                        <th class="px-3 py-2 text-left">Ubah Mode</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($board as $row)
                        <tr class="border-t border-hairline align-top">
                            <td class="px-3 py-2 font-medium">{{ $row['branch']->name }}</td>
                            <td class="px-3 py-2">
                                <x-ui.badge :tone="$row['mode'] === 'pilot_enforced' ? 'danger' : ($row['mode'] === 'warning' ? 'warning' : 'info')">
                                    {{ $row['mode'] }}{{ $row['setting'] === null ? ' (default)' : '' }}
                                </x-ui.badge>
                            </td>
                            <td class="px-3 py-2 text-sm text-ink-muted">{{ $row['setting']?->reason ?? '-' }}</td>
                            <td class="px-3 py-2">
                                <form method="POST" action="{{ route('satusehat.rollout.update', $row['branch']) }}"
                                      class="flex flex-col gap-2 md:flex-row md:items-end">
                                    @csrf
                                    <x-ui.select label="Mode" name="mode">
                                        @foreach (['disabled', 'informational', 'warning', 'pilot_enforced'] as $mode)
                                            <option value="{{ $mode }}" @selected($row['mode'] === $mode)>{{ $mode }}</option>
                                        @endforeach
                                    </x-ui.select>
                                    <div class="flex-1">
                                        <x-ui.input label="Alasan (min. 10 karakter)" name="reason" required />
                                    </div>
                                    <x-ui.button size="sm" type="submit">Simpan</x-ui.button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-3 py-6">
                                <x-ui.empty-state title="Tidak ada cabang RME aktif" />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </x-ui.table>
        </div>
    </x-ui.card>
</x-settings-shell>
