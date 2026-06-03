@php($order = $order ?? null)
@php($initialItems = old('items', $order
    ? $order->items->map(fn ($i) => [
        'id' => $i->id,
        'lab_service_id' => $i->lab_service_id,
        'tooth_number' => $i->tooth_number,
        'shade_color_text' => $i->shade_color_text,
        'material_text' => $i->material_text,
        'quantity' => (float) $i->quantity,
        'unit_price' => (float) $i->unit_price,
        'notes' => $i->notes,
    ])->values()->all()
    : [['lab_service_id' => '', 'tooth_number' => '', 'shade_color_text' => '', 'material_text' => '', 'quantity' => 1, 'unit_price' => 0, 'notes' => '']]))

<div class="space-y-6">
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <div>
            <label class="block text-sm font-medium text-gray-700">Clinic</label>
            <select name="clinic_id" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                <option value="">— Select clinic —</option>
                @foreach ($clinics as $clinic)
                    <option value="{{ $clinic->id }}" @selected((int) old('clinic_id', $order?->clinic_id) === $clinic->id)>{{ $clinic->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700">Doctor</label>
            <select name="doctor_id" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                <option value="">— Select doctor —</option>
                @foreach ($doctors as $doctor)
                    <option value="{{ $doctor->id }}" @selected((int) old('doctor_id', $order?->doctor_id) === $doctor->id)>{{ $doctor->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700">Patient</label>
            <select name="patient_id" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                <option value="">— Select patient —</option>
                @foreach ($patients as $patient)
                    <option value="{{ $patient->id }}" @selected((int) old('patient_id', $order?->patient_id) === $patient->id)>{{ $patient->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700">Order Date</label>
            <input type="date" name="order_date" value="{{ old('order_date', optional($order?->order_date)->format('Y-m-d') ?? now()->format('Y-m-d')) }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm" />
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700">Due Date</label>
            <input type="date" name="due_date" value="{{ old('due_date', optional($order?->due_date)->format('Y-m-d')) }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm" />
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700">Priority</label>
            <select name="priority" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                @foreach ($priorities as $priority)
                    <option value="{{ $priority }}" @selected(old('priority', $order?->priority ?? 'NORMAL') === $priority)>{{ $priority }}</option>
                @endforeach
            </select>
        </div>
        <div class="sm:col-span-3">
            <label class="block text-sm font-medium text-gray-700">Notes</label>
            <textarea name="notes" rows="2" class="mt-1 block w-full rounded-md border-gray-300 text-sm">{{ old('notes', $order?->notes) }}</textarea>
        </div>
    </div>

    <div x-data="{ items: @js($initialItems) }">
        <div class="flex items-center justify-between">
            <h3 class="font-semibold text-gray-800">Items</h3>
            <button type="button" @click="items.push({lab_service_id:'',tooth_number:'',shade_color_text:'',material_text:'',quantity:1,unit_price:0,notes:''})"
                    class="inline-flex items-center rounded-md bg-gray-800 px-3 py-1.5 text-sm font-medium text-white hover:bg-gray-700">+ Add Item</button>
        </div>

        <template x-for="(item, index) in items" :key="index">
            <div class="mt-3 grid grid-cols-1 gap-3 rounded-md border border-gray-200 p-3 sm:grid-cols-7">
                <template x-if="item.id"><input type="hidden" :name="`items[${index}][id]`" :value="item.id" /></template>
                <div class="sm:col-span-2">
                    <label class="block text-xs text-gray-500">Lab Service</label>
                    <select :name="`items[${index}][lab_service_id]`" x-model="item.lab_service_id" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                        <option value="">— Select —</option>
                        @foreach ($labServices as $service)
                            <option value="{{ $service->id }}">{{ $service->name }} ({{ $service->code }})</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-gray-500">Tooth</label>
                    <input type="text" :name="`items[${index}][tooth_number]`" x-model="item.tooth_number" class="mt-1 block w-full rounded-md border-gray-300 text-sm" />
                </div>
                <div>
                    <label class="block text-xs text-gray-500">Shade</label>
                    <input type="text" :name="`items[${index}][shade_color_text]`" x-model="item.shade_color_text" class="mt-1 block w-full rounded-md border-gray-300 text-sm" />
                </div>
                <div>
                    <label class="block text-xs text-gray-500">Material</label>
                    <input type="text" :name="`items[${index}][material_text]`" x-model="item.material_text" class="mt-1 block w-full rounded-md border-gray-300 text-sm" />
                </div>
                <div>
                    <label class="block text-xs text-gray-500">Qty</label>
                    <input type="number" step="0.01" min="1" :name="`items[${index}][quantity]`" x-model="item.quantity" class="mt-1 block w-full rounded-md border-gray-300 text-sm" />
                </div>
                <div>
                    <label class="block text-xs text-gray-500">Unit Price</label>
                    <input type="number" step="0.01" min="0" :name="`items[${index}][unit_price]`" x-model="item.unit_price" class="mt-1 block w-full rounded-md border-gray-300 text-sm" />
                </div>
                <div class="sm:col-span-6">
                    <label class="block text-xs text-gray-500">Item Notes</label>
                    <input type="text" :name="`items[${index}][notes]`" x-model="item.notes" class="mt-1 block w-full rounded-md border-gray-300 text-sm" />
                </div>
                <div class="flex items-end">
                    <button type="button" x-show="items.length > 1" @click="items.splice(index, 1)" class="text-sm text-red-600 hover:text-red-500">Remove</button>
                </div>
            </div>
        </template>
    </div>
</div>
