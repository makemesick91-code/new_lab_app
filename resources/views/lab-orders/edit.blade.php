<x-settings-shell title="Edit Lab Order">
    <div class="bg-white shadow-sm sm:rounded-lg">
        <div class="px-6 pt-6">
            <p class="text-sm text-gray-500">Order Number</p>
            <p class="font-semibold text-gray-900">{{ $order->order_number }}</p>
        </div>
        <form method="POST" action="{{ route('lab-orders.update', $order) }}" class="p-6 space-y-6">
            @csrf @method('PUT')
            @include('lab-orders._form', ['order' => $order])
            <div class="flex items-center gap-3">
                <button type="submit" class="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">Update Lab Order</button>
                <a href="{{ route('lab-orders.show', $order) }}" class="text-sm text-gray-500 hover:text-gray-700">Cancel</a>
            </div>
        </form>
    </div>
</x-settings-shell>
