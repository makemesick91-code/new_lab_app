{{-- FEATURE-RME-DOCTOR-PERFORMED-TREATMENTS-1 (rule 219; owner CB-Q1, DPT-Q1) —
     "Tindakan Dilakukan": the treating doctor's structured record of what was
     ACTUALLY performed at this visit, or an explicit "Tidak Ada Tindakan"
     (operational KONTROL). Presentation only: every value comes from
     RmePerformedTreatmentService::workspaceFor(), and every control mirrors the
     service authority ($pt['can_author']); a crafted POST is refused server-side
     regardless. Rendered only while the capability is ON. --}}
@php
    $pt = $performedTreatments;
@endphp
<div data-dpt-section data-dpt-state="{{ $pt['state'] }}">
<x-ui.card title="Tindakan Dilakukan"
    description="Catat tindakan medis yang benar-benar dilakukan pada kunjungan ini. Rencana, Tindakan Awal, diagnosis, catatan tulisan tangan dan odontogram tidak dihitung sebagai tindakan.">

    <div class="mb-3 flex flex-wrap items-center gap-2 text-sm">
        <span class="text-ink-muted">Status:</span>
        <x-ui.badge :tone="$pt['state'] === 'NOT_RECORDED' ? 'neutral' : 'success'">{{ $pt['state_label'] }}</x-ui.badge>
        @if ($pt['operational_category'] !== null)
            <span class="text-ink-muted">Kategori operasional:</span>
            <x-ui.badge tone="info">{{ $pt['operational_category_label'] }}</x-ui.badge>
        @endif
    </div>

    @if ($pt['state'] === 'NO_TREATMENT_DECLARED')
        <x-ui.alert variant="info">
            Dokter menyatakan <strong>tidak ada tindakan medis</strong> pada kunjungan ini, sehingga kunjungan
            dikategorikan <strong>KONTROL</strong> secara operasional. Kategori ini <strong>tidak</strong> mengubah jenis
            kunjungan dan <strong>tidak</strong> otomatis membuat kunjungan gratis — kelayakan tanpa biaya tetap diperiksa
            kasir sesuai aturan Kontrol (harus ada kunjungan sebelumnya yang sah).
        </x-ui.alert>
    @elseif ($pt['state'] === 'NOT_RECORDED')
        <x-ui.alert variant="warning">
            Tindakan belum dicatat. Daftar kosong <strong>bukan</strong> pernyataan "Tidak Ada Tindakan" — pilih salah satu
            secara eksplisit.
        </x-ui.alert>
    @endif

    @if ($pt['active_rows']->isNotEmpty())
        <div class="mt-3 overflow-x-auto">
            <x-ui.table>
                <thead>
                    <tr>
                        <th class="px-3 py-2 text-left">Kode</th>
                        <th class="px-3 py-2 text-left">Tindakan</th>
                        <th class="px-3 py-2 text-left">Jumlah</th>
                        <th class="px-3 py-2 text-left">Gigi</th>
                        <th class="px-3 py-2 text-left">Dicatat</th>
                        @if ($pt['can_author'])
                            <th class="px-3 py-2 text-left">Batalkan</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @foreach ($pt['active_rows'] as $row)
                        <tr class="border-t border-hairline" data-dpt-row="{{ $row->id }}">
                            <td class="px-3 py-2 font-mono text-sm">{{ $row->treatment_code_snapshot }}</td>
                            <td class="px-3 py-2">{{ $row->treatment_name_snapshot }}</td>
                            <td class="px-3 py-2">{{ $row->quantity }}</td>
                            <td class="px-3 py-2">{{ $row->tooth_numbers ? implode(', ', $row->tooth_numbers) : '—' }}</td>
                            <td class="px-3 py-2 text-xs text-ink-muted">{{ $row->recorded_at?->format('d M Y H:i') }}</td>
                            @if ($pt['can_author'])
                                <td class="px-3 py-2">
                                    <form method="POST" action="{{ route('rme.visits.performed-treatments.void', [$clinicVisit, $row]) }}"
                                          class="flex flex-col gap-1 md:flex-row md:items-center">
                                        @csrf
                                        <input type="text" name="void_reason" required maxlength="500" placeholder="Alasan pembatalan"
                                               class="w-full rounded-lg border border-hairline px-2 py-1 text-sm md:w-48" />
                                        <x-ui.button size="sm" type="submit" variant="secondary">Batalkan</x-ui.button>
                                    </form>
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
        </div>
    @endif

    @if ($pt['voided_rows']->isNotEmpty())
        <details class="mt-3 text-sm">
            <summary class="cursor-pointer text-ink-muted">Riwayat tindakan yang dibatalkan ({{ $pt['voided_rows']->count() }})</summary>
            <ul class="mt-2 list-disc pl-5 text-ink-muted">
                @foreach ($pt['voided_rows'] as $row)
                    <li>{{ $row->treatment_name_snapshot }} — dibatalkan {{ $row->voided_at?->format('d M Y H:i') }}: {{ $row->void_reason }}</li>
                @endforeach
            </ul>
        </details>
    @endif

    @error('performed_treatment')<p class="mt-2 text-sm text-danger" data-dpt-error>{{ $message }}</p>@enderror

    @if ($pt['can_author'])
        <div class="mt-4 grid grid-cols-1 gap-3 border-t border-hairline pt-4 md:grid-cols-2">
            <form method="POST" action="{{ route('rme.visits.performed-treatments.declaration', $clinicVisit) }}">
                @csrf
                <input type="hidden" name="declaration_type" value="has_treatment" />
                <x-ui.button size="sm" type="submit" :variant="$pt['declaration_type'] === 'has_treatment' ? 'primary' : 'secondary'">Ada Tindakan</x-ui.button>
            </form>
            <form method="POST" action="{{ route('rme.visits.performed-treatments.declaration', $clinicVisit) }}" class="space-y-2">
                @csrf
                <input type="hidden" name="declaration_type" value="no_treatment" />
                <label class="flex items-start gap-2 text-sm text-ink">
                    <input type="checkbox" name="confirm_no_treatment" value="1" class="mt-1 rounded border-hairline" />
                    <span>Saya menyatakan tidak ada tindakan medis yang dilakukan pada kunjungan ini (kategori operasional KONTROL).</span>
                </label>
                @error('confirm_no_treatment')<p class="text-sm text-danger">{{ $message }}</p>@enderror
                <x-ui.button size="sm" type="submit" :variant="$pt['declaration_type'] === 'no_treatment' ? 'primary' : 'secondary'">Tidak Ada Tindakan</x-ui.button>
            </form>
        </div>

        @if ($pt['state'] !== 'NO_TREATMENT_DECLARED')
            <form method="POST" action="{{ route('rme.visits.performed-treatments.store', $clinicVisit) }}"
                  class="mt-4 grid grid-cols-1 gap-3 border-t border-hairline pt-4 md:grid-cols-4">
                @csrf
                <input type="hidden" name="submission_token" value="{{ $pt['submission_token'] }}" />
                <div class="md:col-span-2">
                    <label class="mb-1 block text-sm font-medium text-ink">Tindakan</label>
                    <select name="treatment_id" required
                            class="w-full rounded-lg border border-hairline px-3 py-2 text-sm focus:border-brand-500 focus:ring-brand-100">
                        <option value="">— pilih tindakan —</option>
                        @foreach ($pt['treatments'] as $treatment)
                            <option value="{{ $treatment->id }}">{{ $treatment->code }} — {{ $treatment->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-ink">Jumlah</label>
                    <input type="number" name="quantity" value="1" min="1" max="{{ $pt['max_quantity'] }}" required
                           class="w-full rounded-lg border border-hairline px-3 py-2 text-sm" />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-ink">Gigi (FDI, opsional)</label>
                    <select name="tooth_numbers[]" multiple size="4"
                            class="w-full rounded-lg border border-hairline px-3 py-2 text-sm">
                        @foreach ($pt['fdi_numbers'] as $fdi)
                            <option value="{{ $fdi }}">{{ $fdi }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="md:col-span-4">
                    <x-ui.button size="sm" type="submit">+ Tambah Tindakan</x-ui.button>
                    @error('treatment_id')<p class="mt-1 text-sm text-danger">{{ $message }}</p>@enderror
                    @error('quantity')<p class="mt-1 text-sm text-danger">{{ $message }}</p>@enderror
                    @error('tooth_numbers')<p class="mt-1 text-sm text-danger">{{ $message }}</p>@enderror
                </div>
            </form>
        @endif
    @else
        <p class="mt-3 text-xs text-ink-muted">
            Hanya dokter yang menangani kunjungan ini yang dapat mencatat, selama pemeriksaan berlangsung dan setelah
            Persetujuan Tindakan Medis ditandatangani. Setelah "Selesai Pemeriksaan" catatan ini hanya-baca.
        </p>
    @endif
</x-ui.card>
</div>
