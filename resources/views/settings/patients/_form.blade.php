@php
    $patient = $patient ?? null;
    $rmeBranches = $rmeBranches ?? collect();
    // Rule 244 §2 — the RM year is the clinical year (ClinicalClock), passed by
    // PatientController::create(); the edit form shows the stored number only.
    $clinicalYear = $clinicalYear ?? '';
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
    {{-- Identitas RME / Nomor Rekam Medis — rule 244 (memo M4.1, "Auto, read-only"):
         the server issues the number on save; an edit never changes the number,
         the branch or the registration date. --}}
    <div class="sm:col-span-2 rounded-lg border border-teal-100 bg-teal-50/40 p-4">
        <p class="text-xs font-semibold uppercase tracking-wide text-teal-700 mb-1">Identitas Rekam Medis (RME)</p>
        @if ($patient)
            @php $patientBranch = $rmeBranches->firstWhere('id', $patient->branch_id); @endphp
            <p class="mb-3 text-xs text-gray-500">Nomor RM, cabang, dan tanggal daftar tidak dapat diubah setelah pasien terdaftar.</p>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div>
                    <p class="block text-sm font-medium text-gray-700">Cabang</p>
                    <p class="mt-1 rounded-md border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-700">{{ $patientBranch ? $patientBranch->code.' — '.$patientBranch->name : '—' }}</p>
                </div>
                <div>
                    <p class="block text-sm font-medium text-gray-700">Tanggal Daftar</p>
                    <p class="mt-1 rounded-md border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-700">{{ optional($patient->registered_at)->format('Y-m-d') ?: '—' }}</p>
                </div>
                <div>
                    <p class="block text-sm font-medium text-gray-700">Nomor RM</p>
                    <p class="mt-1 rounded-md border border-gray-200 bg-gray-50 px-3 py-2 font-mono text-sm text-gray-700">{{ $patient->medical_record_number ?: '—' }}</p>
                </div>
            </div>
        @else
            <p class="mb-3 text-xs text-gray-500">Nomor RM dibuat otomatis oleh sistem saat pasien disimpan, menurut cabang dan tahun daftar (kalender klinik). Nomor ditampilkan setelah disimpan.</p>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label class="block text-sm font-medium text-gray-700">Cabang <span class="text-rose-600">*</span></label>
                    <select name="branch_id" required data-rm-branch class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="" data-code="">- Pilih cabang RME -</option>
                        @foreach ($rmeBranches as $branch)
                            <option value="{{ $branch->id }}" data-code="{{ $branch->code }}" @selected((int) old('branch_id') === $branch->id)>{{ $branch->code }} — {{ $branch->name }}</option>
                        @endforeach
                    </select>
                    @error('branch_id')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Nomor RM</label>
                    <input type="text" readonly tabindex="-1" data-rm-auto data-rm-year="{{ $clinicalYear }}" value="otomatis — DG-{KODE}-{{ $clinicalYear }}-####" class="mt-1 block w-full rounded-md border-gray-200 bg-gray-50 font-mono text-sm text-gray-700" />
                    @error('medical_record_number')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                </div>
            </div>
            {{-- Rule 244 §4 (plan default 1) — the Supervisor RME paper-card
                 exception. Rendered ONLY for an account the server would accept
                 it from (rule 135 mirror); anyone else gets the automatic number
                 and a posted value is refused with 403. --}}
            @can('register_patient_paper_card_rm')
                <details class="mt-3 rounded-md border border-amber-200 bg-amber-50/60 p-3" @if ($errors->hasAny(['paper_card_year', 'paper_card_sequence', 'paper_card_reason']) || old('paper_card_sequence')) open @endif>
                    <summary class="cursor-pointer text-xs font-semibold text-amber-800">Pakai Nomor RM kartu kertas lama (khusus Supervisor RME)</summary>
                    <p class="mt-2 text-xs text-gray-600">Hanya untuk pasien yang membawa kartu berobat lama. Nomor dibentuk dari cabang di atas: DG-{KODE}-{TAHUN KARTU}-{NOMOR URUT}, diperiksa terhadap semua penulisan yang sudah terdaftar, dan dicatat di audit tanpa nomornya.</p>
                    <div class="mt-2 grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Tahun pada kartu</label>
                            <input type="text" name="paper_card_year" inputmode="numeric" maxlength="4" value="{{ old('paper_card_year') }}" placeholder="mis. 2024" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                            @error('paper_card_year')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Nomor urut pada kartu</label>
                            <input type="text" name="paper_card_sequence" inputmode="numeric" maxlength="8" value="{{ old('paper_card_sequence') }}" placeholder="mis. 0012" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                            @error('paper_card_sequence')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                        </div>
                        <div class="sm:col-span-3">
                            <label class="block text-sm font-medium text-gray-700">Alasan (10–500 karakter)</label>
                            <textarea name="paper_card_reason" rows="2" maxlength="500" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">{{ old('paper_card_reason') }}</textarea>
                            @error('paper_card_reason')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                        </div>
                    </div>
                </details>
            @endcan
        @endif
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
        <p class="mt-1 text-xs text-gray-500">Dipakai untuk konfirmasi kehadiran kunjungan dan tindak lanjut piutang. Sistem tidak mengirim pesan WhatsApp otomatis.</p>
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
        // Rule 244 §2.8 — no number is previewed (two operators would see the
        // same one); only the selected branch code fills the pattern.
        const branch = document.querySelector('[data-rm-branch]');
        const auto = document.querySelector('[data-rm-auto]');
        if (!branch || !auto) return;

        const render = function () {
            const code = (branch.selectedOptions[0]?.dataset.code || '').trim().toUpperCase();
            auto.value = `otomatis — DG-${code || '{KODE}'}-${auto.dataset.rmYear || ''}-####`;
        };

        branch.addEventListener('change', render);
        render();
    })();
</script>
