@php
    $metadata = $log->metadata ?? [];
    $safeMetadata = collect($metadata)
        ->except(['password', 'token', 'secret', 'api_key'])
        ->all();
@endphp

<x-settings-shell title="Detail Log Aktivitas">
    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">Detail Log Aktivitas</p>
                <h2 class="mt-1 text-xl font-semibold text-gray-900">{{ $log->displayActionLabel() }}</h2>
                <p class="mt-1 text-sm text-gray-500 tabular-nums">{{ format_datetime_id($log->created_at) }}</p>
            </div>
            <a href="{{ route('inventory.activity-logs.index') }}" class="inline-flex items-center rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-50 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                Kembali
            </a>
        </div>

        <section class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
            <h3 class="text-base font-semibold text-gray-900">Informasi Log</h3>
            <dl class="mt-5 grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-3">
                <div>
                    <dt class="text-gray-500">Waktu</dt>
                    <dd class="font-medium tabular-nums text-gray-900">{{ format_datetime_id($log->created_at) }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">Aksi</dt>
                    <dd class="font-medium text-gray-900">{{ $log->displayActionLabel() }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">Kode Aksi</dt>
                    <dd class="font-mono text-xs text-gray-900">{{ $log->action }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">Tipe Subjek</dt>
                    <dd class="font-mono text-xs text-gray-900">{{ $log->subject_type }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">ID Subjek</dt>
                    <dd class="font-medium tabular-nums text-gray-900">{{ $log->subject_id }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">Pengguna</dt>
                    <dd class="font-medium text-gray-900">{{ $log->user?->name ?? 'Sistem' }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">Cabang</dt>
                    <dd class="font-medium text-gray-900">{{ $log->branch?->name ?? '—' }}</dd>
                </div>
                <div class="sm:col-span-2 lg:col-span-3">
                    <dt class="text-gray-500">Deskripsi</dt>
                    <dd class="font-medium text-gray-900">{{ $log->description ?? '—' }}</dd>
                </div>
                <div class="sm:col-span-2 lg:col-span-3">
                    <dt class="text-gray-500">Correlation ID</dt>
                    <dd class="font-mono text-xs text-gray-900 break-all">{{ $log->correlation_id ?? '—' }}</dd>
                </div>
            </dl>
        </section>

        <section class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
            <h3 class="text-base font-semibold text-gray-900">Metadata</h3>
            @if ($safeMetadata === [])
                <p class="mt-3 text-sm text-gray-500">Tidak ada metadata tambahan.</p>
            @else
                <pre class="mt-4 overflow-x-auto rounded-lg bg-gray-50 p-4 text-xs text-gray-800">{{ json_encode($safeMetadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
            @endif
        </section>
    </div>
</x-settings-shell>
