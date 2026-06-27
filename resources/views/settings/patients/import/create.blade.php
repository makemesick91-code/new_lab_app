<x-settings-shell title="Impor Pasien Legacy">
    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">Data Pasien</p>
                <h2 class="mt-1 text-xl font-semibold text-gray-900">Impor Pasien Legacy (CSV)</h2>
                <p class="mt-1 text-sm text-gray-500">Unggah file legacy. Setiap baris distaging, divalidasi, dan ditinjau sebelum <strong>Commit</strong>. Tidak ada data pasien yang ditulis sebelum Anda konfirmasi.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('settings.patients.import.template') }}" class="inline-flex items-center rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50">Unduh Template CSV</a>
                <a href="{{ route('settings.patients.index') }}" class="inline-flex items-center rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-50">Kembali</a>
            </div>
        </div>

        @if (session('status'))
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800">{{ session('status') }}</div>
        @endif

        <section class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
            <h3 class="text-base font-semibold text-gray-900">Unggah File</h3>
            <p class="mt-1 text-sm text-gray-500">Format harus sesuai template (KTP/NIK akan disamarkan di seluruh tampilan). Kolom <em>Ruangan, Tindakan Awal, Keluhan Utama, TTD</em> hanya distaging dan tidak masuk RME.</p>

            <form method="POST" action="{{ route('settings.patients.import.store') }}" enctype="multipart/form-data" class="mt-6 space-y-4">
                @csrf
                <div>
                    <label for="csv_file" class="block text-sm font-medium text-gray-700">File CSV</label>
                    <input id="csv_file" type="file" name="csv_file" accept=".csv,.txt,text/csv,text/plain" required
                           class="mt-1 block w-full rounded-lg border border-gray-300 text-sm text-gray-700 file:mr-4 file:rounded-md file:border-0 file:bg-teal-50 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-teal-700 hover:file:bg-teal-100">
                    @error('csv_file')<p class="mt-1 text-sm text-rose-700">{{ $message }}</p>@enderror
                </div>
                <div class="flex items-center justify-end gap-2">
                    <button type="submit" class="inline-flex items-center rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-teal-600">Unggah & Staging</button>
                </div>
            </form>
        </section>

        <section class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
            <h3 class="text-base font-semibold text-gray-900">Batch Terbaru</h3>
            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="text-left text-gray-500">
                        <tr>
                            <th class="px-3 py-2 font-medium">File</th>
                            <th class="px-3 py-2 font-medium">Status</th>
                            <th class="px-3 py-2 font-medium">Total</th>
                            <th class="px-3 py-2 font-medium">Valid</th>
                            <th class="px-3 py-2 font-medium">Warning</th>
                            <th class="px-3 py-2 font-medium">Error</th>
                            <th class="px-3 py-2 font-medium">Commit</th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($batches as $batch)
                            <tr>
                                <td class="px-3 py-2 text-gray-900">{{ $batch->original_filename }}</td>
                                <td class="px-3 py-2"><span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-semibold text-gray-700">{{ $batch->status }}</span></td>
                                <td class="px-3 py-2 tabular-nums">{{ $batch->total_rows }}</td>
                                <td class="px-3 py-2 tabular-nums text-emerald-700">{{ $batch->valid_rows }}</td>
                                <td class="px-3 py-2 tabular-nums text-amber-700">{{ $batch->warning_rows }}</td>
                                <td class="px-3 py-2 tabular-nums text-rose-700">{{ $batch->error_rows }}</td>
                                <td class="px-3 py-2 tabular-nums">{{ $batch->committed_rows }}</td>
                                <td class="px-3 py-2 text-right"><a href="{{ route('settings.patients.import.show', $batch) }}" class="text-sm font-semibold text-teal-700 hover:text-teal-600">Tinjau</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="px-3 py-6 text-center text-gray-400">Belum ada batch impor.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</x-settings-shell>
