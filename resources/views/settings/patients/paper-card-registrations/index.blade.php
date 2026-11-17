{{-- DMS-RME-COMPREHENSIVE-CLOSURE-1 / memo M4.1 (rule 244 §4.3, PR-3b) — the stored
     paper-card registrations in the reader's branch scope. Read only; the NOTE is
     deliberately not listed here (only on the patient's own page). Served
     `private, no-store`.
     Rule 244 §5.3 (RM PR-4b, re-review L2) — below, the OPEN no-phone exceptions
     in the same scope, also without the note (read on the patient's page). --}}
<x-settings-shell title="Registrasi Nomor RM Kartu Lama">
    <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4">
        <div>
            <h2 class="text-lg font-semibold text-gray-900">Registrasi Nomor RM Kartu Lama</h2>
            <p class="text-sm text-gray-500">Hanya baca. Catatan alasan ditampilkan pada halaman tiap pasien. Data ini tidak dapat diubah atau dihapus.</p>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-3 py-2 text-left font-medium text-gray-600">Waktu (WITA)</th>
                        <th class="px-3 py-2 text-left font-medium text-gray-600">Nomor RM</th>
                        <th class="px-3 py-2 text-left font-medium text-gray-600">Pasien</th>
                        <th class="px-3 py-2 text-left font-medium text-gray-600">Cabang</th>
                        <th class="px-3 py-2 text-left font-medium text-gray-600">Alasan</th>
                        <th class="px-3 py-2 text-left font-medium text-gray-600">Dicatat oleh</th>
                        <th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($registrations as $registration)
                        <tr>
                            <td class="px-3 py-2 text-gray-700">{{ $registration->created_at?->copy()->setTimezone($clinicalTimezone)->format('d/m/Y H:i') }}</td>
                            <td class="px-3 py-2 font-mono text-gray-700">{{ $registration->patient?->medical_record_number }}</td>
                            <td class="px-3 py-2 text-gray-700">{{ $registration->patient?->name }}</td>
                            <td class="px-3 py-2 text-gray-700">{{ $registration->branch?->code }}</td>
                            <td class="px-3 py-2 text-gray-700">{{ $reasons[$registration->reason_code] ?? $registration->reason_code }}</td>
                            <td class="px-3 py-2 text-gray-700">{{ $registration->actor?->name }}</td>
                            <td class="px-3 py-2 text-right">
                                <a href="{{ route('settings.patients.paper-card-registrations.show', $registration->patient_id) }}" class="text-indigo-600 hover:text-indigo-800">Detail</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-3 py-6 text-center text-gray-500">Belum ada registrasi Nomor RM kartu lama.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $registrations->links() }}
    </div>

    <div class="mt-6 bg-white shadow-sm sm:rounded-lg p-6 space-y-4" data-open-no-phone-exceptions>
        <div>
            <h2 class="text-lg font-semibold text-gray-900">Pengecualian tanpa nomor telepon (terbuka)</h2>
            <p class="text-sm text-gray-500">Pasien yang didaftarkan tanpa nomor ponsel/WhatsApp dan belum dilengkapi. Catatan alasan ditampilkan pada halaman tiap pasien.</p>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-3 py-2 text-left font-medium text-gray-600">Dicatat (WITA)</th>
                        <th class="px-3 py-2 text-left font-medium text-gray-600">Nomor RM</th>
                        <th class="px-3 py-2 text-left font-medium text-gray-600">Pasien</th>
                        <th class="px-3 py-2 text-left font-medium text-gray-600">Cabang</th>
                        <th class="px-3 py-2 text-left font-medium text-gray-600">Alasan</th>
                        <th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($noPhoneExceptions as $exceptionPatient)
                        <tr>
                            <td class="px-3 py-2 text-gray-700">{{ $exceptionPatient->no_phone_recorded_at?->copy()->setTimezone($clinicalTimezone)->format('d/m/Y H:i') }}</td>
                            <td class="px-3 py-2 font-mono text-gray-700">{{ $exceptionPatient->medical_record_number }}</td>
                            <td class="px-3 py-2 text-gray-700">{{ $exceptionPatient->name }}</td>
                            <td class="px-3 py-2 text-gray-700">{{ $exceptionPatient->branch?->code }}</td>
                            <td class="px-3 py-2 text-gray-700">{{ $noPhoneReasons[$exceptionPatient->no_phone_reason_code] ?? $exceptionPatient->no_phone_reason_code }}</td>
                            <td class="px-3 py-2 text-right">
                                <a href="{{ route('settings.patients.paper-card-registrations.show', $exceptionPatient->id) }}" class="text-indigo-600 hover:text-indigo-800">Detail</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-3 py-6 text-center text-gray-500">Tidak ada pengecualian tanpa nomor telepon yang terbuka.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $noPhoneExceptions->links() }}
    </div>
</x-settings-shell>
