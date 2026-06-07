<x-settings-shell title="Log Aktivitas Persediaan">
    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">Inventory Activity Log</p>
                <h2 class="mt-1 text-xl font-semibold text-gray-900">Log Aktivitas Persediaan</h2>
                <p class="mt-1 text-sm text-gray-500">Jejak audit aktivitas persediaan dan pengadaan untuk cabang aktif.</p>
            </div>
            <a href="{{ route('inventory.dashboard') }}" class="inline-flex items-center rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-50 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                Kembali ke Dasbor
            </a>
        </div>

        <form method="GET" action="{{ route('inventory.activity-logs.index') }}" class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
            <div class="grid gap-3 md:grid-cols-2 lg:grid-cols-4 xl:grid-cols-5 lg:items-end">
                <div class="md:col-span-2">
                    <label for="activity-log-search" class="text-sm font-medium text-gray-700">Cari deskripsi</label>
                    <input id="activity-log-search" type="search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Kata kunci dalam deskripsi"
                           class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                </div>
                <div>
                    <label for="activity-log-action" class="text-sm font-medium text-gray-700">Aksi</label>
                    <select id="activity-log-action" name="action" class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                        <option value="">Semua aksi</option>
                        @foreach ($actionOptions as $action)
                            <option value="{{ $action }}" @selected(($filters['action'] ?? '') === $action)>{{ str($action)->replace('_', ' ')->title() }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="activity-log-user-id" class="text-sm font-medium text-gray-700">ID Pengguna</label>
                    <input id="activity-log-user-id" type="number" name="user_id" min="1" value="{{ $filters['user_id'] ?? '' }}"
                           class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                </div>
                <div>
                    <label for="activity-log-subject-type" class="text-sm font-medium text-gray-700">Tipe Subjek</label>
                    <input id="activity-log-subject-type" type="text" name="subject_type" value="{{ $filters['subject_type'] ?? '' }}" placeholder="mis. inv_purchase_requests"
                           class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                </div>
                <div>
                    <label for="activity-log-subject-id" class="text-sm font-medium text-gray-700">ID Subjek</label>
                    <input id="activity-log-subject-id" type="number" name="subject_id" min="1" value="{{ $filters['subject_id'] ?? '' }}"
                           class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                </div>
                <div class="md:col-span-2">
                    <label for="activity-log-correlation-id" class="text-sm font-medium text-gray-700">Correlation ID</label>
                    <input id="activity-log-correlation-id" type="text" name="correlation_id" value="{{ $filters['correlation_id'] ?? '' }}" placeholder="UUID rantai workflow"
                           class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                </div>
                <div>
                    <label for="activity-log-date-from" class="text-sm font-medium text-gray-700">Dari tanggal</label>
                    <input id="activity-log-date-from" type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}"
                           class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                </div>
                <div>
                    <label for="activity-log-date-to" class="text-sm font-medium text-gray-700">Sampai tanggal</label>
                    <input id="activity-log-date-to" type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}"
                           class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                </div>
                <div>
                    <label for="activity-log-per-page" class="text-sm font-medium text-gray-700">Per halaman</label>
                    <select id="activity-log-per-page" name="per_page" class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                        @foreach ([10, 25, 50, 100] as $size)
                            <option value="{{ $size }}" @selected((int) ($filters['per_page'] ?? 25) === $size)>{{ $size }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="mt-3 flex flex-wrap gap-2">
                <button type="submit" class="inline-flex justify-center rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800 focus:outline-none focus:ring-2 focus:ring-gray-700 focus:ring-offset-2">
                    Terapkan
                </button>
                <a href="{{ route('inventory.activity-logs.index') }}" class="inline-flex justify-center rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-50 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                    Atur Ulang
                </a>
            </div>
        </form>

        <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 px-4 py-3">
                <div>
                    <h3 class="text-base font-semibold text-gray-900">Log Aktivitas</h3>
                    <p class="text-sm text-gray-500">{{ format_number_id($logs->total()) }} entri dalam cabang aktif.</p>
                </div>
            </div>

            @if ($logs->isEmpty())
                <div class="px-4 py-12 text-center">
                    <p class="text-sm font-medium text-gray-900">Belum ada log aktivitas.</p>
                    <p class="mt-1 text-sm text-gray-500">Log akan muncul setelah aktivitas persediaan atau pengadaan tercatat.</p>
                </div>
            @else
                <div class="hidden overflow-x-auto md:block">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr class="text-left text-gray-500">
                                <th scope="col" class="px-4 py-3 font-medium">Waktu</th>
                                <th scope="col" class="px-4 py-3 font-medium">Aksi</th>
                                <th scope="col" class="px-4 py-3 font-medium">Subjek</th>
                                <th scope="col" class="px-4 py-3 font-medium">Deskripsi</th>
                                <th scope="col" class="px-4 py-3 font-medium">Pengguna</th>
                                <th scope="col" class="px-4 py-3 font-medium">Cabang</th>
                                <th scope="col" class="px-4 py-3 font-medium">Correlation ID</th>
                                <th scope="col" class="px-4 py-3 font-medium">Metadata</th>
                                <th scope="col" class="px-4 py-3 font-medium">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 bg-white">
                            @foreach ($logs as $log)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-3 tabular-nums text-gray-700 whitespace-nowrap">{{ format_datetime_id($log->created_at) }}</td>
                                    <td class="px-4 py-3 font-medium text-gray-900">{{ $log->displayActionLabel() }}</td>
                                    <td class="px-4 py-3 text-gray-700">
                                        <span class="font-mono text-xs">{{ $log->subject_type }}</span>
                                        <span class="text-gray-400">#</span>{{ $log->subject_id }}
                                    </td>
                                    <td class="px-4 py-3 max-w-xs truncate text-gray-700" title="{{ $log->description }}">{{ $log->description ?? '—' }}</td>
                                    <td class="px-4 py-3 text-gray-700">{{ $log->user?->name ?? 'Sistem' }}</td>
                                    <td class="px-4 py-3 text-gray-700">{{ $log->branch?->name ?? '—' }}</td>
                                    <td class="px-4 py-3 font-mono text-xs text-gray-600 max-w-[10rem] truncate" title="{{ $log->correlation_id }}">{{ $log->correlation_id ?? '—' }}</td>
                                    <td class="px-4 py-3 max-w-xs truncate text-gray-600" title="{{ $log->metadataSummary() }}">{{ $log->metadataSummary() ?? '—' }}</td>
                                    <td class="px-4 py-3 whitespace-nowrap">
                                        <a href="{{ route('inventory.activity-logs.show', $log) }}" class="text-sm font-medium text-teal-700 hover:text-teal-600">Detail</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="divide-y divide-gray-100 md:hidden">
                    @foreach ($logs as $log)
                        <article class="p-4">
                            <div class="flex flex-wrap items-start justify-between gap-2">
                                <h4 class="font-semibold text-gray-900">{{ $log->displayActionLabel() }}</h4>
                                <time class="text-xs tabular-nums text-gray-500">{{ format_datetime_id($log->created_at) }}</time>
                            </div>
                            <p class="mt-2 text-sm text-gray-700">{{ $log->description ?? '—' }}</p>
                            <dl class="mt-3 grid grid-cols-2 gap-2 text-sm">
                                <div>
                                    <dt class="text-gray-500">Subjek</dt>
                                    <dd class="font-medium text-gray-900">{{ $log->subject_type }} #{{ $log->subject_id }}</dd>
                                </div>
                                <div>
                                    <dt class="text-gray-500">Pengguna</dt>
                                    <dd class="font-medium text-gray-900">{{ $log->user?->name ?? 'Sistem' }}</dd>
                                </div>
                                <div>
                                    <dt class="text-gray-500">Cabang</dt>
                                    <dd class="font-medium text-gray-900">{{ $log->branch?->name ?? '—' }}</dd>
                                </div>
                                <div>
                                    <dt class="text-gray-500">Metadata</dt>
                                    <dd class="font-medium text-gray-900">{{ $log->metadataSummary() ?? '—' }}</dd>
                                </div>
                            </dl>
                            <a href="{{ route('inventory.activity-logs.show', $log) }}" class="mt-3 inline-flex text-sm font-medium text-teal-700 hover:text-teal-600">Lihat detail</a>
                        </article>
                    @endforeach
                </div>

                @if ($logs->hasPages())
                    <div class="border-t border-gray-200 px-4 py-3">
                        {{ $logs->links() }}
                    </div>
                @endif
            @endif
        </section>
    </div>
</x-settings-shell>
