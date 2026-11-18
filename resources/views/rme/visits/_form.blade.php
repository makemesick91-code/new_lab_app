@php
    $visit = $visit ?? null;
    $treatments = $treatments ?? collect();
    $rmeBranches = $rmeBranches ?? collect();
    $patientMode = old('patient_mode', 'existing');
    $prefill = $prefill ?? [];
    $prefillFollowUpId = old('follow_up_of_visit_id', $prefill['follow_up_of_visit_id'] ?? null);
    $prefillPatientId = old('patient_id', $prefill['patient_id'] ?? $visit?->patient_id);
    $prefillBranchId = old('branch_id', $prefill['branch_id'] ?? $visit?->branch_id);
    $lockedBranchId = $lockedBranchId ?? null;
    $noOnlineDoctors = $noOnlineDoctors ?? false;
    $hideDoctorSelection = $hideDoctorSelection ?? false;
@endphp

<div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
    <div>
        @if ($lockedBranchId && ! $visit)
            @php $lockedBranch = $rmeBranches->firstWhere('id', $lockedBranchId); @endphp
            <input type="hidden" name="branch_id" value="{{ $lockedBranchId }}">
            <div class="rounded-lg border border-brand-100 bg-brand-50/50 px-3 py-2">
                <p class="text-xs font-semibold uppercase tracking-wide text-brand-700">Cabang Kunjungan</p>
                <p class="mt-1 text-sm font-medium text-navy">
                    {{ $lockedBranch?->code }} — {{ $lockedBranch?->name }}
                </p>
                <p class="mt-1 text-xs text-ink-soft">Mengikuti konteks kerja admin klinik Anda.</p>
            </div>
        @else
            <label class="block text-sm font-medium text-ink">Klinik/Cabang <span class="text-ink-muted">(Cabang RME)</span> <span class="text-danger">*</span></label>
            <select name="branch_id" required data-visit-branch class="mt-1 block w-full rounded-lg border-hairline text-sm focus:border-brand-500 focus:ring-brand-500">
                <option value="">- Pilih cabang RME -</option>
                @foreach ($rmeBranches as $branch)
                    <option value="{{ $branch->id }}" @selected((int) $prefillBranchId === $branch->id)>{{ $branch->code }} — {{ $branch->name }}</option>
                @endforeach
            </select>
        @endif
        @error('branch_id')<p class="mt-1 text-xs text-danger-700">{{ $message }}</p>@enderror
        <p class="mt-1 text-xs text-ink-muted">Untuk pasien baru, Cabang RME pasien otomatis mengikuti Klinik/Cabang kunjungan.</p>
    </div>

    @if (! $visit)
        {{-- REVISION-RME-VISIT-DETAIL-INVOICE-REGISTRATION-UX-1 — "Jenis
             Kunjungan" is no longer an operator choice. The server derives it
             (StoreClinicVisitRequest): Kontrol when a prior visit is selected
             below, Baru otherwise. The prior-visit picker is the only input that
             can make a visit Kontrol, it is optional, and it exists only for a
             registered patient — a new patient has no prior visit to follow. --}}
        <div class="sm:col-span-2 {{ $patientMode === 'existing' ? '' : 'hidden' }}" data-follow-up-panel>
            <label class="block text-sm font-medium text-ink">Kunjungan Sebelumnya (Kontrol) <span class="text-ink-muted">(opsional)</span></label>
            <select name="follow_up_of_visit_id" data-follow-up-select class="mt-1 block w-full rounded-lg border-hairline text-sm focus:border-brand-500 focus:ring-brand-500">
                <option value="">- Bukan kunjungan kontrol (Kunjungan Baru) -</option>
            </select>
            <p class="mt-1 text-xs text-ink-soft" data-follow-up-hint>Pilih pasien terdaftar terlebih dahulu untuk melihat riwayat kunjungan.</p>
            <p class="mt-1 text-xs text-ink-muted">Pilih kunjungan sebelumnya hanya jika kunjungan ini adalah kontrol. Jika dikosongkan, kunjungan dicatat sebagai Kunjungan Baru.</p>
            @error('follow_up_of_visit_id')<p class="mt-1 text-xs text-danger-700">{{ $message }}</p>@enderror
        </div>

        {{-- The derived type can still be refused (e.g. a prior visit submitted
             with a new-patient registration). Its message is rendered here, in a
             place that is always visible, because there is no longer a type
             field to hang it on. --}}
        @error('visit_type')
            <div class="sm:col-span-2">
                <p class="rounded-lg border border-danger-100 bg-danger-50 px-3 py-2 text-xs text-danger-700">{{ $message }}</p>
            </div>
        @enderror
    @endif

    @if (! $visit)
        {{-- Pilih pasien terdaftar ATAU daftarkan pasien baru (Sprint 23 Phase 23.8) --}}
        <div class="sm:col-span-2 rounded-lg border border-brand-100 bg-brand-50/40 p-4" data-patient-block>
            <input type="hidden" name="patient_mode" data-patient-mode value="{{ $patientMode }}" />
            <div class="flex flex-wrap items-center gap-4">
                <span class="text-xs font-semibold uppercase tracking-wide text-brand-700">Pasien</span>
                <label class="inline-flex items-center gap-2 text-sm text-ink">
                    <input type="radio" name="patient_mode_radio" value="existing" @checked($patientMode === 'existing') data-mode-radio class="text-brand-600 focus:ring-brand-500" />
                    Pasien Terdaftar
                </label>
                <label class="inline-flex items-center gap-2 text-sm text-ink">
                    <input type="radio" name="patient_mode_radio" value="new" @checked($patientMode === 'new') data-mode-radio class="text-brand-600 focus:ring-brand-500" />
                    Pasien Baru
                </label>
            </div>

            {{-- Mode: pasien terdaftar.
                 REVISION-NEW-VISIT-PATIENT-SEARCH-COMBOBOX-1 — ONE searchable
                 control. The previous pair (a filter box plus a native select
                 preloaded with every patient) is gone; results now come from the
                 authorized, branch-scoped, bounded server search. --}}
            <div class="mt-3" data-mode-panel="existing">
                <x-patient-combobox
                    :endpoint="route('rme.visits.patient-search')"
                    :selected="$prefillPatientId"
                    label="Pasien Terdaftar"
                    required
                />
                @error('patient_id')<p class="mt-1 text-xs text-danger-700">{{ $message }}</p>@enderror
            </div>

            {{-- Mode: pasien baru --}}
            <div class="mt-3 hidden" data-mode-panel="new">
                {{-- Rule 244 (memo M4.1, "Auto, read-only") — the server issues the
                     Nomor RM on save, on the clinic calendar; nothing is typed. --}}
                @php $clinicalYear = app(\App\Support\Clinical\ClinicalClock::class)->today()->format('Y'); @endphp
                <p class="mb-2 text-xs text-ink-soft">Nomor RM dibuat otomatis oleh sistem saat kunjungan disimpan, menurut cabang dan tahun daftar (kalender klinik).</p>
                <div class="mb-3">
                    @include('patient-merge.partials.registration-duplicates', ['reasonField' => 'new_patient[duplicate_override_reason]', 'useRoute' => 'rme.visits.create', 'useParam' => 'patient_id'])
                </div>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div>
                        <label class="block text-sm font-medium text-ink">Nama Pasien</label>
                        <input type="text" name="new_patient[name]" value="{{ old('new_patient.name') }}" class="mt-1 block w-full rounded-lg border-hairline text-sm focus:border-brand-500 focus:ring-brand-500" />
                        @error('new_patient.name')<p class="mt-1 text-xs text-danger-700">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-ink">Cabang RME</label>
                        @if ($lockedBranchId)
                            @php $lockedNewPatientBranch = $rmeBranches->firstWhere('id', $lockedBranchId); @endphp
                            <input type="hidden" name="new_patient[branch_id]" value="{{ $lockedBranchId }}" data-rm-branch data-code="{{ $lockedNewPatientBranch?->code }}">
                            <p class="mt-1 rounded-lg border border-hairline bg-navy-50 px-3 py-2 text-sm text-ink">
                                {{ $lockedNewPatientBranch?->code }} — {{ $lockedNewPatientBranch?->name }}
                            </p>
                        @else
                            <select name="new_patient[branch_id]" data-rm-branch class="mt-1 block w-full rounded-lg border-hairline text-sm focus:border-brand-500 focus:ring-brand-500">
                                <option value="" data-code="">- Pilih cabang RME -</option>
                                @foreach ($rmeBranches as $branch)
                                    <option value="{{ $branch->id }}" data-code="{{ $branch->code }}" @selected((int) old('new_patient.branch_id') === $branch->id)>{{ $branch->code }} — {{ $branch->name }}</option>
                                @endforeach
                            </select>
                        @endif
                        @error('new_patient.branch_id')<p class="mt-1 text-xs text-danger-700">{{ $message }}</p>@enderror
                        <p class="mt-1 text-xs text-ink-muted">Harus sama dengan Klinik/Cabang kunjungan di atas.</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-ink">Nomor RM</label>
                        <input type="text" readonly tabindex="-1" data-rm-preview data-rm-year="{{ $clinicalYear }}" value="otomatis — DG-{KODE}-{{ $clinicalYear }}-####" class="mt-1 block w-full rounded-lg border-hairline bg-navy-50 font-mono text-sm text-ink" />
                        @error('medical_record_number')<p class="mt-1 text-xs text-danger-700">{{ $message }}</p>@enderror
                        <p class="mt-1 text-xs text-ink-muted">Nomor ditampilkan setelah disimpan.</p>
                    </div>
                    {{-- Memo M4.2 (rule 244 §5) — the core fields of a new patient,
                         enforced by the server with the same rules as Master Data
                         → Tambah Pasien. Never a native `required`: this panel is
                         hidden on an existing-patient visit, and a browser refuses
                         a hidden required control silently (rule 184 §13). --}}
                    <p class="sm:col-span-2 text-xs text-ink-soft">Kolom bertanda <span class="text-danger-700">*</span> wajib diisi untuk pasien baru (Ponsel atau Nomor WA, salah satu). NIK dan foto KTP tidak wajib.</p>
                    <div>
                        <label class="block text-sm font-medium text-ink">Jenis Kelamin <span class="text-danger-700" data-core-field-required>*</span></label>
                        <select name="new_patient[gender]" class="mt-1 block w-full rounded-lg border-hairline text-sm focus:border-brand-500 focus:ring-brand-500">
                            <option value="">—</option>
                            @foreach (['Male' => 'Laki-laki', 'Female' => 'Perempuan', 'Other' => 'Lainnya'] as $val => $label)
                                <option value="{{ $val }}" @selected(old('new_patient.gender') === $val)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('new_patient.gender')<p class="mt-1 text-xs text-danger-700">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-ink">Tempat Lahir <span class="text-danger-700" data-core-field-required>*</span></label>
                        <input type="text" name="new_patient[place_of_birth]" maxlength="100" value="{{ old('new_patient.place_of_birth') }}" class="mt-1 block w-full rounded-lg border-hairline text-sm focus:border-brand-500 focus:ring-brand-500" />
                        @error('new_patient.place_of_birth')<p class="mt-1 text-xs text-danger-700">{{ $message }}</p>@enderror
                        @include('settings.patients._ktp-place-of-birth-suggestion')
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-ink">Tanggal Lahir <span class="text-danger-700" data-core-field-required>*</span></label>
                        <input type="date" name="new_patient[date_of_birth]" value="{{ old('new_patient.date_of_birth') }}" class="mt-1 block w-full rounded-lg border-hairline text-sm focus:border-brand-500 focus:ring-brand-500" />
                        @error('new_patient.date_of_birth')<p class="mt-1 text-xs text-danger-700">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-ink">Telepon <span class="text-danger-700" data-core-field-required>*</span></label>
                        <input type="text" name="new_patient[phone]" value="{{ old('new_patient.phone') }}" class="mt-1 block w-full rounded-lg border-hairline text-sm focus:border-brand-500 focus:ring-brand-500" />
                        @error('new_patient.phone')<p class="mt-1 text-xs text-danger-700">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-ink">Nomor WA <span class="text-danger-700">*</span></label>
                        <input type="text" name="new_patient[whatsapp_number]" value="{{ old('new_patient.whatsapp_number') }}" class="mt-1 block w-full rounded-lg border-hairline text-sm focus:border-brand-500 focus:ring-brand-500" />
                        <p class="mt-1 text-xs text-ink-soft">Dipakai untuk konfirmasi kehadiran kunjungan dan tindak lanjut piutang. Sistem tidak mengirim pesan WhatsApp otomatis.</p>
                        @error('new_patient.whatsapp_number')<p class="mt-1 text-xs text-danger-700">{{ $message }}</p>@enderror
                    </div>
                    {{-- Rule 244 §5.3 (plan default 2) — the no-phone exception,
                         rendered ONLY for a holder of register_patient_without_phone
                         (rule 135 mirror; anyone else posting it gets 403). The
                         reason fields stay disabled, so unsent, until ticked. --}}
                    @can('register_patient_without_phone')
                        <div class="sm:col-span-2 rounded-lg border border-warning-100 bg-warning-50/60 p-3"
                             x-data="{ noPhone: {{ old('new_patient.no_phone_reason_code') ? 'true' : 'false' }} }">
                            <label class="inline-flex items-center gap-2 text-sm font-medium text-ink">
                                <input type="checkbox" x-model="noPhone" @checked(old('new_patient.no_phone_reason_code')) class="rounded border-hairline text-brand-600 focus:ring-brand-500" />
                                Pasien tidak memiliki nomor ponsel/WhatsApp
                            </label>
                            <p class="mt-1 text-xs text-ink-soft">Pengecualian ini dicatat di audit (tanpa data pasien) dan tertutup otomatis saat nomor diisi kemudian.</p>
                            <div class="mt-2 grid grid-cols-1 gap-3 sm:grid-cols-2" x-show="noPhone" @if (! old('new_patient.no_phone_reason_code')) style="display: none;" @endif>
                                <div>
                                    <label class="block text-sm font-medium text-ink">Alasan</label>
                                    <select name="new_patient[no_phone_reason_code]" :disabled="!noPhone" @disabled(! old('new_patient.no_phone_reason_code')) class="mt-1 block w-full rounded-lg border-hairline text-sm focus:border-brand-500 focus:ring-brand-500">
                                        <option value="">- Pilih alasan -</option>
                                        @foreach (config('patient.no_phone_reasons', []) as $reasonCode => $reasonLabel)
                                            <option value="{{ $reasonCode }}" @selected(old('new_patient.no_phone_reason_code') === $reasonCode)>{{ $reasonLabel }}</option>
                                        @endforeach
                                    </select>
                                    @error('new_patient.no_phone_reason_code')<p class="mt-1 text-xs text-danger-700">{{ $message }}</p>@enderror
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-ink">Catatan (10–500 karakter; wajib untuk Lainnya)</label>
                                    <textarea name="new_patient[no_phone_reason_note]" rows="2" maxlength="500" :disabled="!noPhone" @disabled(! old('new_patient.no_phone_reason_code')) class="mt-1 block w-full rounded-lg border-hairline text-sm focus:border-brand-500 focus:ring-brand-500"></textarea>
                                    <p class="mt-1 text-xs text-ink-soft">Jangan menulis data identitas pasien (NIK, alamat) di catatan. Catatan hanya dapat dibaca Supervisor RME dan Owner, dan tidak diisi ulang bila formulir ditolak.</p>
                                    @error('new_patient.no_phone_reason_note')<p class="mt-1 text-xs text-danger-700">{{ $message }}</p>@enderror
                                </div>
                            </div>
                        </div>
                    @endcan
                    <div>
                        <label class="block text-sm font-medium text-ink">Nomor KTP <span class="text-ink-muted">(opsional)</span></label>
                        <input
                            type="text"
                            name="new_patient[ktp_number]"
                            inputmode="numeric"
                            maxlength="16"
                            value="{{ old('new_patient.ktp_number') }}"
                            class="mt-1 block w-full rounded-lg border-hairline text-sm focus:border-brand-500 focus:ring-brand-500"
                        />
                        <p class="mt-1 text-xs text-ink-soft">Jika diisi, 16 digit angka. Digunakan untuk validasi identitas pasien. Tidak ditampilkan di RME/cetak.</p>
                        @error('new_patient.ktp_number')<p class="mt-1 text-xs text-danger-700">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-ink">E-Mail <span class="text-ink-muted">(opsional)</span></label>
                        <input type="email" name="new_patient[email]" value="{{ old('new_patient.email') }}" class="mt-1 block w-full rounded-lg border-hairline text-sm focus:border-brand-500 focus:ring-brand-500" />
                        @error('new_patient.email')<p class="mt-1 text-xs text-danger-700">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-ink">Pekerjaan <span class="text-danger-700" data-core-field-required>*</span></label>
                        <input type="text" name="new_patient[occupation]" value="{{ old('new_patient.occupation') }}" class="mt-1 block w-full rounded-lg border-hairline text-sm focus:border-brand-500 focus:ring-brand-500" />
                        @error('new_patient.occupation')<p class="mt-1 text-xs text-danger-700">{{ $message }}</p>@enderror
                    </div>
                    <div class="sm:col-span-2">
                        <label class="block text-sm font-medium text-ink">Alamat Lengkap <span class="text-danger-700" data-core-field-required>*</span></label>
                        <textarea name="new_patient[address]" rows="2" class="mt-1 block w-full rounded-lg border-hairline text-sm focus:border-brand-500 focus:ring-brand-500">{{ old('new_patient.address') }}</textarea>
                        @error('new_patient.address')<p class="mt-1 text-xs text-danger-700">{{ $message }}</p>@enderror
                    </div>
                    {{-- Sprint 61.1.1 — reuse the Sprint 61.1 direct KTP scanner section
                         inside the RME "Pasien Baru" panel. The hidden ktp_scan_token is
                         only attached when this new-patient form is submitted. --}}
                    @include('settings.patients._ktp-scan', ['ktpFieldPrefix' => 'new_patient'])
                </div>
            </div>
        </div>
    @endif
    @if ($hideDoctorSelection)
        <div class="sm:col-span-2 rounded-lg border border-brand-100 bg-brand-50/50 px-3 py-2">
            <p class="text-xs font-semibold uppercase tracking-wide text-brand-700">Dokter</p>
            <p class="mt-1 text-sm text-ink">Dokter akan otomatis dipilih berdasarkan ruangan yang diassign pada halaman antrian.</p>
        </div>
    @else
    <div>
        <label class="block text-sm font-medium text-ink">Dokter <span class="text-danger">*</span></label>
        <select name="doctor_id" required data-visit-doctor class="mt-1 block w-full rounded-lg border-hairline text-sm focus:border-brand-500 focus:ring-brand-500" @disabled($noOnlineDoctors && ($doctors ?? collect())->isEmpty())>
            <option value="">- Pilih dokter -</option>
            @foreach ($doctors ?? [] as $doctor)
                <option value="{{ $doctor->id }}" @selected(old('doctor_id', $visit?->doctor_id) == $doctor->id)>{{ $doctor->name }}</option>
            @endforeach
        </select>
        @error('doctor_id')<p class="mt-1 text-xs text-danger-700">{{ $message }}</p>@enderror
        <p data-visit-doctor-empty class="mt-2 text-xs text-warning-700 @if(! $noOnlineDoctors) hidden @endif">
            Belum ada dokter online di cabang ini. Minta dokter memilih cabang dan ruangan terlebih dahulu.
        </p>
        <p data-visit-doctor-hint class="mt-1 text-xs text-ink-soft @if($noOnlineDoctors) hidden @endif">
            Hanya dokter yang sedang online di cabang kunjungan yang dapat dipilih.
        </p>
    </div>
    @endif
    {{-- Sprint 58.6 — Room selection removed from registration. Admin Klinik now
         assigns a treatment room from the queue (Daftar Kunjungan) before treatment. --}}
    <div class="sm:col-span-2">
        <label class="block text-sm font-medium text-ink">Keluhan Utama <span class="text-ink-muted">(opsional)</span></label>
        <textarea name="chief_complaint" rows="3" class="mt-1 block w-full rounded-lg border-hairline text-sm focus:border-brand-500 focus:ring-brand-500">{{ old('chief_complaint', $visit?->chief_complaint) }}</textarea>
    </div>

    {{-- Initial Service (triage/antrian) — Phase 1.8 --}}
    <div class="sm:col-span-2 border-t border-hairline pt-4">
        <p class="text-xs font-semibold uppercase tracking-wide text-ink-soft mb-3">Layanan Awal (Triase)</p>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
                <label class="block text-sm font-medium text-ink">
                    Tindakan Awal
                    @if (!$visit)
                        <span class="text-danger">*</span>
                    @endif
                </label>
                <select name="initial_treatment_id" class="mt-1 block w-full rounded-lg border-hairline text-sm focus:border-brand-500 focus:ring-brand-500">
                    <option value="">- Pilih tindakan awal -</option>
                    @foreach ($treatments as $treatment)
                        <option value="{{ $treatment->id }}" @selected(old('initial_treatment_id', $visit?->initial_treatment_id) == $treatment->id)>
                            {{ $treatment->name }}
                        </option>
                    @endforeach
                </select>
                @error('initial_treatment_id')
                    <p class="mt-1 text-xs text-danger-700">{{ $message }}</p>
                @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-ink">Catatan Layanan Awal <span class="text-ink-muted">(opsional)</span></label>
                <textarea name="initial_service_note" rows="2" class="mt-1 block w-full rounded-lg border-hairline text-sm focus:border-brand-500 focus:ring-brand-500">{{ old('initial_service_note', $visit?->initial_service_note) }}</textarea>
            </div>
        </div>
        <p class="mt-2 text-xs text-ink-muted">Tindakan awal hanya untuk konteks triase/antrian, bukan tagihan akhir.</p>
    </div>
</div>

@if (! $visit)
<script>
    (function () {
        const block = document.querySelector('[data-patient-block]');
        if (!block) return;

        const modeInput = block.querySelector('[data-patient-mode]');
        const radios = block.querySelectorAll('[data-mode-radio]');
        const panels = {
            existing: block.querySelector('[data-mode-panel="existing"]'),
            new: block.querySelector('[data-mode-panel="new"]'),
        };
        const branch = block.querySelector('[data-rm-branch]');
        const preview = block.querySelector('[data-rm-preview]');
        const followUpPanel = document.querySelector('[data-follow-up-panel]');
        const followUpSelect = document.querySelector('[data-follow-up-select]');
        const followUpHint = document.querySelector('[data-follow-up-hint]');
        const patientSelect = document.querySelector('[data-patient-select]');
        const prefillFollowUpId = @json($prefillFollowUpId);
        const patientOptionsUrl = @json(route('rme.visits.patient-options'));

        // REVISION-RME-VISIT-DETAIL-INVOICE-REGISTRATION-UX-1 — there is no visit
        // type select any more; the prior-visit picker alone decides Kontrol, and
        // the server re-derives it on store regardless of what is submitted here.
        const emptyFollowUpOption = '<option value="">- Bukan kunjungan kontrol (Kunjungan Baru) -</option>';

        const applyMode = function (mode) {
            modeInput.value = mode;
            panels.existing?.classList.toggle('hidden', mode !== 'existing');
            panels.new?.classList.toggle('hidden', mode !== 'new');

            // REVISION-NEW-VISIT-PATIENT-SEARCH-COMBOBOX-1 — leaving "Pasien
            // Terdaftar" clears the selected patient, aborts any pending search
            // and empties the box. A hidden panel still submits its inputs, so
            // this is what stops a stale patient_id riding along with a new
            // patient registration. The server nulls it too (defence in depth).
            if (mode !== 'existing') {
                window.dispatchEvent(new CustomEvent('patient-selector-reset'));
            }

            // A new patient has no prior visit. Clear the picker so a stale
            // selection cannot ride along with the hidden panel; if one did, the
            // server would refuse it rather than register a Kontrol.
            if (mode !== 'existing' && followUpSelect) {
                followUpSelect.innerHTML = emptyFollowUpOption;
                followUpSelect.value = '';
            }

            syncFollowUpPanel();
        };

        const renderFollowUpOptions = function (visits) {
            if (!followUpSelect) return;
            const current = followUpSelect.value || String(prefillFollowUpId || '');
            followUpSelect.innerHTML = emptyFollowUpOption;
            visits.forEach((visit) => {
                const option = document.createElement('option');
                option.value = String(visit.id);
                const treatment = visit.initial_treatment ? ` — ${visit.initial_treatment}` : '';
                option.textContent = `${visit.visit_number} (${visit.visit_date}) — ${visit.doctor_name || '—'}${treatment} — ${visit.status}`;
                if (String(visit.id) === current) {
                    option.selected = true;
                }
                followUpSelect.appendChild(option);
            });
        };

        const loadFollowUpOptions = async function () {
            if (!followUpSelect || !patientSelect) return;
            const patientId = patientSelect.value;
            if (!patientId) {
                followUpSelect.innerHTML = emptyFollowUpOption;
                if (followUpHint) {
                    followUpHint.textContent = 'Pilih pasien terdaftar terlebih dahulu untuk melihat riwayat kunjungan.';
                }
                return;
            }

            if (followUpHint) {
                followUpHint.textContent = 'Memuat riwayat kunjungan...';
            }

            try {
                const response = await fetch(`${patientOptionsUrl}?patient_id=${encodeURIComponent(patientId)}`, {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                if (!response.ok) throw new Error('fetch failed');
                const data = await response.json();
                renderFollowUpOptions(data.visits || []);
                if (followUpHint) {
                    followUpHint.textContent = (data.visits || []).length
                        ? 'Pilih kunjungan sebelumnya hanya jika kunjungan ini adalah kontrol.'
                        : 'Tidak ada riwayat kunjungan sebelumnya.';
                }
            } catch (e) {
                if (followUpHint) {
                    followUpHint.textContent = 'Gagal memuat riwayat kunjungan.';
                }
            }
        };

        const syncFollowUpPanel = function () {
            if (!followUpPanel) return;
            const show = modeInput.value === 'existing';
            followUpPanel.classList.toggle('hidden', !show);
            if (show) {
                loadFollowUpOptions();
            }
        };

        radios.forEach(r => r.addEventListener('change', () => applyMode(r.value)));
        patientSelect?.addEventListener('change', () => {
            if (modeInput.value === 'existing') {
                loadFollowUpOptions();
            }
        });

        // Rule 244 §2.8 — no number is previewed (two operators would see the
        // same one); only the branch code fills the automatic pattern.
        const recompute = function () {
            if (!preview) return;
            const code = (
                branch?.selectedOptions?.[0]?.dataset.code
                || branch?.dataset?.code
                || ''
            ).trim().toUpperCase();
            preview.value = `otomatis — DG-${code || '{KODE}'}-${preview.dataset.rmYear || ''}-####`;
        };

        branch && branch.addEventListener('change', recompute);

        applyMode(modeInput.value || 'existing');
        recompute();
        // Loads the prior visits of a prefilled/selected registered patient, so a
        // "Buat Kontrol" link (or a re-rendered form after a validation error)
        // lands with its prior visit already selected.
        syncFollowUpPanel();

        const visitBranchSelect = document.querySelector('[data-visit-branch]');
        const newPatientBranchSelect = document.querySelector('[data-rm-branch]');

        const syncNewPatientBranchWithVisitBranch = () => {
            if (!visitBranchSelect || !newPatientBranchSelect) {
                return;
            }

            if (modeInput?.value === 'new' && visitBranchSelect.value) {
                newPatientBranchSelect.value = visitBranchSelect.value;
                newPatientBranchSelect.dispatchEvent(new Event('change', { bubbles: true }));
            }
        };

        visitBranchSelect?.addEventListener('change', () => {
            syncNewPatientBranchWithVisitBranch();
            @if (! $hideDoctorSelection)
            loadOnlineDoctors();
            @endif
        });
        document.querySelectorAll('[data-mode-radio]').forEach((radio) => {
            radio.addEventListener('change', syncNewPatientBranchWithVisitBranch);
        });

        syncNewPatientBranchWithVisitBranch();

        @if (! $hideDoctorSelection)
        const onlineDoctorsUrl = @json(route('rme.visits.online-doctors'));
        const doctorSelect = document.querySelector('[data-visit-doctor]');
        const doctorEmpty = document.querySelector('[data-visit-doctor-empty]');
        const doctorHint = document.querySelector('[data-visit-doctor-hint]');

        const resolveVisitBranchId = function () {
            if (visitBranchSelect?.value) {
                return visitBranchSelect.value;
            }

            const hiddenBranch = document.querySelector('input[name="branch_id"][type="hidden"]');

            return hiddenBranch?.value || '';
        };

        const loadOnlineDoctors = async function () {
            const branchId = resolveVisitBranchId();

            if (!doctorSelect || !branchId) {
                return;
            }

            try {
                const response = await fetch(`${onlineDoctorsUrl}?branch_id=${encodeURIComponent(branchId)}`, {
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });

                if (!response.ok) {
                    return;
                }

                const payload = await response.json();
                const current = doctorSelect.value;
                doctorSelect.innerHTML = '<option value="">- Pilih dokter -</option>';
                (payload.doctors || []).forEach((doctor) => {
                    const option = document.createElement('option');
                    option.value = String(doctor.id);
                    option.textContent = doctor.name;
                    if (String(doctor.id) === current) {
                        option.selected = true;
                    }
                    doctorSelect.appendChild(option);
                });

                const isEmpty = (payload.doctors || []).length === 0;
                doctorSelect.disabled = isEmpty;
                doctorEmpty?.classList.toggle('hidden', !isEmpty);
                doctorHint?.classList.toggle('hidden', isEmpty);
            } catch (error) {
                // Keep the server-rendered list when the lookup fails.
            }
        };

        loadOnlineDoctors();
        @endif
    })();
</script>
@endif
