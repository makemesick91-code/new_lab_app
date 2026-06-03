<x-settings-shell title="Lab Order Detail">
    <div class="bg-white shadow-sm sm:rounded-lg" x-data="{ tab: 'overview' }">
        {{-- Header --}}
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 p-6">
            <div>
                <p class="text-sm text-gray-500">Order Number</p>
                <p class="text-lg font-semibold text-gray-900">{{ $order->order_number }}</p>
                <span class="mt-1 inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $order->status === 'CANCELLED' ? 'bg-gray-100 text-gray-600' : 'bg-blue-50 text-blue-700' }}">{{ $order->status }}</span>
            </div>
            <div class="flex items-center gap-2">
                @can('update', $order)
                    <a href="{{ route('lab-orders.edit', $order) }}" class="inline-flex items-center rounded-md bg-gray-800 px-3 py-2 text-sm font-medium text-white hover:bg-gray-700">Edit</a>
                @endcan
                @can('cancel', $order)
                    <form method="POST" action="{{ route('lab-orders.cancel', $order) }}"
                          onsubmit="var r=prompt('Cancellation reason (min 5 chars):'); if(!r||r.length<5){return false;} this.reason.value=r;">
                        @csrf
                        <input type="hidden" name="reason" />
                        <button type="submit" class="inline-flex items-center rounded-md bg-red-600 px-3 py-2 text-sm font-medium text-white hover:bg-red-500">Cancel Order</button>
                    </form>
                @endcan
            </div>
        </div>

        {{-- Tabs --}}
        @php($tabs = ['overview' => 'Overview', 'items' => 'Items', 'attachments' => 'Attachments', 'timeline' => 'Timeline', 'audit' => 'Audit Log'])
        @php($placeholderTabs = ['Assignment', 'Production', 'QC', 'Delivery', 'Invoice'])
        <div class="flex flex-wrap gap-1 border-b border-gray-100 px-4">
            @foreach ($tabs as $key => $label)
                <button type="button" @click="tab = '{{ $key }}'"
                        :class="tab === '{{ $key }}' ? 'border-indigo-600 text-indigo-600' : 'border-transparent text-gray-500 hover:text-gray-700'"
                        class="border-b-2 px-3 py-2 text-sm font-medium">{{ $label }}</button>
            @endforeach
            @foreach ($placeholderTabs as $label)
                <button type="button" disabled class="cursor-not-allowed border-b-2 border-transparent px-3 py-2 text-sm font-medium text-gray-300" title="Coming in a future sprint">{{ $label }}</button>
            @endforeach
        </div>

        <div class="p-6">
            {{-- Overview --}}
            <div x-show="tab === 'overview'" class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                <div><dt class="text-gray-500">Clinic</dt><dd class="font-medium">{{ $order->clinic?->name }}</dd></div>
                <div><dt class="text-gray-500">Doctor</dt><dd class="font-medium">{{ $order->doctor?->name }}</dd></div>
                <div><dt class="text-gray-500">Patient</dt><dd class="font-medium">{{ $order->patient?->name ?? '—' }}</dd></div>
                <div><dt class="text-gray-500">Nomor RM</dt><dd class="font-medium">{{ $order->medical_record_number ?? '-' }}</dd></div>
                <div><dt class="text-gray-500">Priority</dt><dd class="font-medium">{{ $order->priority }}</dd></div>
                <div><dt class="text-gray-500">Order Date</dt><dd class="font-medium">{{ optional($order->order_date)->format('Y-m-d') }}</dd></div>
                <div><dt class="text-gray-500">Due Date</dt><dd class="font-medium">{{ optional($order->due_date)->format('Y-m-d') ?? '—' }}</dd></div>
                <div class="sm:col-span-2"><dt class="text-gray-500">Notes</dt><dd class="font-medium">{{ $order->notes ?? '—' }}</dd></div>
                <div><dt class="text-gray-500">Created By</dt><dd class="font-medium">{{ $order->creator?->name ?? '—' }}</dd></div>
            </div>

            {{-- Items --}}
            <div x-show="tab === 'items'" style="display:none;" class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead><tr class="text-left text-gray-500">
                        <th class="px-3 py-2 font-medium">Service</th>
                        <th class="px-3 py-2 font-medium">Tooth</th>
                        <th class="px-3 py-2 font-medium">Shade</th>
                        <th class="px-3 py-2 font-medium">Material</th>
                        <th class="px-3 py-2 font-medium text-right">Qty</th>
                        <th class="px-3 py-2 font-medium text-right">Unit Price</th>
                        <th class="px-3 py-2 font-medium text-right">Subtotal</th>
                    </tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($order->items as $item)
                            <tr>
                                <td class="px-3 py-2 font-medium text-gray-900">{{ $item->labService?->name }}</td>
                                <td class="px-3 py-2 text-gray-600">{{ $item->tooth_number ?? '—' }}</td>
                                <td class="px-3 py-2 text-gray-600">{{ $item->shade_color_text ?? '—' }}</td>
                                <td class="px-3 py-2 text-gray-600">{{ $item->material_text ?? '—' }}</td>
                                <td class="px-3 py-2 text-right text-gray-600">{{ rtrim(rtrim($item->quantity, '0'), '.') }}</td>
                                <td class="px-3 py-2 text-right text-gray-600">{{ number_format((float) $item->unit_price, 2) }}</td>
                                <td class="px-3 py-2 text-right text-gray-600">{{ number_format((float) $item->subtotal, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Attachments --}}
            <div x-show="tab === 'attachments'" style="display:none;" class="space-y-4">
                @can('uploadAttachment', $order)
                    <form method="POST" action="{{ route('lab-orders.attachments.upload', $order) }}" enctype="multipart/form-data" class="flex flex-wrap items-end gap-3 rounded-md border border-gray-200 p-3">
                        @csrf
                        <div>
                            <label class="block text-xs text-gray-500">Category</label>
                            <select name="category" class="mt-1 rounded-md border-gray-300 text-sm">
                                @foreach (App\Modules\LabOrder\Models\Attachment::CATEGORIES as $cat)
                                    <option value="{{ $cat }}">{{ $cat }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500">File (jpg, jpeg, png, pdf, stl — max 10MB)</label>
                            <input type="file" name="file" class="mt-1 block text-sm" />
                        </div>
                        <button type="submit" class="inline-flex items-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500">Upload</button>
                    </form>
                @endcan

                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead><tr class="text-left text-gray-500">
                        <th class="px-3 py-2 font-medium">File</th>
                        <th class="px-3 py-2 font-medium">Category</th>
                        <th class="px-3 py-2 font-medium">Uploaded By</th>
                        <th class="px-3 py-2 font-medium text-right">Actions</th>
                    </tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($order->attachments as $attachment)
                            <tr>
                                <td class="px-3 py-2 text-gray-900">{{ $attachment->file_name }}</td>
                                <td class="px-3 py-2 text-gray-600">{{ $attachment->category }}</td>
                                <td class="px-3 py-2 text-gray-600">{{ $attachment->uploader?->name ?? '—' }}</td>
                                <td class="px-3 py-2">
                                    <div class="flex items-center justify-end gap-2">
                                        <a href="{{ asset('storage/'.$attachment->file_path) }}" target="_blank" class="text-indigo-600 hover:text-indigo-500">Download</a>
                                        @can('deleteAttachment', $order)
                                            <form method="POST" action="{{ route('lab-orders.attachments.destroy', [$order, $attachment]) }}" onsubmit="return confirm('Delete this attachment?');">
                                                @csrf @method('DELETE')
                                                <button type="submit" class="text-red-600 hover:text-red-500">Delete</button>
                                            </form>
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-3 py-6 text-center text-gray-400">No attachments yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Timeline --}}
            <div x-show="tab === 'timeline'" style="display:none;" class="space-y-3">
                @forelse ($order->statusLogs->sortByDesc('changed_at') as $log)
                    <div class="flex items-start gap-3 text-sm">
                        <div class="mt-1 h-2 w-2 rounded-full bg-indigo-500"></div>
                        <div>
                            <p class="font-medium text-gray-900">{{ $log->old_status ? $log->old_status.' → ' : '' }}{{ $log->new_status }}</p>
                            <p class="text-gray-500">{{ optional($log->changed_at)->format('Y-m-d H:i') }} · {{ $log->changedBy?->name ?? 'System' }}</p>
                            @if ($log->notes)<p class="text-gray-600">{{ $log->notes }}</p>@endif
                        </div>
                    </div>
                @empty
                    <p class="text-gray-400">No status history.</p>
                @endforelse
            </div>

            {{-- Audit Log --}}
            <div x-show="tab === 'audit'" style="display:none;" class="space-y-3">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead><tr class="text-left text-gray-500">
                        <th class="px-3 py-2 font-medium">Action</th>
                        <th class="px-3 py-2 font-medium">By</th>
                        <th class="px-3 py-2 font-medium">When</th>
                    </tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($auditLogs as $log)
                            <tr>
                                <td class="px-3 py-2 font-medium text-gray-900">{{ $log->action }}</td>
                                <td class="px-3 py-2 text-gray-600">{{ $log->performer?->name ?? 'System' }}</td>
                                <td class="px-3 py-2 text-gray-600">{{ optional($log->performed_at)->format('Y-m-d H:i') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="px-3 py-6 text-center text-gray-400">No audit entries.</td></tr>
                        @endforelse
                    </tbody>
                </table>
                <div>{{ $auditLogs->links() }}</div>
            </div>
        </div>
    </div>
</x-settings-shell>
