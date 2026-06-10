<x-settings-shell title="Ubah Kunjungan">
    @php
        $statusLabels = [
            'registered'  => 'Terdaftar',
            'waiting'     => 'Menunggu',
            'in_progress' => 'Dalam Pemeriksaan',
            'completed'   => 'Selesai',
            'cancelled'   => 'Dibatalkan',
        ];
    @endphp

    <div class="bg-white shadow-sm sm:rounded-lg">
        <form method="POST" action="{{ route('rme.visits.update', $visit) }}" class="p-6 space-y-6">
            @csrf
            @method('PUT')
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label class="block text-sm font-medium text-gray-700">Status</label>
                    <select name="status" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        @foreach ($statuses as $status)
                            <option value="{{ $status }}" @selected(old('status', $visit->status) === $status)>{{ $statusLabels[$status] ?? $status }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Ruangan <span class="text-gray-400">(opsional)</span></label>
                    <select name="clinic_room_id" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">- Tanpa ruangan -</option>
                        @foreach ($clinicRooms as $room)
                            <option value="{{ $room->id }}" @selected(old('clinic_room_id', $visit->clinic_room_id) == $room->id)>{{ $room->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="sm:col-span-2">
                    <label class="block text-sm font-medium text-gray-700">Keluhan Utama <span class="text-gray-400">(opsional)</span></label>
                    <textarea name="chief_complaint" rows="3" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">{{ old('chief_complaint', $visit->chief_complaint) }}</textarea>
                </div>
                {{-- Initial Service --}}
                <div class="sm:col-span-2 border-t pt-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-3">Layanan Awal (Triase)</p>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Tindakan Awal <span class="text-gray-400">(opsional)</span></label>
                            <select name="initial_treatment_id" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="">- Pilih tindakan awal -</option>
                                @foreach ($treatments as $treatment)
                                    <option value="{{ $treatment->id }}" @selected(old('initial_treatment_id', $visit->initial_treatment_id) == $treatment->id)>
                                        {{ $treatment->name }}
                                    </option>
                                @endforeach
                            </select>
                            @error('initial_treatment_id')
                                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Catatan Layanan Awal <span class="text-gray-400">(opsional)</span></label>
                            <textarea name="initial_service_note" rows="2" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">{{ old('initial_service_note', $visit->initial_service_note) }}</textarea>
                        </div>
                    </div>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <button type="submit" class="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">Simpan Perubahan</button>
                <a href="{{ route('rme.visits.show', $visit) }}" class="text-sm text-gray-500 hover:text-gray-700">Batal</a>
            </div>
        </form>
    </div>
</x-settings-shell>
