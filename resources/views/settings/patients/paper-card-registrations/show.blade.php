{{-- DMS-RME-COMPREHENSIVE-CLOSURE-1 / memo M4.1 (rule 244 §4.3, PR-3b) — one
     patient's stored paper-card reasons, notes included. Supervisor RME and Owner
     only (Super Admin through Gate::before), inside the reader's branch scope;
     served `private, no-store`. Immutable: no edit or delete action exists. --}}
<x-settings-shell title="Registrasi Nomor RM Kartu Lama">
    <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4">
        <div>
            <h2 class="text-lg font-semibold text-gray-900">{{ $patient->name }}</h2>
            <p class="font-mono text-sm text-gray-600">{{ $patient->medical_record_number }}</p>
            <p class="mt-1 text-xs text-gray-500">Alasan dan catatan bersifat rahasia dan tidak dapat diubah atau dihapus. Jangan menyalin catatan ke tempat lain.</p>
        </div>
        @forelse ($registrations as $registration)
            <dl class="grid grid-cols-1 gap-2 rounded-md border border-gray-200 p-4 text-sm sm:grid-cols-2">
                <div><dt class="text-gray-500">Waktu (WITA)</dt><dd class="text-gray-800">{{ $registration->created_at?->copy()->setTimezone($clinicalTimezone)->format('d/m/Y H:i') }}</dd></div>
                <div><dt class="text-gray-500">Cabang</dt><dd class="text-gray-800">{{ $registration->branch?->code }} — {{ $registration->branch?->name }}</dd></div>
                <div><dt class="text-gray-500">Tahun pada kartu</dt><dd class="text-gray-800">{{ $registration->year }}</dd></div>
                <div><dt class="text-gray-500">Dicatat oleh</dt><dd class="text-gray-800">{{ $registration->actor?->name }}</dd></div>
                <div class="sm:col-span-2"><dt class="text-gray-500">Alasan</dt><dd class="text-gray-800">{{ $reasons[$registration->reason_code] ?? $registration->reason_code }}</dd></div>
                <div class="sm:col-span-2"><dt class="text-gray-500">Catatan</dt><dd class="whitespace-pre-line text-gray-800">{{ $registration->reason_note ?? '—' }}</dd></div>
            </dl>
        @empty
            <p class="text-sm text-gray-500">Pasien ini tidak didaftarkan dengan Nomor RM kartu lama.</p>
        @endforelse
        <a href="{{ route('settings.patients.paper-card-registrations.index') }}" class="text-sm text-gray-500 hover:text-gray-700">Kembali ke daftar</a>
    </div>
</x-settings-shell>
