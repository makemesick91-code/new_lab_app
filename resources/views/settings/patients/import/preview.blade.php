<x-settings-shell title="Pratinjau Impor Pasien Legacy">
    @php
        $badge = [
            'valid' => 'bg-emerald-100 text-emerald-800',
            'warning' => 'bg-amber-100 text-amber-800',
            'error' => 'bg-rose-100 text-rose-800',
            'committed' => 'bg-teal-100 text-teal-800',
            'skipped' => 'bg-gray-200 text-gray-700',
            'rolled_back' => 'bg-gray-200 text-gray-700',
        ];
    @endphp
    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">Batch #{{ $batch->id }} — {{ $batch->status }}</p>
                <h2 class="mt-1 text-xl font-semibold text-gray-900">{{ $batch->original_filename }}</h2>
                <p class="mt-1 text-sm text-gray-500">KTP/NIK disamarkan. Kolom advisory (Ruangan, Tindakan Awal, Keluhan Utama, TTD) hanya distaging — <strong>belum masuk RME</strong>.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('settings.patients.import.errors', $batch) }}" class="inline-flex items-center rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50">Unduh Laporan Error</a>
                <a href="{{ route('settings.patients.import.index') }}" class="inline-flex items-center rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-50">Kembali</a>
            </div>
        </div>

        @if (session('status'))
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800">{{ session('status') }}</div>
        @endif
        @foreach (['commit', 'rollback', 'discard'] as $errKey)
            @error($errKey)<div class="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800">{{ $message }}</div>@enderror
        @endforeach

        <div class="grid grid-cols-2 gap-3 sm:grid-cols-5">
            <div class="rounded-lg border border-gray-200 bg-white p-4"><p class="text-xs text-gray-500">Total</p><p class="mt-1 text-2xl font-semibold text-gray-900">{{ $batch->total_rows }}</p></div>
            <div class="rounded-lg border border-gray-200 bg-white p-4"><p class="text-xs text-gray-500">Valid</p><p class="mt-1 text-2xl font-semibold text-emerald-700">{{ $batch->valid_rows }}</p></div>
            <div class="rounded-lg border border-gray-200 bg-white p-4"><p class="text-xs text-gray-500">Warning</p><p class="mt-1 text-2xl font-semibold text-amber-700">{{ $batch->warning_rows }}</p></div>
            <div class="rounded-lg border border-gray-200 bg-white p-4"><p class="text-xs text-gray-500">Error</p><p class="mt-1 text-2xl font-semibold text-rose-700">{{ $batch->error_rows }}</p></div>
            <div class="rounded-lg border border-gray-200 bg-white p-4"><p class="text-xs text-gray-500">Akan di-commit</p><p class="mt-1 text-2xl font-semibold text-teal-700">{{ $batch->valid_rows + $batch->warning_rows }}</p></div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @if ($batch->committable())
                <form method="POST" action="{{ route('settings.patients.import.commit', $batch) }}" onsubmit="return confirm('Commit {{ $batch->valid_rows + $batch->warning_rows }} baris ke master pasien?');">
                    @csrf
                    <button type="submit" class="inline-flex items-center rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-teal-600">Commit Baris Valid</button>
                </form>
                <form method="POST" action="{{ route('settings.patients.import.destroy', $batch) }}" onsubmit="return confirm('Buang batch staging ini?');">
                    @csrf @method('DELETE')
                    <button type="submit" class="inline-flex items-center rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-50">Buang Batch</button>
                </form>
            @elseif ($batch->isCommitted())
                <form method="POST" action="{{ route('settings.patients.import.rollback', $batch) }}" onsubmit="return confirm('Rollback batch ini (soft-delete pasien hasil impor)?');">
                    @csrf
                    <button type="submit" class="inline-flex items-center rounded-lg border border-rose-200 bg-rose-50 px-4 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-100">Rollback Batch</button>
                </form>
            @endif
        </div>

        <form method="GET" action="{{ route('settings.patients.import.show', $batch) }}" class="flex flex-wrap items-center gap-2">
            <input type="text" name="search" value="{{ $search }}" placeholder="Cari nama / RM" class="rounded-md border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
            <select name="status" class="rounded-md border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                <option value="">Semua status</option>
                @foreach (['valid', 'warning', 'error', 'committed', 'skipped', 'rolled_back'] as $s)
                    <option value="{{ $s }}" @selected($statusFilter === $s)>{{ $s }}</option>
                @endforeach
            </select>
            <button type="submit" class="rounded-md border border-gray-200 px-3 py-1.5 text-sm font-semibold text-gray-700 hover:bg-gray-50">Filter</button>
        </form>

        <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="text-left text-gray-500">
                        <tr>
                            <th class="px-3 py-2 font-medium">Baris</th>
                            <th class="px-3 py-2 font-medium">Status</th>
                            <th class="px-3 py-2 font-medium">Nama</th>
                            <th class="px-3 py-2 font-medium">KTP</th>
                            <th class="px-3 py-2 font-medium">RM Final</th>
                            <th class="px-3 py-2 font-medium">Cabang</th>
                            <th class="px-3 py-2 font-medium">Pesan</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($rows as $row)
                            <tr>
                                <td class="px-3 py-2 tabular-nums text-gray-700">{{ $row->row_number }}</td>
                                <td class="px-3 py-2"><span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $badge[$row->status] ?? 'bg-gray-100 text-gray-700' }}">{{ $row->status }}</span></td>
                                <td class="px-3 py-2 text-gray-900">{{ $row->patient_name }}</td>
                                <td class="px-3 py-2 font-mono text-xs text-gray-500">{{ $row->ktp_masked ?? '—' }}</td>
                                <td class="px-3 py-2 font-mono text-xs text-gray-700">{{ $row->generated_medical_record_number ?? '—' }}</td>
                                <td class="px-3 py-2 text-gray-700">{{ $row->matched_branch_id ? (\App\Modules\Branch\Models\Branch::find($row->matched_branch_id)?->code ?? '—') : '—' }}</td>
                                <td class="px-3 py-2 text-xs text-gray-600">
                                    @foreach (($row->errors ?? []) as $m)<div class="text-rose-700">• {{ $m }}</div>@endforeach
                                    @foreach (($row->warnings ?? []) as $m)<div class="text-amber-700">• {{ $m }}</div>@endforeach
                                    @if (($row->advisory_initial_treatment || $row->advisory_chief_complaint || $row->advisory_doctor_signature || $row->advisory_patient_signature))
                                        <div class="mt-1 text-gray-400">Advisory (staged only / belum masuk RME): {{ collect([$row->advisory_initial_treatment, $row->advisory_chief_complaint])->filter()->implode(' · ') }}</div>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-3 py-6 text-center text-gray-400">Tidak ada baris.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="border-t border-gray-100 px-3 py-3">{{ $rows->links() }}</div>
        </section>
    </div>
</x-settings-shell>
