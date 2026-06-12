<x-settings-shell title="Dashboard RME">
    <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-lg font-semibold text-gray-900">Laporan Pasien RME</h2>
            <form method="GET" action="{{ route('rme.reports.patients') }}" class="flex flex-wrap items-end gap-2">
                <div>
                    <label class="block text-xs text-gray-500">Cabang</label>
                    <select name="branch_id" class="mt-1 rounded-md border-gray-300 text-sm">
                        <option value="">Semua cabang RME</option>
                        @foreach ($branches as $b)
                            <option value="{{ $b->id }}" @selected($selectedBranchId == $b->id)>{{ $b->name }}</option>
                        @endforeach
                    </select>
                </div>
                <button class="rounded-md bg-gray-800 px-3 py-2 text-sm font-medium text-white hover:bg-gray-700">Terapkan</button>
                <a href="{{ route('rme.reports.patients') }}" class="text-sm text-gray-500 hover:text-gray-700">Atur Ulang</a>
            </form>
        </div>

        <div class="flex flex-wrap gap-3 text-sm">
            <span class="rounded-md bg-blue-50 px-3 py-1 text-blue-700">Total Kunjungan: <strong>{{ $totalVisits }}</strong></span>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead><tr class="text-left text-gray-500">
                    <th class="px-3 py-2 font-medium">No. Kunjungan</th>
                    <th class="px-3 py-2 font-medium">Pasien</th>
                    <th class="px-3 py-2 font-medium">Cabang</th>
                    <th class="px-3 py-2 font-medium">Tanggal</th>
                    <th class="px-3 py-2 font-medium">Status</th>
                </tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($visits as $v)
                        <tr>
                            <td class="px-3 py-2 font-medium text-gray-900">{{ $v->visit_number }}</td>
                            <td class="px-3 py-2 text-gray-600">{{ $v->patient?->name ?? '—' }}</td>
                            <td class="px-3 py-2 text-gray-600">{{ $v->branch?->name ?? '—' }}</td>
                            <td class="px-3 py-2 text-gray-600">{{ $v->visit_date?->format('d/m/Y') ?? '—' }}</td>
                            <td class="px-3 py-2 text-gray-600">{{ $v->status }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-3 py-6 text-center text-gray-400">Belum ada kunjungan RME.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-settings-shell>
