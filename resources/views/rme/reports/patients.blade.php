<x-settings-shell title="Dashboard RME">
    <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-lg font-semibold text-gray-900">Laporan Pasien RME</h2>
        </div>

        <form method="GET" action="{{ route('rme.reports.patients') }}" class="rounded-lg border border-gray-200 bg-gray-50 p-4">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
                <div>
                    <label for="branch_id" class="block text-xs text-gray-500">Cabang</label>
                    <select id="branch_id" name="branch_id" class="mt-1 w-full rounded-md border-gray-300 text-sm">
                        <option value="">Semua cabang RME</option>
                        @foreach ($branches as $b)
                            <option value="{{ $b->id }}" @selected($selectedBranchId == $b->id)>{{ $b->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="date_from" class="block text-xs text-gray-500">Tanggal dari</label>
                    <input id="date_from" type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}" class="mt-1 w-full rounded-md border-gray-300 text-sm" />
                </div>
                <div>
                    <label for="date_to" class="block text-xs text-gray-500">Tanggal sampai</label>
                    <input id="date_to" type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}" class="mt-1 w-full rounded-md border-gray-300 text-sm" />
                </div>
                <div>
                    <label for="status" class="block text-xs text-gray-500">Status</label>
                    <select id="status" name="status" class="mt-1 w-full rounded-md border-gray-300 text-sm">
                        <option value="">Semua status</option>
                        @foreach ($statusOptions as $value => $label)
                            <option value="{{ $value }}" @selected(($filters['status'] ?? null) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="sm:col-span-2">
                    <label for="q" class="block text-xs text-gray-500">Search ID / Nama Pasien</label>
                    <input id="q" type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="ID, RM, atau nama pasien" class="mt-1 w-full rounded-md border-gray-300 text-sm" />
                </div>
            </div>
            <div class="mt-3 flex flex-wrap items-center gap-2">
                <button type="submit" class="rounded-md bg-teal-700 px-3 py-2 text-sm font-medium text-white hover:bg-teal-600">Filter</button>
                <a href="{{ route('rme.reports.patients') }}" class="text-sm text-gray-500 hover:text-gray-700">Reset</a>
            </div>
        </form>

        <div class="flex flex-wrap gap-3 text-sm">
            <span class="rounded-md bg-blue-50 px-3 py-1 text-blue-700">Total Kunjungan: <strong>{{ $totalVisits }}</strong></span>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead><tr class="text-left text-gray-500">
                    <th class="px-3 py-2 font-medium">No. Kunjungan</th>
                    <th class="px-3 py-2 font-medium">ID / RM Pasien</th>
                    <th class="px-3 py-2 font-medium">Nama Pasien</th>
                    <th class="px-3 py-2 font-medium">Cabang</th>
                    <th class="px-3 py-2 font-medium">Tanggal Kunjungan</th>
                    <th class="px-3 py-2 font-medium">Status</th>
                </tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($visits as $v)
                        <tr>
                            <td class="px-3 py-2 font-medium text-gray-900">{{ $v->visit_number }}</td>
                            <td class="px-3 py-2 font-mono text-xs text-gray-600">{{ $v->patient?->medical_record_number ?? ('#'.$v->patient_id) }}</td>
                            <td class="px-3 py-2 text-gray-600">{{ $v->patient?->name ?? '—' }}</td>
                            <td class="px-3 py-2 text-gray-600">{{ $v->branch?->name ?? '—' }}</td>
                            <td class="px-3 py-2 text-gray-600">{{ $v->visit_date?->format('d/m/Y') ?? '—' }}</td>
                            <td class="px-3 py-2 text-gray-600">{{ $statusOptions[$v->status] ?? $v->status }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-3 py-6 text-center text-gray-400">Belum ada kunjungan RME.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-settings-shell>
