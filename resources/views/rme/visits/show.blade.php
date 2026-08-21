<x-settings-shell title="Detail Kunjungan">
    @php
        $statusLabels = [
            'registered'      => 'Terdaftar',
            'waiting'         => 'Menunggu',
            'in_progress'     => 'Dalam Pemeriksaan',
            'cashier_pending' => 'Menunggu Kasir',
            'completed'       => 'Selesai Visit',
            'cancelled'       => 'Dibatalkan',
        ];
        // Status badge tone is resolved by x-ui.badge :status (UIX design-system
        // status map) — no local tone map needed (UIX-4).
        // Sprint 62.1 — the doctor/front office can advance examination to the
        // cashier ("Selesai Pemeriksaan" = cashier_pending) but never to
        // `completed`; "Selesai Visit" is reached only after the cashier settles
        // the invoice, so no manual `completed` button is rendered.
        $transitionLabels = [
            'waiting'         => 'Check-in',
            'in_progress'     => 'Mulai Pemeriksaan',
            'cashier_pending' => 'Selesai Pemeriksaan',
            'cancelled'       => 'Batalkan',
        ];
        // UIX-4 — transition CTAs map to design-system x-ui.button variants.
        // Gold is never used here (accent-only, never a clinical action colour).
        $transitionVariant = [
            'waiting'         => 'warning',
            'in_progress'     => 'primary',
            'cashier_pending' => 'success',
            'cancelled'       => 'danger',
        ];
        // FIX-CLINIC-OPS-BRANCH-CONTEXT-WA-1 (FIX-07), as amended by
        // FIX-RME-CONSENT-WORKFLOW-PRINT-UX-2 (FIX-04) — Admin Klinik's visit detail
        // is read-only; it registers and places patients from Antrian Pasien
        // instead. "Cetak RME" no longer lives here: it moved to the Rekam Medis
        // page, which the front office reaches through the navigation link below.
        // Every hidden control is also denied server-side by its own ability, so
        // this is presentation, never the boundary.
        $canOperateVisit = auth()->user()?->can('operateFromDetail', $visit) ?? false;
        // FIX-05 — "Selesai Pemeriksaan" additionally needs clinical authority.
        $canCompleteExamination = auth()->user()?->can('completeExamination', $visit) ?? false;
        $validNextStatuses = collect(\App\Modules\ClinicVisit\Models\ClinicVisit::VALID_TRANSITIONS[$visit->status] ?? [])
            ->reject(fn ($status) => $status === \App\Modules\ClinicVisit\Models\ClinicVisit::STATUS_COMPLETED)
            ->reject(fn ($status) => $status === \App\Modules\ClinicVisit\Models\ClinicVisit::STATUS_CASHIER_PENDING
                && ! $canCompleteExamination)
            ->all();
    @endphp

    <div class="space-y-6">
        <x-ui.page-header
            title="Detail Kunjungan"
            :subtitle="'Antrian #' . $visit->queue_number . ' · ' . ($visit->visit_date?->format('d/m/Y') ?? '—')">
            <x-slot:breadcrumb>
                <a href="{{ route('rme.visits.index') }}" class="font-medium text-brand-700 hover:text-brand-800">Kunjungan</a>
                <span class="px-1 text-ink-muted">/</span>
                <span class="font-mono text-ink">{{ $visit->visit_number }}</span>
            </x-slot:breadcrumb>
            <x-slot:actions>
                <x-ui.badge :status="$visit->status">{{ $statusLabels[$visit->status] ?? $visit->status }}</x-ui.badge>
                @if ($canOperateVisit)
                @can('transition', $visit)
                    @foreach ($validNextStatuses as $nextStatus)
                        <form method="POST" action="{{ route('rme.visits.transition', $visit) }}">
                            @csrf
                            <input type="hidden" name="status" value="{{ $nextStatus }}">
                            <x-ui.button type="submit" :variant="$transitionVariant[$nextStatus] ?? 'neutral'">
                                {{ $transitionLabels[$nextStatus] ?? $nextStatus }}
                            </x-ui.button>
                        </form>
                    @endforeach
                @endcan
                @if ($visit->status !== \App\Modules\ClinicVisit\Models\ClinicVisit::STATUS_COMPLETED && $visit->status !== \App\Modules\ClinicVisit\Models\ClinicVisit::STATUS_CANCELLED)
                    @can('update', $visit)
                        <x-ui.button variant="secondary" :href="route('rme.visits.edit', $visit)">Ubah</x-ui.button>
                    @endcan
                @endif
                @endif
                {{-- FIX-RME-CONSENT-WORKFLOW-PRINT-UX-2 / FIX-04 — "Cetak RME" no
                     longer lives on Detail Kunjungan. It now belongs to the Rekam
                     Medis workflow and is rendered in the Medical Record page header,
                     next to Finalisasi. This SUPERSEDES the FIX-CLINIC-OPS (FIX-07)
                     rule that Admin Klinik's visit detail is "read-only plus Cetak
                     RME".

                     The front office keeps the capability rather than losing it: a
                     user who may print but may not operate the visit has no Rekam
                     Medis card (that card is behind $canOperateVisit), so without a
                     path here they could no longer reach the print action at all.
                     They get a NAVIGATION link — not a second print button — gated by
                     the very ability that decides whether they may print. Operators
                     already have the Rekam Medis card, so they get no duplicate. --}}
                @if (! $canOperateVisit)
                    @can('print', $visit)
                        <x-ui.button variant="secondary" :href="route('rme.visits.medical-record.show', $visit)">
                            Rekam Medis
                        </x-ui.button>
                    @endcan
                @endif
                @if ($canOperateVisit)
                @can('create', \App\Modules\ClinicVisit\Models\ClinicVisit::class)
                    <x-ui.button variant="primary" :href="route('rme.visits.create', [
                        'patient_id' => $visit->patient_id,
                        'visit_type' => \App\Modules\ClinicVisit\Models\ClinicVisit::VISIT_TYPE_CONTROL,
                        'follow_up_of_visit_id' => $visit->id,
                        'branch_id' => $visit->branch_id,
                    ])">Buat Kontrol</x-ui.button>
                @endcan
                @endif
            </x-slot:actions>
        </x-ui.page-header>

        @if ($visit->status === \App\Modules\ClinicVisit\Models\ClinicVisit::STATUS_COMPLETED)
            <x-ui.alert variant="success">
                Kunjungan telah selesai, tidak ada aksi perubahan status tersedia.
            </x-ui.alert>
        @elseif ($visit->status === \App\Modules\ClinicVisit\Models\ClinicVisit::STATUS_CANCELLED)
            <x-ui.alert variant="danger">
                Kunjungan telah dibatalkan, tidak ada aksi perubahan status tersedia.
            </x-ui.alert>
        @endif

        {{-- FIX-RME-EXAM-CONSENT-ODONTOGRAM-HISTORY-3 / FIX-02 — the consent gate,
             shown at the moment it is actually actionable.

             It used to appear only at cashier_pending, because consent was the
             PAYMENT gate. Consent is now taken at the START of the examination and
             is what unlocks writing this visit's RME, so it is surfaced for the
             whole live encounter and the doctor meets it before charting rather
             than the cashier meeting it at the counter.

             Presentation only — RmeVisitConsentService is the authority. --}}
        {{-- CORRECTIVE-01 — consent becomes actionable ONLY once the doctor has
             explicitly started the examination. Before that there is no decided
             treatment to consent to; after it, signing would be after the fact. --}}
        @if ($visit->status === \App\Modules\ClinicVisit\Models\ClinicVisit::STATUS_IN_PROGRESS)
            @php $visitSignedConsent = $visit->consents()->whereNull('voided_at')->latest('signed_at')->first(); @endphp

            @if ($visitSignedConsent === null)
                <x-ui.alert variant="warning" title="Persetujuan Tindakan Medis belum ditandatangani">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <p class="max-w-xl text-sm">
                            Rekam medis kunjungan ini belum dapat ditulis sampai Surat Persetujuan
                            Tindakan Medis ditandatangani pasien atau pemberi persetujuan.
                            Riwayat klinis pasien tetap dapat dibaca.
                        </p>
                        @can('create', [\App\Modules\Consent\Models\RmeVisitConsent::class, $visit])
                            <x-ui.button variant="primary" :href="route('rme.visits.consent.create', $visit)">
                                Pilih Form Consent
                            </x-ui.button>
                        @endcan
                    </div>
                </x-ui.alert>
            @else
                <x-ui.alert variant="success" title="Persetujuan Tindakan Medis sudah ditandatangani">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <p class="max-w-xl text-sm">
                            {{ $visitSignedConsent->consent_number }} &middot;
                            {{ $visitSignedConsent->signed_at?->format('d/m/Y H:i') }}.
                            Penulisan rekam medis kunjungan ini sudah terbuka.
                        </p>
                        @can('view', $visitSignedConsent)
                            <x-ui.button variant="secondary" :href="route('rme.consents.show', $visitSignedConsent)">
                                Lihat Persetujuan
                            </x-ui.button>
                        @endcan
                    </div>
                </x-ui.alert>
            @endif
        @endif

        {{-- Hotfix Sprint 60.8 — room-assignment gate. An active visit must be
             placed into a treatment room before the doctor can examine. --}}
        @if ($visit->requiresRoomBeforeExam())
            <x-ui.alert variant="warning" title="Menunggu Penempatan Ruangan">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <p class="max-w-xl text-sm">
                        Pasien belum ditempatkan ke ruangan perawatan. Dokter belum dapat memulai pemeriksaan
                        sebelum ruangan dipilih.
                    </p>
                    @if (! $canOperateVisit)
                        <span class="text-xs">Penempatan ruangan dilakukan dari halaman <span class="font-medium">Antrian Pasien</span>.</span>
                    @endif
                    @if ($canOperateVisit)
                    @can('update', $visit)
                        @if (($rooms ?? collect())->isNotEmpty())
                            <form method="POST" action="{{ route('rme.visits.assign-room', $visit) }}"
                                  class="flex flex-wrap items-center gap-1.5">
                                @csrf
                                @method('PATCH')
                                <select name="clinic_room_id"
                                        class="min-w-[10rem] rounded-lg border-hairline text-sm text-ink focus:border-brand-500 focus:ring-brand-500">
                                    <option value="">- Pilih ruangan -</option>
                                    @foreach ($rooms as $room)
                                        <option value="{{ $room->id }}">{{ $room->name }}</option>
                                    @endforeach
                                </select>
                                <x-ui.button type="submit" variant="warning" size="sm">Tempatkan Ruangan</x-ui.button>
                            </form>
                        @else
                            <span class="text-xs">Belum ada ruangan aktif pada cabang ini.</span>
                        @endif
                    @endcan
                    @endif
                </div>
            </x-ui.alert>
        @endif

        <x-ui.card title="Informasi Kunjungan">
            <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Pasien</dt>
                    <dd class="mt-1 text-sm text-gray-900">{{ $visit->patient?->name ?? '—' }}</dd>
                    @if ($visit->patient?->medical_record_number)
                        <dd class="mt-0.5 font-mono text-xs text-gray-500">{{ $visit->patient->medical_record_number }}</dd>
                    @endif
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Ponsel</dt>
                    <dd class="mt-1 text-sm text-gray-900">{{ $visit->patient?->phone ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Nomor WA</dt>
                    <dd class="mt-1 text-sm text-gray-900">{{ $visit->patient?->whatsapp_number ?? '—' }}</dd>
                    <dd class="mt-0.5 text-xs text-gray-500">Konfirmasi kehadiran &amp; tindak lanjut piutang. Tidak ada kiriman WhatsApp otomatis.</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Persetujuan Tindakan Medis</dt>
                    <dd class="mt-1 text-sm">
                        @if ($visit->hasSignedConsentDocument())
                            <x-ui.badge tone="success">Sudah ditandatangani</x-ui.badge>
                        @else
                            <x-ui.badge tone="warning">Belum ditandatangani</x-ui.badge>
                        @endif
                    </dd>
                    {{-- FIX-RME-EXAM-CONSENT-ODONTOGRAM-HISTORY-3 / FIX-02 — the signed
                         document is what unlocks RME authoring for this visit. It is no
                         longer verified by the cashier and is no longer a payment
                         condition. --}}
                    <dd class="mt-1 text-xs text-gray-500">Ditandatangani pasien/pemberi persetujuan saat pemeriksaan dokter. Membuka penulisan RME kunjungan ini.</dd>
                </div>
                @if ($visit->patient?->date_of_birth)
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Umur</dt>
                        <dd class="mt-1 text-sm text-gray-900">{{ $visit->patient->age() ?? '—' }} tahun</dd>
                    </div>
                @endif
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Dokter</dt>
                    <dd class="mt-1 text-sm text-gray-900">{{ $visit->doctor?->name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Klinik/Cabang</dt>
                    <dd class="mt-1 text-sm text-gray-900">
                        @if ($visit->branch)
                            {{ $visit->branch->code }} — {{ $visit->branch->name }}
                        @else
                            {{ $visit->clinic?->name ?? '—' }}
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Ruangan</dt>
                    <dd class="mt-1 text-sm text-gray-900">{{ $visit->clinicRoom?->name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Jenis Kunjungan</dt>
                    <dd class="mt-1 text-sm text-gray-900">{{ $visit->visitTypeLabel() }}</dd>
                </div>
                @if ($visit->followUpOf)
                    <div class="sm:col-span-2">
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Kontrol dari</dt>
                        <dd class="mt-1 text-sm">
                            <a href="{{ route('rme.visits.show', $visit->followUpOf) }}" class="font-mono text-brand-700 hover:text-brand-800">{{ $visit->followUpOf->visit_number }}</a>
                            <span class="text-gray-500"> — {{ $visit->followUpOf->visit_date?->format('d/m/Y') }}</span>
                        </dd>
                    </div>
                @endif
                @if ($visit->followUpVisits->isNotEmpty())
                    <div class="sm:col-span-2">
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Kontrol lanjutan</dt>
                        <dd class="mt-1 text-sm text-gray-900">
                            <ul class="list-disc pl-5 space-y-1">
                                @foreach ($visit->followUpVisits as $followUpVisit)
                                    <li>
                                        <a href="{{ route('rme.visits.show', $followUpVisit) }}" class="font-mono text-brand-700 hover:text-brand-800">{{ $followUpVisit->visit_number }}</a>
                                        <span class="text-gray-500"> — {{ $followUpVisit->visitTypeLabel() }} ({{ $followUpVisit->visit_date?->format('d/m/Y') }})</span>
                                    </li>
                                @endforeach
                            </ul>
                        </dd>
                    </div>
                @endif
                <div class="sm:col-span-2">
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Keluhan Utama</dt>
                    <dd class="mt-1 text-sm text-gray-900">{{ $visit->chief_complaint ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Tindakan Awal</dt>
                    <dd class="mt-1 text-sm text-gray-900">{{ $visit->initialTreatment?->name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Catatan Layanan Awal</dt>
                    <dd class="mt-1 text-sm text-gray-900 whitespace-pre-wrap">{{ $visit->initial_service_note ?? '—' }}</dd>
                </div>
            </dl>
        </x-ui.card>

        @include('rme.visits.partials.doctor-rm-access-panel', [
            'doctorAccessSummary' => $doctorAccessSummary ?? [],
        ])

        @if ($visit->check_in_at || $visit->started_at || $visit->completed_at || $visit->cancelled_at)
            <x-ui.card title="Linimasa Kunjungan">
                <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Check-in</dt>
                        <dd class="mt-1 text-sm text-gray-900">{{ $visit->check_in_at?->format('d/m/Y H:i') ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Mulai Pemeriksaan</dt>
                        <dd class="mt-1 text-sm text-gray-900">{{ $visit->started_at?->format('d/m/Y H:i') ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Selesai</dt>
                        <dd class="mt-1 text-sm text-gray-900">{{ $visit->completed_at?->format('d/m/Y H:i') ?? '—' }}</dd>
                    </div>
                    @if ($visit->status === \App\Modules\ClinicVisit\Models\ClinicVisit::STATUS_CANCELLED && $visit->cancelled_at)
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Dibatalkan</dt>
                            <dd class="mt-1 text-sm text-gray-900">{{ $visit->cancelled_at->format('d/m/Y H:i') }}</dd>
                        </div>
                    @endif
                </dl>
            </x-ui.card>
        @endif

        {{-- FIX-CLINIC-OPS-BRANCH-CONTEXT-WA-1 (FIX-07) — the clinical surfaces
             (Rekam Medis, Odontogram, Resep) belong to the treating clinician.
             Admin Klinik's visit detail is read-only; each of these routes is
             independently authorised server-side as well. Since FIX-04, "Cetak RME"
             lives on the Rekam Medis page rather than here. --}}
        @if ($canOperateVisit)
        {{-- Rekam Medis --}}
        @php $medicalRecord = $visit->medicalRecord; @endphp
        <x-ui.card title="Rekam Medis">
            @if ($visit->requiresRoomBeforeExam())
                <p class="text-sm text-warning-700">
                    Pemeriksaan terkunci — pasien belum ditempatkan ke ruangan perawatan.
                </p>
            @elseif ($medicalRecord)
                <div class="flex flex-wrap items-center gap-3">
                    <x-ui.badge :tone="$medicalRecord->status === \App\Modules\MedicalRecord\Models\MedicalRecord::STATUS_FINAL ? 'success' : 'warning'">
                        {{ $medicalRecord->status === \App\Modules\MedicalRecord\Models\MedicalRecord::STATUS_FINAL ? 'Final' : 'Draft' }}
                    </x-ui.badge>
                    <x-ui.button variant="primary" :href="route('rme.visits.medical-record.show', $visit)">
                        Lihat Rekam Medis
                    </x-ui.button>
                </div>
            @else
                <p class="text-sm text-gray-500 mb-3">Rekam medis belum dibuat.</p>
                @if ($visit->status !== \App\Modules\ClinicVisit\Models\ClinicVisit::STATUS_CANCELLED)
                    @can('create', [\App\Modules\MedicalRecord\Models\MedicalRecord::class, $visit])
                        <form method="POST" action="{{ route('rme.visits.medical-record.store', $visit) }}">
                            @csrf
                            <x-ui.button type="submit" variant="primary">Buat Rekam Medis</x-ui.button>
                        </form>
                    @endcan
                @endif
            @endif
        </x-ui.card>

        {{-- Odontogram --}}
        @can('create', [\App\Modules\Odontogram\Models\Odontogram::class, $visit])
            <x-ui.card title="Odontogram">
                @if ($visit->requiresRoomBeforeExam())
                    <p class="text-sm text-warning-700">
                        Pemeriksaan terkunci — pasien belum ditempatkan ke ruangan perawatan.
                    </p>
                @else
                    <x-ui.button variant="primary" :href="route('rme.visits.odontogram.show', $visit)">
                        Buka Odontogram
                    </x-ui.button>
                    <p class="mt-2 text-xs text-gray-500">Placeholder — Odontogram interaktif akan tersedia di Sprint berikutnya.</p>
                @endif
            </x-ui.card>
        @endcan

        {{-- Resep Dokter --}}
        @can('viewForVisit', [\App\Modules\Prescription\Models\RmePrescription::class, $visit])
            <x-ui.card title="Resep Dokter">
                @if ($visit->requiresRoomBeforeExam())
                    <p class="text-sm text-warning-700">
                        Pemeriksaan terkunci — pasien belum ditempatkan ke ruangan perawatan.
                    </p>
                @else
                    @php $visitPrescription = $visit->rmePrescription; @endphp
                    @if ($visitPrescription)
                        <div class="flex flex-wrap items-center gap-3">
                            <x-ui.badge tone="success">Tersimpan</x-ui.badge>
                            <span class="text-sm text-gray-600">{{ $visitPrescription->prescription_date?->format('d/m/Y') }}</span>
                        </div>
                    @else
                        <p class="text-sm text-gray-500 mb-3">Resep dokter belum dibuat untuk kunjungan ini.</p>
                    @endif
                    <x-ui.button variant="primary" :href="route('rme.visits.prescription.show', $visit)" class="mt-3">
                        {{ $visitPrescription ? 'Lihat Resep Dokter' : 'Buat Resep Dokter' }}
                    </x-ui.button>
                @endif
            </x-ui.card>
        @endcan
        @endif

        @include('rme.visits.partials.patient-visit-history', [
            'patientVisitHistory' => $patientVisitHistory,
            'currentVisitId' => $visit->id,
            'statusLabels' => $statusLabels,
        ])

        {{-- LEGACY-RME-PDF-1C — renders nothing unless the patient has a
             published legacy archive this operator may see. --}}
        @include('rme.visits.partials.patient-rme-timeline', [
            'rmeTimeline' => $rmeTimeline ?? collect(),
        ])

        <div>
            <a href="{{ route('rme.visits.index') }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; Kembali ke daftar</a>
        </div>
    </div>
</x-settings-shell>
