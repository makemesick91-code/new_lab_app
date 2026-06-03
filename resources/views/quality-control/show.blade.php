<x-settings-shell title="QC Detail">
    <div class="space-y-6">
        {{-- Summary --}}
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="text-sm text-gray-500">Order Number</p>
                    <p class="text-lg font-semibold text-gray-900">{{ $order->order_number }}</p>
                    <span class="mt-1 inline-flex items-center rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700">{{ $order->status }}</span>
                </div>
                <a href="{{ route('quality-control.queue') }}" class="text-sm text-gray-500 hover:text-gray-700">← Back to queue</a>
            </div>
            <dl class="mt-4 grid grid-cols-1 gap-2 text-sm sm:grid-cols-3">
                <div><dt class="text-gray-500">Clinic</dt><dd class="font-medium">{{ $order->clinic?->name }}</dd></div>
                <div><dt class="text-gray-500">Doctor</dt><dd class="font-medium">{{ $order->doctor?->name }}</dd></div>
                <div><dt class="text-gray-500">Patient</dt><dd class="font-medium">{{ $order->patient?->name ?? '—' }}</dd></div>
                <div><dt class="text-gray-500">Priority</dt><dd class="font-medium">{{ $order->priority }}</dd></div>
                <div><dt class="text-gray-500">Production Technician</dt><dd class="font-medium">{{ $activeAssignment?->technician?->name ?? '—' }}</dd></div>
                <div><dt class="text-gray-500">Active QC Inspector</dt><dd class="font-medium">{{ $activeReview?->inspector?->name ?? '—' }}</dd></div>
            </dl>
        </div>

        {{-- QC actions --}}
        <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-3">
            <h3 class="font-semibold text-gray-800">QC Actions</h3>
            <div class="flex flex-wrap gap-3">
                @can('qc.start', $order)
                    <form method="POST" action="{{ route('quality-control.start', $order) }}" class="flex items-end gap-2">
                        @csrf<input type="text" name="notes" placeholder="Notes" class="rounded-md border-gray-300 text-sm" />
                        <button class="rounded-md bg-gray-800 px-3 py-2 text-sm font-medium text-white hover:bg-gray-700">Start Review</button>
                    </form>
                @endcan
                @can('qc.pass', $order)
                    <form method="POST" action="{{ route('quality-control.pass', $order) }}" class="flex items-end gap-2">
                        @csrf<input type="text" name="notes" placeholder="Notes" class="rounded-md border-gray-300 text-sm" />
                        <button class="rounded-md bg-green-600 px-3 py-2 text-sm font-medium text-white hover:bg-green-500">Pass QC</button>
                    </form>
                @endcan
                @can('qc.reject', $order)
                    <form method="POST" action="{{ route('quality-control.reject', $order) }}" class="flex flex-wrap items-end gap-2 rounded-md border border-gray-200 p-3">
                        @csrf
                        <select name="result" class="rounded-md border-gray-300 text-sm">
                            <option value="REJECTED">REJECTED</option>
                            <option value="REVISION">REVISION</option>
                        </select>
                        <select name="reason" class="rounded-md border-gray-300 text-sm">
                            @foreach ($remakeReasons as $reason)<option value="{{ $reason }}">{{ $reason }}</option>@endforeach
                        </select>
                        <input type="text" name="notes" placeholder="Notes (required)" class="rounded-md border-gray-300 text-sm" />
                        <button class="rounded-md bg-red-600 px-3 py-2 text-sm font-medium text-white hover:bg-red-500">Reject QC</button>
                    </form>
                @endcan
                @can('qc.requestRemake', $order)
                    <form method="POST" action="{{ route('quality-control.remake', $order) }}" class="flex flex-wrap items-end gap-2 rounded-md border border-gray-200 p-3">
                        @csrf
                        <select name="reason" class="rounded-md border-gray-300 text-sm">
                            @foreach ($remakeReasons as $reason)<option value="{{ $reason }}">{{ $reason }}</option>@endforeach
                        </select>
                        <input type="text" name="notes" placeholder="Notes (required)" class="rounded-md border-gray-300 text-sm" />
                        <button class="rounded-md bg-amber-600 px-3 py-2 text-sm font-medium text-white hover:bg-amber-500">Request Remake</button>
                    </form>
                @endcan
            </div>
        </div>

        {{-- Checklist panel --}}
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <h3 class="font-semibold text-gray-800">QC Checklist</h3>
            @if ($activeReview)
                <table class="mt-3 min-w-full divide-y divide-gray-200 text-sm">
                    <thead><tr class="text-left text-gray-500">
                        <th class="px-3 py-2 font-medium">Item</th>
                        <th class="px-3 py-2 font-medium">Result</th>
                        <th class="px-3 py-2 font-medium">Update</th>
                    </tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($checklists as $item)
                            <tr>
                                <td class="px-3 py-2 font-medium text-gray-900">{{ $item->checklist_item }}</td>
                                <td class="px-3 py-2 text-gray-600">{{ $item->result }}</td>
                                <td class="px-3 py-2">
                                    @can('qc.checklists.update', $item)
                                        <form method="POST" action="{{ route('quality-control.checklists.update', $item) }}" class="flex items-center gap-2">
                                            @csrf @method('PATCH')
                                            <select name="result" class="rounded-md border-gray-300 text-xs">
                                                @foreach ($checklistResults as $r)<option value="{{ $r }}" @selected($item->result === $r)>{{ $r === 'N_A' ? 'N/A' : $r }}</option>@endforeach
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
            @else
                <p class="mt-2 text-sm text-gray-400">Start a QC review to load the checklist.</p>
            @endif
        </div>

        {{-- Evidence panel --}}
        <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-3">
            <h3 class="font-semibold text-gray-800">QC Evidence</h3>
            @can('qc.uploadEvidence', $order)
                <form method="POST" action="{{ route('quality-control.evidence.store', $order) }}" enctype="multipart/form-data" class="flex flex-wrap items-end gap-2 rounded-md border border-gray-200 p-3">
                    @csrf
                    <select name="category" class="rounded-md border-gray-300 text-sm">
                        @foreach ($evidenceCategories as $cat)<option value="{{ $cat }}">{{ $cat }}</option>@endforeach
                    </select>
                    <input type="file" name="file" class="text-sm" />
                    <button class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500">Upload</button>
                </form>
            @endcan
            <ul class="space-y-1 text-sm">
                @forelse ($order->attachments as $attachment)
                    <li class="flex items-center justify-between border-b border-gray-100 pb-1">
                        <span class="text-gray-900">{{ $attachment->file_name }} <span class="text-xs text-gray-400">({{ $attachment->category }})</span></span>
                        <a href="{{ asset('storage/'.$attachment->file_path) }}" target="_blank" class="text-indigo-600 hover:text-indigo-500">Download</a>
                    </li>
                @empty
                    <li class="text-gray-400">No evidence uploaded.</li>
                @endforelse
            </ul>
        </div>

        {{-- History + Remake --}}
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <h3 class="font-semibold text-gray-800">QC History</h3>
                <ul class="mt-3 space-y-2 text-sm">
                    @forelse ($history as $review)
                        <li class="border-b border-gray-100 pb-2">
                            <p class="font-medium text-gray-900">{{ $review->result ?? 'IN REVIEW' }} <span class="text-xs text-gray-400">by {{ $review->inspector?->name }}</span></p>
                            <p class="text-gray-500">{{ optional($review->completed_at ?? $review->started_at)->format('Y-m-d H:i') }}</p>
                        </li>
                    @empty
                        <li class="text-gray-400">No QC history.</li>
                    @endforelse
                </ul>
            </div>
            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <h3 class="font-semibold text-gray-800">Remake Requests</h3>
                <ul class="mt-3 space-y-2 text-sm">
                    @forelse ($remakeRequests as $remake)
                        <li class="border-b border-gray-100 pb-2">
                            <p class="font-medium text-gray-900">{{ $remake->reason }} <span class="text-xs text-gray-400">({{ $remake->status }})</span></p>
                            <p class="text-gray-500">{{ optional($remake->requested_at)->format('Y-m-d H:i') }} · {{ $remake->requestedBy?->name }}</p>
                            @if ($remake->notes)<p class="text-gray-600">{{ $remake->notes }}</p>@endif
                        </li>
                    @empty
                        <li class="text-gray-400">No remake requests.</li>
                    @endforelse
                </ul>
            </div>
        </div>

        {{-- Status + Audit --}}
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
