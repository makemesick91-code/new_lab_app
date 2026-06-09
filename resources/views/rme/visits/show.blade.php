<x-settings-shell title="Detail Kunjungan">
    @php
        $statusLabels = [
            'registered'  => 'Terdaftar',
            'waiting'     => 'Menunggu',
            'in_progress' => 'Dalam Pemeriksaan',
            'completed'   => 'Selesai',
            'cancelled'   => 'Dibatalkan',
        ];
        $statusBadge = [
            'registered'  => 'bg-blue-50 text-blue-700',
            'waiting'     => 'bg-amber-50 text-amber-700',
            'in_progress' => 'bg-indigo-50 text-indigo-700',
            'completed'   => 'bg-green-50 text-green-700',
            'cancelled'   => 'bg-red-50 text-red-700',
        ];
    @endphp

    <div class="bg-white shadow-sm sm:rounded-lg">
        <div class="p-6 space-y-6">
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="text-lg font-semibold text-gray-900">{{ $visit->visit_number }}</h3>
                    <p class="text-sm text-gray-500">Antrian #{{ $visit->queue_number }} &mdash; {{ $visit->visit_date?->format('d/m/Y') }}</p>
                </div>
                <div class="flex items-center gap-3">
                    <span class="inline-flex items-center rounded-full px-3 py-1 text-sm font-medium {{ $statusBadge[$visit->status] ?? 'bg-gray-100 text-gray-600' }}">
                        {{ $statusLabels[$visit->status] ?? $visit->status }}
                    </span>
                    @can('update', $visit)
                        <a href="{{ route('rme.visits.edit', $visit) }}" class="inline-flex items-center rounded-md bg-amber-600 px-3 py-2 text-sm font-medium text-white hover:bg-amber-500">Ubah</a>
                    @endcan
                </div>
            </div>

            <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Pasien</dt>
                    <dd class="mt-1 text-sm text-gray-900">{{ $visit->patient?->name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Dokter</dt>
                    <dd class="mt-1 text-sm text-gray-900">{{ $visit->doctor?->name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Klinik</dt>
                    <dd class="mt-1 text-sm text-gray-900">{{ $visit->clinic?->name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Ruangan</dt>
                    <dd class="mt-1 text-sm text-gray-900">{{ $visit->clinicRoom?->name ?? '—' }}</dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Keluhan Utama</dt>
                    <dd class="mt-1 text-sm text-gray-900">{{ $visit->chief_complaint ?? '—' }}</dd>
                </div>
            </dl>

            <div class="border-t pt-4">
                <a href="{{ route('rme.visits.index') }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; Kembali ke daftar</a>
            </div>
        </div>
    </div>
</x-settings-shell>
