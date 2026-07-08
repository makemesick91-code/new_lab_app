@php
    $visit = $visit ?? null;
    $treatments = $treatments ?? collect();
    $rmeBranches = $rmeBranches ?? collect();
    $patientMode = old('patient_mode', 'existing');
    $prefill = $prefill ?? [];
    $visitType = old('visit_type', $prefill['visit_type'] ?? \App\Modules\ClinicVisit\Models\ClinicVisit::VISIT_TYPE_NEW);
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
        <div class="sm:col-span-2">
            <label class="block text-sm font-medium text-ink">Jenis Kunjungan <span class="text-danger">*</span></label>
            <select name="visit_type" required data-visit-type class="mt-1 block w-full rounded-lg border-hairline text-sm focus:border-brand-500 focus:ring-brand-500">
                @foreach ([
                    \App\Modules\ClinicVisit\Models\ClinicVisit::VISIT_TYPE_NEW => 'Baru',
                    \App\Modules\ClinicVisit\Models\ClinicVisit::VISIT_TYPE_CONTROL => 'Kontrol',
                    \App\Modules\ClinicVisit\Models\ClinicVisit::VISIT_TYPE_CONTINUED_TREATMENT => 'Lanjutan Tindakan',
                    \App\Modules\ClinicVisit\Models\ClinicVisit::VISIT_TYPE_EMERGENCY => 'Emergency',
                ] as $typeValue => $typeLabel)
                    <option value="{{ $typeValue }}" @selected($visitType === $typeValue)>{{ $typeLabel }}</option>
                @endforeach
            </select>
            @error('visit_type')<p class="mt-1 text-xs text-danger-700">{{ $message }}</p>@enderror
        </div>

        <div class="sm:col-span-2 hidden" data-follow-up-panel>
            <label class="block text-sm font-medium text-ink">Kunjungan Sebelumnya <span class="text-danger">*</span></label>
            <select name="follow_up_of_visit_id" data-follow-up-select class="mt-1 block w-full rounded-lg border-hairline text-sm focus:border-brand-500 focus:ring-brand-500">
                <option value="">- Pilih kunjungan sebelumnya -</option>
            </select>
            <p class="mt-1 text-xs text-ink-soft" data-follow-up-hint>Pilih pasien terdaftar terlebih dahulu untuk melihat riwayat kunjungan.</p>
            @error('follow_up_of_visit_id')<p class="mt-1 text-xs text-danger-700">{{ $message }}</p>@enderror
        </div>
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

            {{-- Mode: pasien terdaftar --}}
            <div class="mt-3" data-mode-panel="existing">
                <x-patient-search-select
                    :patients="$patients"
                    :selected="$prefillPatientId"
                    label="Pasien Terdaftar"
                />
                @error('patient_id')<p class="mt-1 text-xs text-danger-700">{{ $message }}</p>@enderror
            </div>

            {{-- Mode: pasien baru --}}
            <div class="mt-3 hidden" data-mode-panel="new">
                <p class="mb-2 text-xs text-ink-soft">Nomor RM final dibentuk otomatis: <span class="font-mono">DG-{KODE_CABANG}-{TAHUN_DAFTAR}-{NOMOR_RM_MANUAL}</span>. Nomor RM manual diisi oleh admin.</p>
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
                        <label class="block text-sm font-medium text-ink">Tanggal Daftar</label>
                        <input type="date" name="new_patient[registered_at]" data-rm-date value="{{ old('new_patient.registered_at', now()->format('Y-m-d')) }}" class="mt-1 block w-full rounded-lg border-hairline text-sm focus:border-brand-500 focus:ring-brand-500" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-ink">Nomor RM Manual</label>
                        <input type="text" name="new_patient[manual_rm_number]" data-rm-manual inputmode="numeric" value="{{ old('new_patient.manual_rm_number') }}" placeholder="mis. 0001" class="mt-1 block w-full rounded-lg border-hairline text-sm focus:border-brand-500 focus:ring-brand-500" />
                        @error('new_patient.manual_rm_number')<p class="mt-1 text-xs text-danger-700">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-ink">Jenis Kelamin <span class="text-ink-muted">(opsional)</span></label>
                        <select name="new_patient[gender]" class="mt-1 block w-full rounded-lg border-hairline text-sm focus:border-brand-500 focus:ring-brand-500">
                            <option value="">—</option>
                            @foreach (['Male' => 'Laki-laki', 'Female' => 'Perempuan', 'Other' => 'Lainnya'] as $val => $label)
                                <option value="{{ $val }}" @selected(old('new_patient.gender') === $val)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-ink">Tanggal Lahir <span class="text-ink-muted">(opsional)</span></label>
                        <input type="date" name="new_patient[date_of_birth]" value="{{ old('new_patient.date_of_birth') }}" class="mt-1 block w-full rounded-lg border-hairline text-sm focus:border-brand-500 focus:ring-brand-500" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-ink">Telepon <span class="text-ink-muted">(opsional)</span></label>
                        <input type="text" name="new_patient[phone]" value="{{ old('new_patient.phone') }}" class="mt-1 block w-full rounded-lg border-hairline text-sm focus:border-brand-500 focus:ring-brand-500" />
                        @error('new_patient.phone')<p class="mt-1 text-xs text-danger-700">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-ink">Nomor WA <span class="text-ink-muted">(opsional)</span></label>
                        <input type="text" name="new_patient[whatsapp_number]" value="{{ old('new_patient.whatsapp_number') }}" class="mt-1 block w-full rounded-lg border-hairline text-sm focus:border-brand-500 focus:ring-brand-500" />
                        <p class="mt-1 text-xs text-ink-soft">Dipakai untuk konfirmasi kehadiran kunjungan dan tindak lanjut piutang. Sistem tidak mengirim pesan WhatsApp otomatis.</p>
                        @error('new_patient.whatsapp_number')<p class="mt-1 text-xs text-danger-700">{{ $message }}</p>@enderror
                    </div>
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
                        <p class="mt-1 text-xs text-ink-soft">Digunakan untuk validasi identitas pasien. Tidak ditampilkan di RME/cetak.</p>
                        @error('new_patient.ktp_number')<p class="mt-1 text-xs text-danger-700">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-ink">E-Mail <span class="text-ink-muted">(opsional)</span></label>
                        <input type="email" name="new_patient[email]" value="{{ old('new_patient.email') }}" class="mt-1 block w-full rounded-lg border-hairline text-sm focus:border-brand-500 focus:ring-brand-500" />
                        @error('new_patient.email')<p class="mt-1 text-xs text-danger-700">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-ink">Pekerjaan <span class="text-ink-muted">(opsional)</span></label>
                        <input type="text" name="new_patient[occupation]" value="{{ old('new_patient.occupation') }}" class="mt-1 block w-full rounded-lg border-hairline text-sm focus:border-brand-500 focus:ring-brand-500" />
                        @error('new_patient.occupation')<p class="mt-1 text-xs text-danger-700">{{ $message }}</p>@enderror
                    </div>
                    <div class="sm:col-span-2">
                        <label class="block text-sm font-medium text-ink">Alamat Lengkap <span class="text-ink-muted">(opsional)</span></label>
                        <textarea name="new_patient[address]" rows="2" class="mt-1 block w-full rounded-lg border-hairline text-sm focus:border-brand-500 focus:ring-brand-500">{{ old('new_patient.address') }}</textarea>
                        @error('new_patient.address')<p class="mt-1 text-xs text-danger-700">{{ $message }}</p>@enderror
                    </div>
                    {{-- Sprint 61.1.1 — reuse the Sprint 61.1 direct KTP scanner section
                         inside the RME "Pasien Baru" panel. The hidden ktp_scan_token is
                         only attached when this new-patient form is submitted. --}}
                    @include('settings.patients._ktp-scan')
                    <div class="sm:col-span-2">
                        <label class="block text-sm font-medium text-ink">Nomor RM Final (Preview)</label>
                        <input type="text" data-rm-preview readonly placeholder="DG-{KODE_CABANG}-{TAHUN_DAFTAR}-{NOMOR_RM_MANUAL}" class="mt-1 block w-full rounded-lg border-hairline bg-navy-50 font-mono text-sm text-ink" />
                    </div>
                </div>
            </div>
        </div>
    @else
        <div>
            <x-patient-search-select
                :patients="$patients"
                :selected="old('patient_id', $visit?->patient_id)"
                label="Pasien"
            />
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
        const dateEl = block.querySelector('[data-rm-date]');
        const manual = block.querySelector('[data-rm-manual]');
        const preview = block.querySelector('[data-rm-preview]');
        const visitTypeSelect = document.querySelector('[data-visit-type]');
        const followUpPanel = document.querySelector('[data-follow-up-panel]');
        const followUpSelect = document.querySelector('[data-follow-up-select]');
        const followUpHint = document.querySelector('[data-follow-up-hint]');
        const patientSelect = document.querySelector('[data-patient-select]');
        const prefillFollowUpId = @json($prefillFollowUpId);
        const patientOptionsUrl = @json(route('rme.visits.patient-options'));

        const followUpTypes = ['control', 'continued_treatment'];

        const applyMode = function (mode) {
            modeInput.value = mode;
            panels.existing?.classList.toggle('hidden', mode !== 'existing');
            panels.new?.classList.toggle('hidden', mode !== 'new');
            syncVisitTypeOptions();
            syncFollowUpPanel();
        };

        const syncVisitTypeOptions = function () {
            if (!visitTypeSelect) return;
            const isNewPatient = modeInput.value === 'new';
            Array.from(visitTypeSelect.options).forEach((option) => {
                if (followUpTypes.includes(option.value)) {
                    option.disabled = isNewPatient;
                }
            });
            if (isNewPatient && followUpTypes.includes(visitTypeSelect.value)) {
                visitTypeSelect.value = 'new';
            }
        };

        const renderFollowUpOptions = function (visits) {
            if (!followUpSelect) return;
            const current = followUpSelect.value || String(prefillFollowUpId || '');
            followUpSelect.innerHTML = '<option value="">- Pilih kunjungan sebelumnya -</option>';
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
                followUpSelect.innerHTML = '<option value="">- Pilih kunjungan sebelumnya -</option>';
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
                        ? 'Pilih kunjungan sebelumnya yang akan dijadikan acuan kontrol.'
                        : 'Tidak ada riwayat kunjungan sebelumnya.';
                }
            } catch (e) {
                if (followUpHint) {
                    followUpHint.textContent = 'Gagal memuat riwayat kunjungan.';
                }
            }
        };

        const syncFollowUpPanel = function () {
            if (!visitTypeSelect || !followUpPanel) return;
            const show = followUpTypes.includes(visitTypeSelect.value) && modeInput.value === 'existing';
            followUpPanel.classList.toggle('hidden', !show);
            if (show) {
                loadFollowUpOptions();
            }
        };

        radios.forEach(r => r.addEventListener('change', () => applyMode(r.value)));
        visitTypeSelect?.addEventListener('change', syncFollowUpPanel);
        patientSelect?.addEventListener('change', () => {
            if (followUpTypes.includes(visitTypeSelect?.value || '')) {
                loadFollowUpOptions();
            }
        });

        const recompute = function () {
            if (!preview) return;
            const code = (
                branch?.selectedOptions?.[0]?.dataset.code
                || branch?.dataset?.code
                || ''
            ).trim().toUpperCase();
            const year = (dateEl?.value || '').slice(0, 4);
            const num = (manual?.value || '').trim();
            preview.value = (code && year && num) ? `DG-${code}-${year}-${num}` : '';
        };

        [branch, dateEl, manual].forEach(el => el && el.addEventListener('input', recompute));
        branch && branch.addEventListener('change', recompute);

        applyMode(modeInput.value || 'existing');
        recompute();
        syncFollowUpPanel();
        if (prefillFollowUpId && followUpTypes.includes(visitTypeSelect?.value || '')) {
            loadFollowUpOptions();
        }

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
