<x-settings-shell title="Work Logs">
    <div class="bg-white shadow-sm sm:rounded-lg p-6">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm text-gray-500">Order Number</p>
                <p class="font-semibold text-gray-900">{{ $order->order_number }}</p>
            </div>
            <a href="{{ route('production.show', $order) }}" class="text-sm text-gray-500 hover:text-gray-700">← Back to detail</a>
        </div>

        <table class="mt-4 min-w-full divide-y divide-gray-200 text-sm">
            <thead><tr class="text-left text-gray-500">
                <th class="px-3 py-2 font-medium">Event</th>
                <th class="px-3 py-2 font-medium">Technician</th>
                <th class="px-3 py-2 font-medium text-right">Duration (min)</th>
                <th class="px-3 py-2 font-medium">By</th>
                <th class="px-3 py-2 font-medium">When</th>
                <th class="px-3 py-2 font-medium">Notes</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($workLogs as $log)
                    <tr>
                        <td class="px-3 py-2 font-medium text-gray-900">{{ $log->event_type }}</td>
                        <td class="px-3 py-2 text-gray-600">{{ $log->assignment?->technician?->name ?? '—' }}</td>
                        <td class="px-3 py-2 text-right text-gray-600">{{ $log->duration_minutes }}</td>
                        <td class="px-3 py-2 text-gray-600">{{ $log->performedBy?->name ?? 'System' }}</td>
                        <td class="px-3 py-2 text-gray-600">{{ optional($log->created_at)->format('Y-m-d H:i') }}</td>
                        <td class="px-3 py-2 text-gray-600">{{ $log->notes ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-3 py-6 text-center text-gray-400">No work logs yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-settings-shell>
