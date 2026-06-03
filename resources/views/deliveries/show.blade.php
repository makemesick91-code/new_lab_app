<x-settings-shell title="Delivery Detail">
    <div class="space-y-6">
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="text-sm text-gray-500">Delivery Number</p>
                    <p class="text-lg font-semibold text-gray-900">{{ $delivery->delivery_number }}</p>
                    <span class="mt-1 inline-flex rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-700">{{ $delivery->status }}</span>
                </div>
                <a href="{{ route('deliveries.index') }}" class="text-sm text-gray-500 hover:text-gray-700">Back to queue</a>
            </div>
            <dl class="mt-4 grid grid-cols-1 gap-2 text-sm sm:grid-cols-3">
                <div><dt class="text-gray-500">Order</dt><dd class="font-medium">{{ $delivery->labOrder?->order_number }}</dd></div>
                <div><dt class="text-gray-500">Clinic</dt><dd class="font-medium">{{ $delivery->labOrder?->clinic?->name }}</dd></div>
                <div><dt class="text-gray-500">Doctor</dt><dd class="font-medium">{{ $delivery->labOrder?->doctor?->name }}</dd></div>
                <div><dt class="text-gray-500">Patient</dt><dd class="font-medium">{{ $delivery->labOrder?->patient?->name ?? '-' }}</dd></div>
                <div><dt class="text-gray-500">Priority</dt><dd class="font-medium">{{ $delivery->labOrder?->priority }}</dd></div>
                <div><dt class="text-gray-500">Courier</dt><dd class="font-medium">{{ $delivery->courier?->name ?? '-' }}</dd></div>
            </dl>
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-3">
                <h3 class="font-semibold text-gray-800">Courier Assignment</h3>
                @can('assignCourier', $delivery)
                    <form method="POST" action="{{ route($delivery->courier_id ? 'deliveries.reassign-courier' : 'deliveries.assign-courier', $delivery) }}" class="space-y-3">
                        @csrf
                        <select name="courier_id" class="w-full rounded-md border-gray-300 text-sm">
                            @foreach ($couriers as $courier)
                                <option value="{{ $courier->id }}" @selected($delivery->courier_id === $courier->id)>{{ $courier->name }}</option>
                            @endforeach
                        </select>
                        <input type="text" name="notes" placeholder="{{ $delivery->courier_id ? 'Reassignment notes required' : 'Assignment notes' }}" class="w-full rounded-md border-gray-300 text-sm">
                        <button class="rounded-md bg-gray-800 px-3 py-2 text-sm font-medium text-white hover:bg-gray-700">{{ $delivery->courier_id ? 'Reassign Courier' : 'Assign Courier' }}</button>
                    </form>
                @else
                    <p class="text-sm text-gray-400">No assignment action available.</p>
                @endcan
            </div>

            <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-3">
                <h3 class="font-semibold text-gray-800">Delivery Lifecycle</h3>
                <div class="flex flex-wrap gap-2">
                    @can('startDelivery', $delivery)
                        <form method="POST" action="{{ route('deliveries.start', $delivery) }}">
                            @csrf
                            <button class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500">Start Delivery</button>
                        </form>
                    @endcan
                    @can('completeDelivery', $delivery)
                        <form method="POST" action="{{ route('deliveries.complete', $delivery) }}">
                            @csrf
                            <button class="rounded-md bg-green-600 px-3 py-2 text-sm font-medium text-white hover:bg-green-500">Complete Delivery</button>
                        </form>
                    @endcan
                </div>
                <p class="text-sm text-gray-500">Started: {{ optional($delivery->started_at)->format('Y-m-d H:i') ?? '-' }}</p>
                <p class="text-sm text-gray-500">Completed: {{ optional($delivery->completed_at)->format('Y-m-d H:i') ?? '-' }}</p>
            </div>
        </div>

        <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-3">
            <h3 class="font-semibold text-gray-800">POD Panel</h3>
            <dl class="grid grid-cols-1 gap-2 text-sm sm:grid-cols-3">
                <div><dt class="text-gray-500">Receiver</dt><dd class="font-medium">{{ $delivery->receiver_name ?? '-' }}</dd></div>
                <div><dt class="text-gray-500">Received At</dt><dd class="font-medium">{{ optional($delivery->received_at)->format('Y-m-d H:i') ?? '-' }}</dd></div>
                <div><dt class="text-gray-500">Notes</dt><dd class="font-medium">{{ $delivery->delivery_notes ?? '-' }}</dd></div>
            </dl>

            @can('uploadPod', $delivery)
                <form method="POST" action="{{ route('deliveries.pod', $delivery) }}" enctype="multipart/form-data" class="grid grid-cols-1 gap-3 rounded-md border border-gray-200 p-3 md:grid-cols-2">
                    @csrf
                    <input type="text" name="receiver_name" value="{{ old('receiver_name', $delivery->receiver_name) }}" placeholder="Receiver name" class="rounded-md border-gray-300 text-sm">
                    <input type="datetime-local" name="received_at" value="{{ old('received_at', optional($delivery->received_at)->format('Y-m-d\TH:i')) }}" class="rounded-md border-gray-300 text-sm">
                    <label class="text-sm text-gray-600">Signature <input type="file" name="signature" class="mt-1 block text-sm"></label>
                    <label class="text-sm text-gray-600">Receiver Photo <input type="file" name="receiver_photo" class="mt-1 block text-sm"></label>
                    <textarea name="delivery_notes" placeholder="Delivery notes" class="rounded-md border-gray-300 text-sm md:col-span-2">{{ old('delivery_notes', $delivery->delivery_notes) }}</textarea>
                    <button class="w-fit rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500">Upload POD</button>
                </form>
            @endcan

            @can('markDelivered', $delivery)
                <form method="POST" action="{{ route('deliveries.mark-delivered', $delivery) }}" enctype="multipart/form-data" class="grid grid-cols-1 gap-3 rounded-md border border-gray-200 p-3 md:grid-cols-2">
                    @csrf
                    <input type="text" name="receiver_name" value="{{ old('receiver_name', $delivery->receiver_name) }}" placeholder="Receiver name" class="rounded-md border-gray-300 text-sm">
                    <input type="datetime-local" name="received_at" value="{{ old('received_at', optional($delivery->received_at)->format('Y-m-d\TH:i') ?? now()->format('Y-m-d\TH:i')) }}" class="rounded-md border-gray-300 text-sm">
                    <label class="text-sm text-gray-600">Signature <input type="file" name="signature" class="mt-1 block text-sm"></label>
                    <label class="text-sm text-gray-600">Receiver Photo <input type="file" name="receiver_photo" class="mt-1 block text-sm"></label>
                    <textarea name="delivery_notes" placeholder="Delivery notes" class="rounded-md border-gray-300 text-sm md:col-span-2">{{ old('delivery_notes', $delivery->delivery_notes) }}</textarea>
                    <button class="w-fit rounded-md bg-green-600 px-3 py-2 text-sm font-medium text-white hover:bg-green-500">Mark Delivered</button>
                </form>
            @endcan
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <h3 class="font-semibold text-gray-800">Evidence Panel</h3>
                <ul class="mt-3 space-y-2 text-sm">
                    @forelse ($delivery->attachments as $attachment)
                        <li class="flex items-center justify-between border-b border-gray-100 pb-2">
                            <span>{{ $attachment->file_name }} <span class="text-xs text-gray-400">({{ $attachment->category }})</span></span>
                            <a href="{{ asset('storage/'.$attachment->file_path) }}" target="_blank" class="text-indigo-600 hover:text-indigo-500">Open</a>
                        </li>
                    @empty
                        <li class="text-gray-400">No evidence uploaded.</li>
                    @endforelse
                </ul>
            </div>
            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <h3 class="font-semibold text-gray-800">Status History</h3>
                <ul class="mt-3 space-y-2 text-sm">
                    @forelse ($delivery->labOrder?->statusLogs->sortByDesc('changed_at') ?? [] as $log)
                        <li class="border-b border-gray-100 pb-2">
                            <p class="font-medium">{{ $log->old_status }} -> {{ $log->new_status }}</p>
                            <p class="text-gray-500">{{ optional($log->changed_at)->format('Y-m-d H:i') }} by {{ $log->changedBy?->name ?? 'System' }}</p>
                        </li>
                    @empty
                        <li class="text-gray-400">No status history.</li>
                    @endforelse
                </ul>
            </div>
        </div>

        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <h3 class="font-semibold text-gray-800">Audit History</h3>
            <ul class="mt-3 space-y-2 text-sm">
                @forelse ($delivery->auditLogs->sortByDesc('performed_at') as $log)
                    <li class="border-b border-gray-100 pb-2">
                        <p class="font-medium">{{ $log->action }}</p>
                        <p class="text-gray-500">{{ optional($log->performed_at)->format('Y-m-d H:i') }} by {{ $log->performer?->name ?? 'System' }}</p>
                    </li>
                @empty
                    <li class="text-gray-400">No audit entries.</li>
                @endforelse
            </ul>
        </div>
    </div>
</x-settings-shell>
