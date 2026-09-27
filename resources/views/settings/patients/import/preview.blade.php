{{--
    REVISION-LEGACY-PATIENT-STAGED-VERIFICATION-CANCEL-1 — batch verification.

    This page is a review surface, not a control. Every decision it renders is
    taken again server-side: the confirm button's presence mirrors
    LegacyPatientImportBatch::committable(), it does not implement it, and a
    crafted POST meets the same predicate.
--}}
<x-settings-shell title="Verifikasi Impor Pasien Legacy">
    @php
        $badge = [
            'valid' => 'bg-emerald-100 text-emerald-800',
            'warning' => 'bg-amber-100 text-amber-800',
            'error' => 'bg-rose-100 text-rose-800',
            'committed' => 'bg-teal-100 text-teal-800',
            'skipped' => 'bg-gray-200 text-gray-700',
            'rolled_back' => 'bg-gray-200 text-gray-700',
        ];

        // Resolved once — the previous per-row Branch::find() ran one query per
        // rendered row.
        $branchCodes = \App\Modules\Branch\Models\Branch::query()
            ->whereIn('id', collect($rows->items())->pluck('matched_branch_id')->filter()->unique()->all())
            ->pluck('code', 'id');

        $approvedRows = $batch->valid_rows + $batch->warning_rows;
    @endphp
    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">Batch #{{ $batch->id }} — {{ $batch->status }}</p>
                <h2 class="mt-1 text-xl font-semibold text-gray-900">{{ $batch->original_filename }}</h2>
                <p class="mt-1 text-sm text-gray-500">KTP/NIK disamarkan. Kolom advisory (Ruangan, Tindakan Awal, Keluhan Utama, TTD) hanya distaging — <strong>belum masuk RME</strong>.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('settings.patients.import.errors', $batch) }}" class="inline-flex items-center rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50">Unduh Laporan Verifikasi</a>
                <a href="{{ route('settings.patients.import.index') }}" class="inline-flex items-center rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-50">Kembali</a>
            </div>
        </div>

        @if (session('status'))
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800">{{ session('status') }}</div>
        @endif
        @foreach (['commit', 'rollback', 'discard', 'acknowledged', 'cancel_reason'] as $errKey)
            @error($errKey)<div class="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800">{{ $message }}</div>@enderror
        @endforeach

        @if (session('import_blocked_findings'))
            <div class="rounded-lg border border-rose-200 bg-white p-4 text-sm">
                <p class="font-semibold text-rose-800">Temuan yang memblokir impor (maksimal 20 ditampilkan — daftar lengkap ada di laporan verifikasi):</p>
                <ul class="mt-2 space-y-1 text-rose-700">
                    @foreach (session('import_blocked_findings') as $finding)
                        <li>• Baris {{ $finding['row'] }}: {{ $finding['message'] }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- THE VERDICT. One sentence, stating what will and will not happen. --}}
        @if ($batch->isReviewRequired())
            <div class="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm text-rose-900">
                <p class="font-semibold">Batch tidak dapat diimpor — {{ $batch->error_rows }} baris berstatus ERROR.</p>
                <p class="mt-1">Selama masih ada baris ERROR, <strong>seluruh batch ditolak dan tidak ada satu pasien pun yang diimpor</strong> — termasuk {{ $approvedRows }} baris yang sudah lolos verifikasi. Perbaiki berkas sumber di luar sistem, batalkan batch ini, lalu unggah ulang berkas yang sudah diperbaiki.</p>
            </div>
        @elseif ($batch->isReadyToImport())
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">
                <p class="font-semibold">Siap diimpor — 0 baris ERROR.</p>
                <p class="mt-1">{{ $approvedRows }} baris akan diimpor dalam <strong>satu transaksi</strong>: semuanya berhasil, atau tidak ada yang tersimpan. Saat konfirmasi, sistem memverifikasi ulang seluruh batch terhadap data master terkini.</p>
            </div>
        @elseif ($batch->isCancelled())
            <div class="rounded-lg border border-gray-300 bg-gray-50 p-4 text-sm text-gray-800">
                <p class="font-semibold">Batch dibatalkan.</p>
                <p class="mt-1">
                    Tidak ada pasien yang diimpor dari batch ini.
                    @if ($batch->cancelled_at)
                        Dibatalkan {{ $batch->cancelled_at->format('d/m/Y H:i') }}@if ($batch->canceller) oleh {{ $batch->canceller->name }}@endif.
                    @endif
                    @if ($batch->cancel_reason)
                        <span class="block mt-1">Alasan: {{ $batch->cancel_reason }}</span>
                    @endif
                </p>
            </div>
        @elseif ($batch->isCommitted())
            <div class="rounded-lg border border-teal-200 bg-teal-50 p-4 text-sm text-teal-900">
                <p class="font-semibold">Batch sudah diimpor — {{ $batch->committed_rows }} pasien.</p>
                <p class="mt-1">Batch yang sudah diimpor tidak dapat dibatalkan. Untuk menarik pasien hasil impor, gunakan Rollback (ditolak jika ada pasien yang sudah memiliki kunjungan/rekam medis).</p>
            </div>
        @elseif ($batch->status === \App\Modules\Patient\Models\LegacyPatientImportBatch::STATUS_FAILED)
            <div class="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm text-rose-900">
                <p class="font-semibold">Batch tidak dapat diimpor.</p>
                <p class="mt-1">Identitas berkas sumber tidak dapat dibuktikan lagi (berkas hilang atau berubah setelah diunggah). Batalkan batch ini dan unggah ulang berkas.</p>
            </div>
        @endif

        <div class="grid grid-cols-2 gap-3 sm:grid-cols-5">
            <div class="rounded-lg border border-gray-200 bg-white p-4"><p class="text-xs text-gray-500">Total</p><p class="mt-1 text-2xl font-semibold text-gray-900">{{ $batch->total_rows }}</p></div>
            <div class="rounded-lg border border-gray-200 bg-white p-4"><p class="text-xs text-gray-500">Valid</p><p class="mt-1 text-2xl font-semibold text-emerald-700">{{ $batch->valid_rows }}</p></div>
            <div class="rounded-lg border border-gray-200 bg-white p-4"><p class="text-xs text-gray-500">Warning</p><p class="mt-1 text-2xl font-semibold text-amber-700">{{ $batch->warning_rows }}</p></div>
            <div class="rounded-lg border border-gray-200 bg-white p-4"><p class="text-xs text-gray-500">Error</p><p class="mt-1 text-2xl font-semibold text-rose-700">{{ $batch->error_rows }}</p></div>
            <div class="rounded-lg border border-gray-200 bg-white p-4">
                <p class="text-xs text-gray-500">Akan diimpor</p>
                {{-- Zero while any error stands: the number must state the outcome, not the eligible subset. --}}
                <p class="mt-1 text-2xl font-semibold {{ $batch->isReadyToImport() ? 'text-teal-700' : 'text-gray-400' }}">{{ $batch->isReadyToImport() ? $approvedRows : 0 }}</p>
            </div>
        </div>

        <div class="flex flex-wrap items-start gap-3">
            @if ($batch->isReadyToImport())
                <form method="POST" action="{{ route('settings.patients.import.commit', $batch) }}" class="rounded-lg border border-teal-200 bg-white p-4">
                    @csrf
                    <label class="flex items-start gap-2 text-sm text-gray-700">
                        <input type="checkbox" name="acknowledged" value="1" required class="mt-0.5 rounded border-gray-300 text-teal-700 focus:ring-teal-500">
                        <span>Saya telah memeriksa hasil verifikasi batch ({{ $batch->total_rows }} baris · {{ $batch->valid_rows }} valid · {{ $batch->warning_rows }} warning · 0 error) untuk berkas <strong>{{ $batch->original_filename }}</strong>.</span>
                    </label>
                    <button type="submit" class="mt-3 inline-flex items-center rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-teal-600">Konfirmasi &amp; Import Pasien</button>
                </form>
            @endif

            @if ($batch->isCancellable())
                <form method="POST" action="{{ route('settings.patients.import.destroy', $batch) }}" class="rounded-lg border border-gray-200 bg-white p-4" onsubmit="return confirm('Batch ini belum mengubah data pasien. Batalkan batch dan hentikan proses import?');">
                    @csrf @method('DELETE')
                    <label for="cancel_reason" class="block text-sm font-medium text-gray-700">Alasan pembatalan <span class="font-normal text-gray-400">(opsional)</span></label>
                    <input id="cancel_reason" type="text" name="cancel_reason" maxlength="500" placeholder="mis. berkas sumber diperbaiki dan akan diunggah ulang"
                           class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                    <p class="mt-1 text-xs text-gray-500">Pembatalan tidak membuat atau menghapus pasien. Riwayat batch dan hasil verifikasi tetap tersimpan.</p>
                    <button type="submit" class="mt-3 inline-flex items-center rounded-lg border border-rose-200 bg-rose-50 px-4 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-100">Batalkan Batch</button>
                </form>
            @endif

            @if ($batch->isCommitted())
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
                                <td class="px-3 py-2 text-gray-700">{{ $row->matched_branch_id ? ($branchCodes[$row->matched_branch_id] ?? '—') : '—' }}</td>
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
