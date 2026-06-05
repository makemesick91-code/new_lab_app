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
    <div class="bg-white shadow-sm sm:rounded-lg p-6">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm text-gray-500">Nomor Order</p>
                <p class="font-semibold text-gray-900">{{ $order->order_number }}</p>
            </div>
            <a href="{{ route('production.show', $order) }}" class="text-sm text-gray-500 hover:text-gray-700">Kembali ke detail</a>
        </div>

        <table class="mt-4 min-w-full divide-y divide-gray-200 text-sm">
            <thead><tr class="text-left text-gray-500">
                <th class="px-3 py-2 font-medium">Aktivitas</th>
                <th class="px-3 py-2 font-medium">Teknisi</th>
                <th class="px-3 py-2 font-medium text-right">Durasi (menit)</th>
                <th class="px-3 py-2 font-medium">Oleh</th>
                <th class="px-3 py-2 font-medium">Waktu</th>
                <th class="px-3 py-2 font-medium">Catatan</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($workLogs as $log)
                    <tr>
                        <td class="px-3 py-2 font-medium text-gray-900">{{ $workLogLabels[$log->event_type] ?? $log->event_type }}</td>
                        <td class="px-3 py-2 text-gray-600">{{ $log->assignment?->technician?->name ?? '—' }}</td>
                        <td class="px-3 py-2 text-right text-gray-600">{{ $log->duration_minutes }}</td>
                        <td class="px-3 py-2 text-gray-600">{{ $log->performedBy?->name ?? 'Sistem' }}</td>
                        <td class="px-3 py-2 text-gray-600">{{ format_datetime_id($log->created_at) }}</td>
                        <td class="px-3 py-2 text-gray-600">{{ $log->notes ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-3 py-6 text-center text-gray-400">Belum ada log pekerjaan.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-settings-shell>
