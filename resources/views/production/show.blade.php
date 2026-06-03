<x-settings-shell title="Production Detail">
    <div class="space-y-6">
        {{-- Header + summary --}}
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="text-sm text-gray-500">Order Number</p>
                    <p class="text-lg font-semibold text-gray-900">{{ $order->order_number }}</p>
                    <span class="mt-1 inline-flex items-center rounded-full bg-blue-50 px-2 py-0.5 text-xs font-medium text-blue-700">{{ $order->status }}</span>
                </div>
                <a href="{{ route('production.board') }}" class="text-sm text-gray-500 hover:text-gray-700">← Back to board</a>
            </div>
            <dl class="mt-4 grid grid-cols-1 gap-2 text-sm sm:grid-cols-3">
                <div><dt class="text-gray-500">Clinic</dt><dd class="font-medium">{{ $order->clinic?->name }}</dd></div>
                <div><dt class="text-gray-500">Doctor</dt><dd class="font-medium">{{ $order->doctor?->name }}</dd></div>
                <div><dt class="text-gray-500">Patient</dt><dd class="font-medium">{{ $order->patient?->name ?? '—' }}</dd></div>
                <div><dt class="text-gray-500">Priority</dt><dd class="font-medium">{{ $order->priority }}</dd></div>
                <div><dt class="text-gray-500">Due Date</dt><dd class="font-medium">{{ optional($order->due_date)->format('Y-m-d') ?? '—' }}</dd></div>
                <div><dt class="text-gray-500">Active Technician</dt><dd class="font-medium">{{ $activeAssignment?->technician?->name ?? '— unassigned —' }}</dd></div>
            </dl>
        </div>

        {{-- Action panel --}}
        <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4">
            <h3 class="font-semibold text-gray-800">Actions</h3>
            <div class="flex flex-wrap gap-4">
                @can('production.assign', $order)
                    <form method="POST" action="{{ route('production.assign', $order) }}" class="flex flex-wrap items-end gap-2 rounded-md border border-gray-200 p-3">
                        @csrf
                        <div>
                            <label class="block text-xs text-gray-500">Assign Technician</label>
                            <select name="technician_id" class="mt-1 rounded-md border-gray-300 text-sm">
                                @foreach ($technicians as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach
                            </select>
                        </div>
                        <input type="text" name="notes" placeholder="Notes (optional)" class="rounded-md border-gray-300 text-sm" />
                        <button class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500">Assign</button>
                    </form>
                @endcan

                @can('production.reassign', $order)
                    <form method="POST" action="{{ route('production.reassign', $order) }}" class="flex flex-wrap items-end gap-2 rounded-md border border-gray-200 p-3">
                        @csrf
                        <div>
                            <label class="block text-xs text-gray-500">Reassign To</label>
                            <select name="technician_id" class="mt-1 rounded-md border-gray-300 text-sm">
                                @foreach ($technicians as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach
                            </select>
                        </div>
                        <input type="text" name="reason" placeholder="Reason (min 5 chars)" class="rounded-md border-gray-300 text-sm" />
                        <button class="rounded-md bg-amber-600 px-3 py-2 text-sm font-medium text-white hover:bg-amber-500">Reassign</button>
                    </form>
                @endcan

                @can('production.start', $order)
                    <form method="POST" action="{{ route('production.start', $order) }}" class="flex items-end gap-2">
                        @csrf<input type="text" name="notes" placeholder="Notes" class="rounded-md border-gray-300 text-sm" />
                        <button class="rounded-md bg-green-600 px-3 py-2 text-sm font-medium text-white hover:bg-green-500">Start Work</button>
                    </form>
                @endcan

                @can('production.pause', $order)
                    <form method="POST" action="{{ route('production.pause', $order) }}" class="flex flex-wrap items-end gap-2 rounded-md border border-gray-200 p-3">
                        @csrf
                        <select name="hold_reason" class="rounded-md border-gray-300 text-sm">
                            <option value="">Hold reason</option>
                            @foreach ($holdReasons as $hr)<option value="{{ $hr }}">{{ $hr }}</option>@endforeach
                        </select>
                        <input type="text" name="reason" placeholder="Reason (min 5)" class="rounded-md border-gray-300 text-sm" />
                        <button class="rounded-md bg-amber-600 px-3 py-2 text-sm font-medium text-white hover:bg-amber-500">Pause</button>
                    </form>
                @endcan

                @can('production.resume', $order)
                    <form method="POST" action="{{ route('production.resume', $order) }}" class="flex items-end gap-2">
                        @csrf<input type="text" name="notes" placeholder="Notes" class="rounded-md border-gray-300 text-sm" />
                        <button class="rounded-md bg-green-600 px-3 py-2 text-sm font-medium text-white hover:bg-green-500">Resume</button>
                    </form>
                @endcan

                @can('production.complete', $order)
                    <form method="POST" action="{{ route('production.complete', $order) }}" class="flex items-end gap-2">
                        @csrf<input type="text" name="notes" placeholder="Notes" class="rounded-md border-gray-300 text-sm" />
                        <button class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500">Complete Work</button>
                    </form>
                @endcan

                @can('production.sendToQc', $order)
                    <form method="POST" action="{{ route('production.send-to-qc', $order) }}" class="flex items-end gap-2">
                        @csrf<input type="text" name="notes" placeholder="Notes" class="rounded-md border-gray-300 text-sm" />
                        <button class="rounded-md bg-purple-600 px-3 py-2 text-sm font-medium text-white hover:bg-purple-500">Send to QC</button>
                    </form>
                @endcan
            </div>
        </div>

        {{-- Production steps --}}
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <h3 class="font-semibold text-gray-800">Production Steps</h3>
            <table class="mt-3 min-w-full divide-y divide-gray-200 text-sm">
                <thead><tr class="text-left text-gray-500">
                    <th class="px-3 py-2 font-medium">Step</th>
                    <th class="px-3 py-2 font-medium">Status</th>
                    <th class="px-3 py-2 font-medium">Update</th>
                </tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($steps as $step)
                        <tr>
                            <td class="px-3 py-2 font-medium text-gray-900">{{ $step->step_name }}</td>
                            <td class="px-3 py-2 text-gray-600">{{ $step->status }}</td>
                            <td class="px-3 py-2">
                                @can('production.steps.update', $order)
                                    <form method="POST" action="{{ route('production.steps.update', [$order, $step]) }}" class="flex items-center gap-2">
                                        @csrf @method('PATCH')
                                        <select name="status" class="rounded-md border-gray-300 text-xs">
                                            @foreach ($stepStatuses as $s)<option value="{{ $s }}" @selected($step->status === $s)>{{ $s }}</option>@endforeach
                                        </select>
                                        <input type="text" name="notes" placeholder="Notes" class="rounded-md border-gray-300 text-xs" />
                                        <button class="text-indigo-600 hover:text-indigo-500 text-xs">Save</button>
                                    </form>
                                @else
                                    <span class="text-gray-400 text-xs">—</span>
                                @endcan
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- Assignment history + Work log timeline --}}
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <h3 class="font-semibold text-gray-800">Assignment History</h3>
                <ul class="mt-3 space-y-2 text-sm">
                    @forelse ($assignmentHistory as $a)
                        <li class="flex items-center justify-between border-b border-gray-100 pb-2">
                            <span class="font-medium text-gray-900">{{ $a->technician?->name }}</span>
                            <span class="text-gray-500">{{ $a->status }} · {{ optional($a->assigned_at)->format('Y-m-d H:i') }}</span>
                        </li>
                    @empty
                        <li class="text-gray-400">No assignments yet.</li>
                    @endforelse
                </ul>
            </div>

            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <div class="flex items-center justify-between">
                    <h3 class="font-semibold text-gray-800">Work Log Timeline</h3>
                    <a href="{{ route('production.work-logs.index', $order) }}" class="text-xs text-indigo-600 hover:text-indigo-500">View all</a>
                </div>
                <ul class="mt-3 space-y-2 text-sm">
                    @forelse ($workLogs as $log)
                        <li class="border-b border-gray-100 pb-2">
                            <p class="font-medium text-gray-900">{{ $log->event_type }} <span class="text-xs text-gray-400">({{ $log->duration_minutes }} min)</span></p>
                            <p class="text-gray-500">{{ optional($log->created_at)->format('Y-m-d H:i') }} · {{ $log->performedBy?->name }}</p>
                            @if ($log->notes)<p class="text-gray-600">{{ $log->notes }}</p>@endif
                        </li>
                    @empty
                        <li class="text-gray-400">No work logs yet.</li>
                    @endforelse
                </ul>
            </div>
        </div>

        {{-- Status timeline + Audit log --}}
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <h3 class="font-semibold text-gray-800">Status Timeline</h3>
                <ul class="mt-3 space-y-2 text-sm">
                    @forelse ($order->statusLogs->sortByDesc('changed_at') as $log)
                        <li class="border-b border-gray-100 pb-2">
                            <p class="font-medium text-gray-900">{{ $log->old_status ? $log->old_status.' → ' : '' }}{{ $log->new_status }}</p>
                            <p class="text-gray-500">{{ optional($log->changed_at)->format('Y-m-d H:i') }} · {{ $log->changedBy?->name ?? 'System' }}</p>
                        </li>
                    @empty
                        <li class="text-gray-400">No status history.</li>
                    @endforelse
                </ul>
            </div>

            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <h3 class="font-semibold text-gray-800">Audit Log</h3>
                <ul class="mt-3 space-y-2 text-sm">
                    @forelse ($auditLogs as $log)
                        <li class="border-b border-gray-100 pb-2">
                            <p class="font-medium text-gray-900">{{ $log->action }}</p>
                            <p class="text-gray-500">{{ optional($log->performed_at)->format('Y-m-d H:i') }} · {{ $log->performer?->name ?? 'System' }}</p>
                        </li>
                    @empty
                        <li class="text-gray-400">No audit entries.</li>
                    @endforelse
                </ul>
                <div class="mt-3">{{ $auditLogs->links() }}</div>
            </div>
        </div>
    </div>
</x-settings-shell>
