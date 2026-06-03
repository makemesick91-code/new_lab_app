<x-settings-shell title="QC Queue">
    <div class="bg-white shadow-sm sm:rounded-lg">
        <div class="p-6 space-y-4">
            <form method="GET" action="{{ route('quality-control.queue') }}" class="flex flex-wrap items-center gap-2">
                <input type="text" name="search" value="{{ $filters['search'] }}" placeholder="Order #, clinic, doctor, patient"
                       class="rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                <select name="priority" class="rounded-md border-gray-300 text-sm">
                    <option value="">All priority</option>
                    @foreach ($priorities as $priority)
                        <option value="{{ $priority }}" @selected($filters['priority'] === $priority)>{{ $priority }}</option>
                    @endforeach
                </select>
                <select name="clinic_id" class="rounded-md border-gray-300 text-sm">
                    <option value="">All clinics</option>
                    @foreach ($clinics as $clinic)
                        <option value="{{ $clinic->id }}" @selected($filters['clinic_id'] === $clinic->id)>{{ $clinic->name }}</option>
                    @endforeach
                </select>
                <select name="technician_id" class="rounded-md border-gray-300 text-sm">
                    <option value="">All technicians</option>
                    @foreach ($technicians as $technician)
                        <option value="{{ $technician->id }}" @selected($filters['technician_id'] === $technician->id)>{{ $technician->name }}</option>
                    @endforeach
                </select>
                <button type="submit" class="inline-flex items-center rounded-md bg-gray-800 px-3 py-2 text-sm font-medium text-white hover:bg-gray-700">Filter</button>
                <a href="{{ route('quality-control.queue') }}" class="text-sm text-gray-500 hover:text-gray-700">Reset</a>
            </form>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead>
                        <tr class="text-left text-gray-500">
                            <th class="px-3 py-2 font-medium">Order #</th>
                            <th class="px-3 py-2 font-medium">Clinic</th>
                            <th class="px-3 py-2 font-medium">Patient</th>
                            <th class="px-3 py-2 font-medium">Technician</th>
                            <th class="px-3 py-2 font-medium">Priority</th>
                            <th class="px-3 py-2 font-medium">Due</th>
                            <th class="px-3 py-2 font-medium">QC Status</th>
                            <th class="px-3 py-2 font-medium text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($orders as $order)
                            <tr>
                                <td class="px-3 py-2 font-medium text-gray-900">{{ $order->order_number }}</td>
                                <td class="px-3 py-2 text-gray-600">{{ $order->clinic?->name }}</td>
                                <td class="px-3 py-2 text-gray-600">{{ $order->patient?->name ?? '—' }}</td>
                                <td class="px-3 py-2 text-gray-600">{{ $order->activeAssignment?->technician?->name ?? '—' }}</td>
                                <td class="px-3 py-2 text-gray-600">{{ $order->priority }}</td>
                                <td class="px-3 py-2 text-gray-600">{{ optional($order->due_date)->format('Y-m-d') ?? '—' }}</td>
                                <td class="px-3 py-2"><span class="inline-flex items-center rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700">{{ $order->status }}</span></td>
                                <td class="px-3 py-2 text-right">
                                    <a href="{{ route('quality-control.show', $order) }}" class="text-indigo-600 hover:text-indigo-500">Review</a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="px-3 py-6 text-center text-gray-400">QC queue is empty.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div>{{ $orders->links() }}</div>
        </div>
    </div>
</x-settings-shell>
