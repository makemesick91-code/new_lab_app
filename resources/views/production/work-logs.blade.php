@php
    $workLogLabels = [
        'WORK_STARTED' => 'Pekerjaan dimulai',
        'WORK_PAUSED' => 'Pekerjaan dijeda',
        'WORK_RESUMED' => 'Pekerjaan dilanjutkan',
        'WORK_COMPLETED' => 'Pekerjaan selesai',
        'STATUS_CHANGED' => 'Status berubah',
    ];
@endphp

<x-settings-shell title="Log Pekerjaan">
    <x-ui.page-header title="Log Pekerjaan" :subtitle="'Nomor Order: '.$order->order_number">
        <x-slot:breadcrumb>Lab / Papan Produksi / {{ $order->order_number }}</x-slot:breadcrumb>
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('production.show', $order)">&larr; Kembali ke detail</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.table>
        <thead class="bg-navy-50 text-ink"><tr>
            <th class="px-3 py-2 text-left font-medium">Aktivitas</th>
            <th class="px-3 py-2 text-left font-medium">Teknisi</th>
            <th class="px-3 py-2 text-right font-medium">Durasi (menit)</th>
            <th class="px-3 py-2 text-left font-medium">Oleh</th>
            <th class="px-3 py-2 text-left font-medium">Waktu</th>
            <th class="px-3 py-2 text-left font-medium">Catatan</th>
        </tr></thead>
        <tbody class="divide-y divide-hairline">
            @forelse ($workLogs as $log)
                <tr class="transition-colors hover:bg-navy-50">
                    <td class="px-3 py-2 font-medium text-navy">{{ $workLogLabels[$log->event_type] ?? $log->event_type }}</td>
                    <td class="px-3 py-2 text-ink-soft">{{ $log->assignment?->technician?->name ?? '—' }}</td>
                    <td class="px-3 py-2 text-right text-ink-soft">{{ $log->duration_minutes }}</td>
                    <td class="px-3 py-2 text-ink-soft">{{ $log->performedBy?->name ?? 'Sistem' }}</td>
                    <td class="px-3 py-2 text-ink-soft">{{ format_datetime_id($log->created_at) }}</td>
                    <td class="px-3 py-2 text-ink-soft">{{ $log->notes ?? '—' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="px-3 py-6">
                        <x-ui.empty-state title="Belum ada log pekerjaan" description="Aktivitas produksi teknisi akan tercatat di sini." />
                    </td>
                </tr>
            @endforelse
        </tbody>
    </x-ui.table>
</x-settings-shell>
