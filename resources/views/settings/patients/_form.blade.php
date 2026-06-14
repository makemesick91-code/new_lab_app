@php
    $patient = $patient ?? null;
    $rmeBranches = $rmeBranches ?? collect();
    $defaultYear = old('registered_at', optional($patient?->registered_at)->format('Y-m-d'))
        ? \Carbon\Carbon::parse(old('registered_at', optional($patient?->registered_at)->format('Y-m-d')))->format('Y')
        : now()->format('Y');
@endphp

<div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
    <div>
        <label class="block text-sm font-medium text-gray-700">Klinik</label>
        <select name="clinic_id" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
            <option value="">- Pilih klinik -</option>
            @foreach ($clinics as $clinic)
                <option value="{{ $clinic->id }}" @selected((int) old('clinic_id', $patient?->clinic_id) === $clinic->id)>{{ $clinic->name }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700">Dokter</label>
        <select name="doctor_id" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
            <option value="">- Pilih dokter -</option>
            @foreach ($doctors as $doctor)
                <option value="{{ $doctor->id }}" @selected((int) old('doctor_id', $patient?->doctor_id) === $doctor->id)>{{ $doctor->name }}</option>
            @endforeach
        </select>
    </div>
    {{-- Identitas RME / Nomor Rekam Medis (Sprint 23 Phase 23.8) --}}
    <div class="sm:col-span-2 rounded-lg border border-teal-100 bg-teal-50/40 p-4">
        <p class="text-xs font-semibold uppercase tracking-wide text-teal-700 mb-1">Identitas Rekam Medis (RME)</p>
        <p class="mb-3 text-xs text-gray-500">Nomor RM final dibentuk otomatis dari format: <span class="font-mono">RM DG-{KODE_CABANG}-{TAHUN_DAFTAR}-{NOMOR_RM_MANUAL}</span>. Nomor RM manual diisi oleh admin.</p>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div>
                <label class="block text-sm font-medium text-gray-700">Cabang</label>
                <select name="branch_id" data-rm-branch class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                    <option value="" data-code="">- Pilih cabang RME -</option>
                    @foreach ($rmeBranches as $branch)
                        <option value="{{ $branch->id }}" data-code="{{ $branch->code }}" @selected((int) old('branch_id', $patient?->branch_id) === $branch->id)>{{ $branch->code }} — {{ $branch->name }}</option>
                    @endforeach
                </select>
                @error('branch_id')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">Tanggal Daftar</label>
                <input type="date" name="registered_at" data-rm-date value="{{ old('registered_at', optional($patient?->registered_at)->format('Y-m-d') ?: now()->format('Y-m-d')) }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                @error('registered_at')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">Nomor RM Manual</label>
                <input type="text" name="manual_rm_number" data-rm-manual inputmode="numeric" value="{{ old('manual_rm_number', $patient?->manual_rm_number) }}" placeholder="mis. 0001" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                @error('manual_rm_number')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
            </div>
        </div>
        <div class="mt-3">
            <label class="block text-sm font-medium text-gray-700">Nomor RM Final (Preview)</label>
            <input type="text" data-rm-preview readonly value="{{ $patient?->medical_record_number }}" placeholder="RM DG-{KODE_CABANG}-{TAHUN_DAFTAR}-{NOMOR_RM_MANUAL}" class="mt-1 block w-full rounded-md border-gray-200 bg-gray-50 font-mono text-sm text-gray-700" />
            <p class="mt-1 text-xs text-gray-500">Preview dibentuk dari Cabang + Tahun Daftar + Nomor RM manual. Nilai final divalidasi unik saat disimpan.</p>
        </div>
        @if ($patient && $patient->medical_record_number)
            <p class="mt-2 text-xs text-gray-500">Nomor RM tersimpan saat ini: <span class="font-mono">{{ $patient->medical_record_number }}</span>. Tidak akan berubah kecuali Anda mengubah komponen identitas di atas.</p>
        @endif
        {{-- Legacy / override langsung: pasien lama dengan format nomor lama --}}
        <details class="mt-3">
            <summary class="cursor-pointer text-xs font-medium text-gray-500">Override Nomor RM manual penuh (pasien lama / format lama)</summary>
            <input type="text" name="medical_record_number" value="{{ old('medical_record_number', $patient && $patient->manual_rm_number ? null : $patient?->medical_record_number) }}" placeholder="Isi penuh hanya untuk pasien lama / format lama" class="mt-2 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" />
            @error('medical_record_number')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
            <p class="mt-1 text-xs text-gray-400">Jika diisi, nilai ini dipakai apa adanya dan format RM DG di atas diabaikan.</p>
        </details>
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700">Nama</label>
        <input type="text" name="name" value="{{ old('name', $patient?->name) }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" />
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700">Jenis Kelamin</label>
        <select name="gender" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
            <option value="">—</option>
            @foreach (['Male', 'Female', 'Other'] as $gender)
                <option value="{{ $gender }}" @selected(old('gender', $patient?->gender) === $gender)>{{ ['Male' => 'Laki-laki', 'Female' => 'Perempuan', 'Other' => 'Lainnya'][$gender] }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700">Tanggal Lahir</label>
        <input type="date" name="date_of_birth" value="{{ old('date_of_birth', optional($patient?->date_of_birth)->format('Y-m-d')) }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" />
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700">Nomor KTP</label>
        <input type="text" name="ktp_number" inputmode="numeric" maxlength="16" value="{{ old('ktp_number', $patient?->ktp_number) }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" />
        @error('ktp_number')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
        <p class="mt-1 text-xs text-gray-500">Digunakan untuk validasi identitas pasien. Tidak ditampilkan di RME/cetak.</p>
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700">Ponsel</label>
        <input type="text" name="phone" value="{{ old('phone', $patient?->phone) }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" />
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700">Nomor WA</label>
        <input type="text" name="whatsapp_number" value="{{ old('whatsapp_number', $patient?->whatsapp_number) }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" />
        @error('whatsapp_number')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700">E-Mail</label>
        <input type="email" name="email" value="{{ old('email', $patient?->email) }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" />
        @error('email')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700">Pekerjaan</label>
        <input type="text" name="occupation" value="{{ old('occupation', $patient?->occupation) }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" />
    </div>
    <div class="sm:col-span-2">
        <label class="block text-sm font-medium text-gray-700">Alamat</label>
        <textarea name="address" rows="2" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">{{ old('address', $patient?->address) }}</textarea>
    </div>
    <div class="flex items-center">
        <input type="hidden" name="is_active" value="0" />
        <input type="checkbox" id="is_active" name="is_active" value="1" @checked(old('is_active', $patient?->is_active ?? true)) class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
        <label for="is_active" class="ml-2 text-sm text-gray-700">Aktif</label>
    </div>
</div>

<script>
    (function () {
        const branch = document.querySelector('[data-rm-branch]');
        const dateEl = document.querySelector('[data-rm-date]');
        const manual = document.querySelector('[data-rm-manual]');
        const preview = document.querySelector('[data-rm-preview]');
        if (!branch || !preview) return;

        const recompute = function () {
            const code = (branch.selectedOptions[0]?.dataset.code || '').trim().toUpperCase();
            const year = (dateEl?.value || '').slice(0, 4);
            const num = (manual?.value || '').trim();
            preview.value = (code && year && num) ? `RM DG-${code}-${year}-${num}` : '';
        };

        [branch, dateEl, manual].forEach(el => el && el.addEventListener('input', recompute));
        branch.addEventListener('change', recompute);
        recompute();
    })();
</script>
