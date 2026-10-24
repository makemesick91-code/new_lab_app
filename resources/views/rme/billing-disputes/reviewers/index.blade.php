{{-- Rule 204 (OPS-OQ-1a) — explicit branch billing dispute reviewers. The page is a
     courtesy: the route requires manage_rme_billing_dispute_reviewers and the service
     decides every rule again on the persisted accounts. --}}
@php
    use App\Modules\ClinicOversight\Support\OversightFormat;
@endphp
<x-settings-shell title="Peninjau Ketidaksesuaian Tagihan">
    <div class="space-y-6">
        <x-ui.page-header title="Peninjau Ketidaksesuaian Tagihan per Cabang"
            subtitle="Tunjuk satu akun sebagai penanggung jawab peninjauan laporan ketidaksesuaian tagihan untuk satu cabang. Penunjukan tidak memberi izin mengubah harga, mengoreksi, membatalkan, atau menerima pembayaran.">
            <x-slot:breadcrumb>Rekam Medis Elektronik · Kasir · Ketidaksesuaian Tagihan</x-slot:breadcrumb>
        </x-ui.page-header>

        @if (session('success'))
            <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
        @endif

        @if ($errors->any())
            <x-ui.alert variant="danger" title="Penunjukan tidak dapat diproses">
                <ul class="list-disc pl-5">
                    @foreach ($errors->all() as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            </x-ui.alert>
        @endif

        <x-ui.alert variant="info" title="Batas kewenangan peninjau cabang">
            Peninjau hanya dapat meninjau, memberi catatan, mengeskalasi ke Owner, dan menyelesaikan laporan
            di cabang yang ditunjuk — itupun hanya saat sedang bekerja di cabang tersebut. Penyelesaian tetap
            memerlukan bukti yang diverifikasi sistem, dan pelapor tidak dapat menyatakan sendiri tagihannya benar.
            Akun Owner tetap hanya-baca dan tidak dapat ditunjuk. Anda tidak dapat menunjuk diri sendiri.
        </x-ui.alert>

        <x-ui.card title="Tunjuk peninjau" data-billing-dispute-reviewer-form>
            <form method="POST" action="{{ route('rme.billing-dispute-reviewers.store') }}" class="grid gap-4 md:grid-cols-3">
                @csrf
                <label class="block text-sm">
                    <span class="font-medium text-ink">Akun</span>
                    <select name="user_id" required class="mt-1 w-full rounded-lg border-hairline text-sm">
                        <option value="">— pilih akun —</option>
                        @foreach ($candidates as $candidate)
                            <option value="{{ $candidate->id }}" @selected((int) old('user_id') === (int) $candidate->id)>
                                {{ $candidate->name }} ({{ $candidate->roles->pluck('name')->join(', ') ?: 'tanpa peran' }})
                            </option>
                        @endforeach
                    </select>
                </label>
                <label class="block text-sm">
                    <span class="font-medium text-ink">Cabang</span>
                    <select name="branch_id" required class="mt-1 w-full rounded-lg border-hairline text-sm">
                        <option value="">— pilih cabang —</option>
                        @foreach ($branches as $branch)
                            <option value="{{ $branch->id }}" @selected((int) old('branch_id') === (int) $branch->id)>
                                {{ $branch->code }} — {{ $branch->name }}
                            </option>
                        @endforeach
                    </select>
                </label>
                <label class="block text-sm md:col-span-3">
                    <span class="font-medium text-ink">Alasan penunjukan (10–1000 karakter, tanpa NIK)</span>
                    <textarea name="reason" rows="2" required minlength="10" maxlength="1000"
                              class="mt-1 w-full rounded-lg border-hairline text-sm">{{ old('reason') }}</textarea>
                </label>
                <div class="md:col-span-3">
                    <x-ui.button type="submit" variant="primary">Tunjuk peninjau</x-ui.button>
                </div>
            </form>
        </x-ui.card>

        <x-ui.card title="Daftar penunjukan" padding="" data-billing-dispute-reviewer-list>
            @if ($designations->isEmpty())
                <div class="p-6">
                    <x-ui.empty-state title="Belum ada peninjau cabang"
                        description="Selama belum ada penunjukan, laporan ketidaksesuaian tagihan hanya dapat diselesaikan oleh Super Admin." />
                </div>
            @else
                <x-ui.table>
                    <thead class="bg-navy-50">
                        <tr>
                            <th class="px-4 py-2 text-left text-xs font-semibold text-ink-soft">Akun</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold text-ink-soft">Cabang</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold text-ink-soft">Ditunjuk</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold text-ink-soft">Status</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold text-ink-soft">Tindakan</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-hairline">
                        @foreach ($designations as $designation)
                            <tr data-designation-id="{{ $designation->id }}">
                                <td class="px-4 py-2 text-sm">{{ $designation->user?->name ?? '#'.$designation->user_id }}</td>
                                <td class="px-4 py-2 text-sm">{{ $designation->branch?->code ?? '#'.$designation->branch_id }}</td>
                                <td class="px-4 py-2 text-sm">
                                    {{ OversightFormat::clinicalTime($designation->granted_at, 'd/m/Y H:i') }} WITA
                                    oleh {{ $designation->granter?->name ?? '#'.$designation->granted_by }}
                                </td>
                                <td class="px-4 py-2 text-sm">
                                    @if ($designation->isActive())
                                        <x-ui.badge tone="success">Aktif</x-ui.badge>
                                    @else
                                        <x-ui.badge tone="neutral">Dicabut</x-ui.badge>
                                        <span class="block text-xs text-ink-soft">
                                            {{ OversightFormat::clinicalTime($designation->revoked_at, 'd/m/Y H:i') }} WITA
                                            oleh {{ $designation->revoker?->name ?? '#'.$designation->revoked_by }}
                                        </span>
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-sm">
                                    @if ($designation->isActive())
                                        <form method="POST" action="{{ route('rme.billing-dispute-reviewers.revoke', $designation->id) }}" class="flex flex-col gap-2">
                                            @csrf
                                            <input type="text" name="reason" required minlength="10" maxlength="1000"
                                                   placeholder="Alasan pencabutan"
                                                   class="w-full rounded-lg border-hairline text-xs">
                                            <x-ui.button type="submit" variant="danger" size="sm">Cabut</x-ui.button>
                                        </form>
                                    @else
                                        <span class="text-xs text-ink-soft">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-ui.table>
            @endif
        </x-ui.card>
    </div>
</x-settings-shell>
