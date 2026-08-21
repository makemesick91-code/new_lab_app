<x-settings-shell title="Rekam Medis">
    @php
        $isFinal = $medicalRecord->status === \App\Modules\MedicalRecord\Models\MedicalRecord::STATUS_FINAL;

        // FIX-RME-CONSENT-WORKFLOW-PRINT-UX-2 / FIX-03 — hoisted from the middle of
        // the page so the primary actions (Finalisasi, Cetak RME) can render in the
        // page-header actions slot. Presentation only: every gate below is the same
        // policy/handwriting check as before, evaluated in the same way.
        $isDraft = $medicalRecord->status === \App\Modules\MedicalRecord\Models\MedicalRecord::STATUS_DRAFT;
        $canUpdate = auth()->user()?->can('update', $medicalRecord) ?? false;
        $canFinalize = auth()->user()?->can('finalize', $medicalRecord) ?? false;
        // Sprint 59 — handwriting and notes remain editable after finalization.
        $canEditHandwriting = $canUpdate;
        $savedHandwriting = $medicalRecord->latestHandwriting();
        $hasHandwriting = $hasRequiredHandwriting ?? false;
        $hasLegacySoap = filled($medicalRecord->subjective)
            || filled($medicalRecord->objective)
            || filled($medicalRecord->assessment)
            || filled($medicalRecord->plan);
    @endphp

    <div class="space-y-6">
        <x-ui.page-header
            title="Rekam Medis"
            :subtitle="$clinicVisit->visit_number.' — '.($clinicVisit->visit_date?->format('d/m/Y') ?? '-')"
        >
            <x-slot:breadcrumb>Rekam Medis Elektronik</x-slot:breadcrumb>
            <x-slot:actions>
                <x-ui.badge :tone="$isFinal ? 'success' : 'warning'">
                    {{ $isFinal ? 'Final' : 'Draft' }}
                </x-ui.badge>

                {{-- FIX-RME-CONSENT-WORKFLOW-PRINT-UX-2 / FIX-03 — "Finalisasi" moved
                     here from the very bottom of the page. MOVED, NOT DUPLICATED:
                     there is no second Finalisasi control further down. The action,
                     its route, its policy gate ($canFinalize) and the mandatory
                     handwriting precondition ($hasHandwriting) are all unchanged. --}}
                @if ($isDraft && $canFinalize)
                    @if ($hasHandwriting && ! $consentRequired)
                        <form method="POST" action="{{ route('rme.visits.medical-record.finalize', [$clinicVisit, $medicalRecord]) }}">
                            @csrf
                            <x-ui.button type="submit" variant="success">
                                Finalisasi
                            </x-ui.button>
                        </form>
                    @else
                        {{-- Disabled is presentation only; the server still refuses to
                             finalize without handwriting. The reason is spelled out in
                             the alert directly below the header. --}}
                        <x-ui.button type="button" variant="success" disabled>
                            Finalisasi
                        </x-ui.button>
                    @endif
                @endif

                {{-- FIX-RME-CONSENT-WORKFLOW-PRINT-UX-2 / FIX-04 — "Cetak RME" moved
                     here from Detail Kunjungan, mirroring the "Cetak Odontogram"
                     placement and style on the Odontogram page. It reuses the
                     EXISTING print route; no duplicate print endpoint was created and
                     authorisation stays server-side in ClinicVisitPolicy::print.
                     $clinicVisit is the ACTIVE SHEET's visit (MedicalRecordController
                     binds 'clinicVisit' => $activeSheet->clinicVisit), so this prints
                     exactly the visit whose sheet is on screen. --}}
                @can('print', $clinicVisit)
                    <x-ui.button variant="secondary" :href="route('rme.visits.print', $clinicVisit)" target="_blank">
                        Cetak RME
                    </x-ui.button>
                @endcan

                {{-- FIX-RME-EXAM-CONSENT-ODONTOGRAM-HISTORY-3 / FIX-01 — "Selesai
                     Pemeriksaan" reachable from the page the doctor is actually on.

                     Finalizing a record no longer ends the examination, so without an
                     affordance here a doctor could finalize, leave, and strand the
                     patient at in_progress with the cashier unable to raise an
                     invoice. This is the SAME route and the SAME authorization as the
                     button on Detail Kunjungan (rme.visits.transition +
                     ClinicVisitPolicy::completeExamination) — a second entry point to
                     one guarded action, not a second way to complete a visit. --}}
                @php
                    // The workspace spans the patient's whole RM book, so the active
                    // sheet — and therefore $clinicVisit — changes as the doctor
                    // swipes. Completing an examination is irreversible, so the
                    // control is shown ONLY while the active sheet belongs to the
                    // visit the doctor actually navigated from, and the label always
                    // names that visit. Switching sheets hides it rather than
                    // silently retargeting it at a different visit.
                    $examVisitInFocus = $clinicVisit->id === ($sourceVisit?->id ?? $workspaceVisit->id);
                @endphp
                @if ($examVisitInFocus && $clinicVisit->status === \App\Modules\ClinicVisit\Models\ClinicVisit::STATUS_IN_PROGRESS)
                    @can('completeExamination', $clinicVisit)
                        <form method="POST" action="{{ route('rme.visits.transition', $clinicVisit) }}">
                            @csrf
                            <input type="hidden" name="status"
                                value="{{ \App\Modules\ClinicVisit\Models\ClinicVisit::STATUS_CASHIER_PENDING }}">
                            <x-ui.button type="submit" variant="primary">
                                Selesai Pemeriksaan (Kunjungan #{{ $clinicVisit->visit_number }})
                            </x-ui.button>
                        </form>
                    @endcan
                @endif

                {{-- FIX-PRE-68-45 Scope A — real "Kembali ke Kunjungan" button at the
                     top of the page, matching the Odontogram page placement/style so
                     the doctor reaches the handwriting area faster. --}}
                <x-ui.button variant="secondary" :href="route('rme.visits.show', $clinicVisit)">
                    &larr; Kembali ke Kunjungan
                </x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>

        {{-- FIX-RME-EXAM-CONSENT-ODONTOGRAM-HISTORY-3 / FIX-02 — the RME authoring
             gate for THIS sheet's visit.

             Reading is never blocked: the patient's history, the published legacy
             archive and this record itself stay fully visible, because the doctor
             deciding the treatment needs them. Only WRITING this visit's record
             waits for the signature.

             Presentation only — the server asserts the same gate. --}}
        @if ($consentRequired)
            <x-ui.alert variant="warning" title="Persetujuan Tindakan Medis belum ditandatangani">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <p class="max-w-2xl text-sm">
                        Penulisan rekam medis kunjungan ini terkunci sampai Surat Persetujuan
                        Tindakan Medis ditandatangani. Riwayat rekam medis, arsip RME lama dan
                        odontogram pasien tetap dapat dibaca.
                    </p>
                    @can('create', [\App\Modules\Consent\Models\RmeVisitConsent::class, $consentVisit])
                        <x-ui.button variant="primary" :href="route('rme.visits.consent.create', $consentVisit)">
                            Pilih Form Consent
                        </x-ui.button>
                    @endcan
                </div>
            </x-ui.alert>
        @endif

        {{-- FIX-RME-CONSENT-WORKFLOW-PRINT-UX-2 / FIX-03 — the finalization STATE
             messages travel with the relocated button so the doctor learns why it is
             unavailable without scrolling to the bottom of the record. --}}
        @if ($isDraft && $canFinalize && ! $hasHandwriting)
            <x-ui.alert variant="warning">
                RME belum dapat difinalkan karena catatan tulis tangan dokter belum tersedia.
            </x-ui.alert>
        @elseif (! $isDraft)
            <x-ui.alert variant="success" title="Rekam Medis ini telah difinalkan.">
                Catatan dan tulisan tangan masih dapat diperbarui oleh dokter bila diperlukan.
            </x-ui.alert>
        @endif

        @if ($clinicVisit->isFollowUpVisit())
            <x-ui.alert variant="info">
                <span class="font-medium">Jenis Kunjungan:</span> {{ $clinicVisit->visitTypeLabel() }}
                @if ($clinicVisit->followUpOf)
                    &mdash; <span class="font-medium">Kontrol dari:</span>
                    <a href="{{ route('rme.visits.show', $clinicVisit->followUpOf) }}" class="font-mono underline">{{ $clinicVisit->followUpOf->visit_number }}</a>
                @endif
            </x-ui.alert>
        @endif

        @include('rme.visits.partials.visit-workflow-nav', [
            'clinicVisit' => $clinicVisit,
            'active' => 'medical-record',
        ])

        {{-- Sprint 64.0 — opened-from-later-visit notice (patient-centric workspace). --}}
        @if (! empty($notice))
            <x-ui.alert variant="info">
                {{ $notice }}
            </x-ui.alert>
        @endif

        {{-- FIX-PRE-68-45 Scope A — "Buku RM Pasien" (sheet nav) and "Riwayat
             Pencatatan" were relocated BELOW the handwriting canvas so the doctor
             lands directly on the handwritten RME area with less scrolling. See the
             relocated blocks after the RME Tulisan Tangan card. --}}

        {{-- LEGACY-RME-DOCTOR-WORKSPACE-1 introduced the patient's RME document rail
             (native sheets + PUBLISHED legacy archive) here, above the fold.
             FIX-RME-CONSENT-WORKFLOW-PRINT-UX-2 / FIX-02 moved it to the foot of the
             page: the top of the record must prioritise the active visit and the
             handwritten RME, not the document archive. The rail itself is unchanged
             and still reachable — see the relocated include at the end of this view. --}}

        <x-ui.card title="Informasi Kunjungan">
            {{-- Sprint 59.4 — patient biodata rendered as a compact bordered
                 two-column table (label : value). KTP number is intentionally
                 never shown here, preserving RME privacy rules. Marital status
                 and religion have no column in the pilot schema, so they use the
                 safe "-" fallback (no migration added). --}}
            @php
                $patient = $clinicVisit->patient;
                $bioDash = '-';
                $genderLabels = ['Male' => 'Laki-laki', 'Female' => 'Perempuan', 'Other' => 'Lainnya'];

                // TTL / Umur — birth date (and age) only; the schema has no
                // place-of-birth column, so the place segment is omitted.
                if ($patient?->date_of_birth) {
                    $ttlUmur = $patient->date_of_birth->format('d-m-Y');
                    $patientAge = $patient->age();
                    if ($patientAge !== null) {
                        $ttlUmur .= ' / '.$patientAge.' tahun';
                    }
                } else {
                    $ttlUmur = $bioDash;
                }

                $bioLeft = [
                    ['Nama', $patient?->name ?: $bioDash],
                    ['TTL / Umur', $ttlUmur],
                    ['Pekerjaan', $patient?->occupation ?: $bioDash],
                    ['Status Pernikahan', $patient?->marital_status ?: $bioDash],
                    ['Alamat', $patient?->address ?: $bioDash],
                ];
                $bioRight = [
                    ['Jenis Kelamin', $patient?->gender ? ($genderLabels[$patient->gender] ?? $patient->gender) : $bioDash],
                    ['Agama', $patient?->religion ?: $bioDash],
                    ['No. Tlp / Wa', $patient?->whatsapp_number ?: ($patient?->phone ?: $bioDash)],
                    ['Email', $patient?->email ?: $bioDash],
                    ['No. RM', $patient?->medical_record_number ?: $bioDash],
                ];
            @endphp

            <div class="overflow-x-auto">
                <table class="w-full border-collapse text-sm">
                    <tbody>
                        @foreach (range(0, 4) as $i)
                            <tr class="align-top">
                                <th class="border border-gray-200 bg-gray-50 px-3 py-1.5 text-left font-semibold text-gray-700 whitespace-nowrap">{{ $bioLeft[$i][0] }}</th>
                                <td class="w-1/3 border border-gray-200 px-3 py-1.5 text-gray-900">: {{ $bioLeft[$i][1] }}</td>
                                <th class="border border-gray-200 bg-gray-50 px-3 py-1.5 text-left font-semibold text-gray-700 whitespace-nowrap">{{ $bioRight[$i][0] }}</th>
                                <td class="w-1/3 border border-gray-200 px-3 py-1.5 text-gray-900">: {{ $bioRight[$i][1] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Visit-level context retained below the patient biodata table. --}}
            <dl class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Dokter</dt>
                    <dd class="mt-1 text-sm text-gray-900">{{ $clinicVisit->doctor?->name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Tindakan Awal</dt>
                    <dd class="mt-1 text-sm text-gray-900">{{ $clinicVisit->initialTreatment?->name ?? '—' }}</dd>
                </div>
                @if ($clinicVisit->initial_service_note)
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Catatan Layanan Awal</dt>
                        <dd class="mt-1 text-sm text-gray-900 whitespace-pre-wrap">{{ $clinicVisit->initial_service_note }}</dd>
                    </div>
                @endif
            </dl>
        </x-ui.card>

        {{-- FIX-RME-CONSENT-WORKFLOW-PRINT-UX-2 / FIX-03 — the gating variables that
             used to be computed here are now computed once at the top of this view,
             so the page-header actions can use them. --}}

        {{-- Sprint 59.2 — the typed "Catatan Rekam Medis" notes section is
             removed from the doctor UI. Handwriting RM is the primary clinical
             input, so the typed-notes editor only cluttered the workflow. The
             `notes` column and its update route are kept untouched for
             backward compatibility (print/detail views and the data layer). --}}

        @if ($hasLegacySoap)
            {{-- Legacy SOAP data (read-only; hidden from doctor input workflow) --}}
            <x-ui.card title="Data SOAP (Legacy)">
                <dl class="space-y-4">
                    @if (filled($medicalRecord->subjective))
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Subjective</dt>
                            <dd class="mt-1 text-sm text-gray-900 whitespace-pre-wrap">{{ $medicalRecord->subjective }}</dd>
                        </div>
                    @endif
                    @if (filled($medicalRecord->objective))
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Objective</dt>
                            <dd class="mt-1 text-sm text-gray-900 whitespace-pre-wrap">{{ $medicalRecord->objective }}</dd>
                        </div>
                    @endif
                    @if (filled($medicalRecord->assessment))
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Assessment</dt>
                            <dd class="mt-1 text-sm text-gray-900 whitespace-pre-wrap">{{ $medicalRecord->assessment }}</dd>
                        </div>
                    @endif
                    @if (filled($medicalRecord->plan))
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Plan</dt>
                            <dd class="mt-1 text-sm text-gray-900 whitespace-pre-wrap">{{ $medicalRecord->plan }}</dd>
                        </div>
                    @endif
                </dl>
            </x-ui.card>
        @endif

        {{-- Sprint 60 / 60.1 — Multi-page handwriting RME. 1 canvas = 1 RM page.
             Storage is still multi-page (Page 1 = legacy read-through; Page 2+
             from the additive pages table), but this edit page now loads and
             renders ONLY the single selected RM page canvas to stay fast — all
             other pages are reached via the pagination below (?rm_page=). The
             controller supplies the active page metadata + total count so no
             per-page <img>/canvas is rendered for the non-active pages. KTP is
             never rendered. --}}
        @php
            // Active (selected) page metadata only — the single page whose
            // preview/canvas is loaded on this request.
            $activeSrc = $activeRmPage['preview_url'] ?? '';
            $activeHasContent = $activeRmPage['has_content'] ?? false;
            $activeSavedAt = $activeRmPage['saved_at'] ?? null;
        @endphp

        <x-ui.card
            :title="$canEditHandwriting ? 'RME Tulisan Tangan Lengkap' : 'RME Tulisan Tangan'"
            :description="$canEditHandwriting ? 'Isi Rekam Medis lengkap: setiap halaman = satu kanvas rekam medis. Hanya satu halaman dimuat per layar — gunakan navigasi halaman atau geser kiri/kanan untuk berpindah. Klik halaman aktif untuk menulis. Halaman bertanda L adalah arsip RME lama (hanya baca). Menyimpan satu halaman tidak menghapus halaman lain.' : null"
        >
            {{-- Sprint 64.0.2 — page navigation/pagination for the patient's single
                 handwriting RM book (virtual merge across visits). Numbered buttons
                 link via ?rm_page= (virtual page index). Swipe is scoped to this
                 block only — the drawing canvas ignores swipe gestures. --}}
            @php
                $prevPage = $prevRmPage;
                $nextNavPage = $nextRmPage;
                $navBase = 'inline-flex items-center justify-center rounded-lg border px-3 py-1.5 text-xs font-semibold transition-colors';
                $rmPageBase = array_filter([
                    'sheet' => $activeSheet->id,
                    'source_visit_id' => $sourceVisit?->id,
                ], fn ($v) => $v !== null);
                $rmPageUrl = fn ($page) => route('rme.visits.medical-record.show', array_merge([$workspaceVisit], $rmPageBase, ['rm_page' => $page]));
                $prevSwipeUrl = $prevPage ? $rmPageUrl($prevPage) : '';
                $nextSwipeUrl = $nextNavPage ? $rmPageUrl($nextNavPage) : '';
                $canonicalHandwritingVisit = $handwritingRecord?->clinicVisit ?? $workspaceVisit;
                $canonicalHandwritingFormAction = route('rme.visits.medical-record.handwriting.store', [
                    $canonicalHandwritingVisit,
                    $handwritingRecord ?? $handwritingFormRecord,
                ]);
                $storagePageNumber = $activeRmPage['storage_page_number'] ?? $activePageNumber;
            @endphp

            <div
                id="rm-handwriting-swipe"
                class="mb-4 touch-pan-y"
                data-rm-swipe-zone
                @if ($prevSwipeUrl) data-prev-url="{{ $prevSwipeUrl }}" @endif
                @if ($nextSwipeUrl) data-next-url="{{ $nextSwipeUrl }}" @endif
            >
            @include('rme.visits.medical-record.partials.rm-page-navigator')

            <p class="mb-3 text-xs text-gray-500">Geser kiri/kanan pada area halaman untuk berpindah halaman.</p>
            {{-- Only the ACTIVE page is rendered. LEGACY-RME-DOCTOR-WORKSPACE-1A:
                 the slot holds EITHER the editable native handwriting page OR a
                 read-only legacy archive page — decided by the page's explicit
                 kind, never by its index. --}}
            @if ($isLegacyActivePage && $activeWorkspacePage)
                <div id="rm-page-previews" class="touch-pan-y" data-active-page-type="legacy">
                    @include('rme.visits.medical-record.partials.legacy-archive-page')
                </div>
            @else
            <div id="rm-page-previews" class="mx-auto max-w-md touch-pan-y" data-active-page-type="native">
                <figure
                    class="rm-page-preview group relative rounded-lg border border-gray-300 bg-white p-2 touch-pan-y {{ $canEditHandwriting ? 'cursor-pointer transition hover:border-brand-500 hover:shadow' : '' }}"
                    data-page-number="{{ $activePageNumber }}"
                    data-storage-page="{{ $storagePageNumber }}"
                    data-form-action="{{ route('rme.visits.medical-record.handwriting.store', [$handwritingFormVisit, $handwritingFormRecord]) }}"
                    data-existing-src="{{ $activeSrc }}"
                    @if ($canEditHandwriting) role="button" tabindex="0" @endif
                >
                    <figcaption class="mb-2 flex items-center justify-between text-xs">
                        <span class="font-semibold text-gray-700">Halaman {{ $activePageNumber }}</span>
                        @if ($activeHasContent)
                            <span class="text-gray-500">Tersimpan pada {{ $activeSavedAt?->format('d/m/Y H:i') }}</span>
                        @endif
                    </figcaption>

                    @if ($activeHasContent && $activeSrc)
                        <img src="{{ $activeSrc }}"
                             alt="RME Tulisan Tangan Halaman {{ $activePageNumber }}"
                             class="block w-full rounded border border-gray-200 pointer-events-none select-none"
                             draggable="false"
                             style="aspect-ratio:900/1273;object-fit:contain;" />
                    @else
                        <div class="flex items-center justify-center rounded border border-dashed border-warning-100 bg-warning-50 px-4 py-8 text-center text-sm text-warning-700"
                             style="aspect-ratio:900/1273;">
                            Belum ada handwriting RM. Silakan isi dan simpan tulisan tangan sebelum finalisasi.
                        </div>
                    @endif

                    @if ($canEditHandwriting)
                        <span class="pointer-events-none absolute inset-x-0 bottom-2 mx-auto w-fit rounded bg-black/60 px-2 py-0.5 text-[11px] text-white opacity-0 transition group-hover:opacity-100">
                            Klik untuk menulis
                        </span>
                    @endif
                </figure>
            </div>
            @endif
            </div>{{-- /#rm-handwriting-swipe --}}
            @if ($canEditHandwriting)
                @error('handwriting_data')
                    <p class="mt-2 text-xs text-danger-600">{{ $message }}</p>
                @enderror

                {{-- Same-page overlay editor (relocated Sprint 59 canvas). One
                     shared canvas edits the selected page only; the hidden
                     page_number routes the save to the right RM page row. --}}
                <div id="rm-editor-overlay" class="fixed inset-0 z-50 hidden items-start justify-center overflow-y-auto bg-black/50 p-4" data-ignore-swipe>
                    <div class="my-6 w-full max-w-3xl rounded-xl bg-white p-4 shadow-xl">
                        <div class="mb-3 flex items-center justify-between">
                            <h3 class="text-base font-semibold text-gray-900">
                                Menulis Halaman <span id="editor-page-label">{{ $nativeActivePageNumber }}</span>
                            </h3>
                            <button type="button" id="close-editor-btn" class="rounded-full p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-700" aria-label="Tutup">
                                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                            </button>
                        </div>

                        <p class="mb-2 text-xs text-gray-500">
                            Tulisan tangan tersimpan halaman ini dimuat ke kanvas. Lanjutkan menulis untuk menambah coretan tanpa menghapus yang lama.
                        </p>

                        <form method="POST" action="{{ route('rme.visits.medical-record.handwriting.store', [$handwritingFormVisit, $handwritingFormRecord]) }}" id="handwriting-form">
                            @csrf
                            @if ($sourceVisit)
                                <input type="hidden" name="source_visit_id" value="{{ $sourceVisit->id }}">
                            @endif
                            <input type="hidden" name="handwriting_data" id="handwriting-data-input">
                            <input type="hidden" name="page_number" id="handwriting-page-input" value="{{ $storagePageNumber }}">

                            {{-- Sprint 60 — A4-portrait page canvas (900 x 1273,
                                 ≈ 1:1.414). One canvas = one RM page; overflow
                                 goes to the next page, never an endlessly tall
                                 canvas. max-width:100%;height:auto stays responsive. --}}
                            <canvas id="rme-canvas"
                                    width="900" height="1273"
                                    data-existing-src="{{ $activeSrc }}"
                                    data-ignore-swipe
                                    class="mx-auto block w-full border border-gray-400 rounded-lg cursor-crosshair bg-white touch-none"
                                    style="max-width:100%;height:auto;max-height:70vh;"></canvas>

                            <div class="mt-3 flex flex-wrap items-center gap-3">
                                <x-ui.button type="button" variant="secondary" id="clear-canvas-btn">Reset ke Tulisan Tersimpan</x-ui.button>
                                {{-- FIX-02 — saving handwriting is an RME write, so it waits
                                     for consent exactly like the rest of the record. Disabled
                                     is presentation only; MedicalRecordHandwritingController
                                     asserts the same gate before it stores anything. --}}
                                <x-ui.button type="submit" variant="primary" id="save-handwriting-btn"
                                    :disabled="$consentRequired">Simpan Tulisan Tangan</x-ui.button>
                            </div>
                        </form>
                    </div>
                </div>

                @php
                    // Hotfix 60.5 — official Daengtisia RM template data, baked
                    // into the canvas (and therefore the saved PNG) so the print
                    // bundle automatically follows the paper format.
                    //
                    // Branch-aware header line: "CABANG {BRANCH} KLINIK GIGI
                    // DAENGTISIA". The branch token is derived from the visit
                    // branch name/code with the clinic name stripped to avoid
                    // duplication, falling back to "TELKOMAS" when unavailable.
                    $rmBranchRaw = $clinicVisit->branch?->name ?? $clinicVisit->branch?->code ?? '';
                    $rmBranchToken = trim(preg_replace('/\b(klinik|gigi|daengtisia|cabang)\b/i', '', $rmBranchRaw));
                    $rmBranchToken = trim(preg_replace('/\s+/', ' ', $rmBranchToken));
                    $rmBranchLabel = strtoupper($rmBranchToken !== '' ? $rmBranchToken : ($rmBranchRaw !== '' ? $rmBranchRaw : 'TELKOMAS'));

                    // Page-1 biodata grid — same fields/order as the spec (no KTP).
                    // Recomputed here (independent of the component slot scope) so
                    // it is reliably available to the canvas script.
                    $rmPatient = $clinicVisit->patient;
                    $rmDash = '-';
                    $rmGenderLabels = ['Male' => 'Laki-laki', 'Female' => 'Perempuan', 'Other' => 'Lainnya'];
                    if ($rmPatient?->date_of_birth) {
                        $rmTtlUmur = $rmPatient->date_of_birth->format('d-m-Y');
                        $rmAge = $rmPatient->age();
                        if ($rmAge !== null) {
                            $rmTtlUmur .= ' / '.$rmAge.' tahun';
                        }
                    } else {
                        $rmTtlUmur = $rmDash;
                    }
                    $rmBio = [
                        'left' => [
                            ['Nama', $rmPatient?->name ?: $rmDash],
                            ['TTL / Umur', $rmTtlUmur],
                            ['Pekerjaan', $rmPatient?->occupation ?: $rmDash],
                            ['Status Pernikahan', $rmPatient?->marital_status ?: $rmDash],
                            ['Alamat', $rmPatient?->address ?: $rmDash],
                        ],
                        'right' => [
                            ['Jenis Kelamin', $rmPatient?->gender ? ($rmGenderLabels[$rmPatient->gender] ?? $rmPatient->gender) : $rmDash],
                            ['Agama', $rmPatient?->religion ?: $rmDash],
                            ['No. Tlp / Wa', $rmPatient?->whatsapp_number ?: ($rmPatient?->phone ?: $rmDash)],
                            ['Email', $rmPatient?->email ?: $rmDash],
                            ['No. RM', $rmPatient?->medical_record_number ?: $rmDash],
                        ],
                    ];
                @endphp
                <script>
                (function () {
                    const canvas = document.getElementById('rme-canvas');
                    const ctx = canvas.getContext('2d');
                    const form = document.getElementById('handwriting-form');
                    const input = document.getElementById('handwriting-data-input');
                    const pageInput = document.getElementById('handwriting-page-input');
                    const overlay = document.getElementById('rm-editor-overlay');
                    const pageLabel = document.getElementById('editor-page-label');

                    // Hotfix 60.5 — official Daengtisia RM template data.
                    const RM_BRANCH = @json($rmBranchLabel);
                    const RM_BIO = @json($rmBio);

                    // The page currently loaded into the shared canvas. `existingSrc`
                    // is the saved PNG of that selected page ('' for a brand-new page).
                    let existingSrc = canvas.dataset.existingSrc || '';
                    // The active RM page number. Page 1 carries the patient biodata
                    // grid; Page 2+ are header + continuation table only.
                    let currentPage = parseInt(pageInput.value, 10) || 1;
                    let drawing = false;
                    let userDrew = false;
                    let baselineImg = null;
                    let baselineLoaded = false;

                    // Hotfix 60.5 — official Daengtisia Rekam Medik template baked
                    // onto the canvas. Every page renders the three-line RM header;
                    // Page 1 additionally renders the patient biodata grid; all
                    // pages render the "Hari / Tanggal | Pemeriksaan | Ket" table.
                    // KTP is never part of the biodata grid.
                    const TEMPLATE = {
                        headerH: 96,                       // 3-line official RM header
                        bioRowH: 28,                       // page-1 biodata row height
                        bioRows: 5,
                        colHeaderH: 46,                    // table column-header band
                        leftW: 150,                        // "Hari / Tanggal" column
                        rightW: 150,                       // "Ket" column
                        bioCols: [0, 150, 470, 620, 900], // label|value|label|value
                    };

                    function rmTruncate(text, maxW) {
                        text = String(text);
                        if (ctx.measureText(text).width <= maxW) return text;
                        let t = text;
                        while (t.length > 1 && ctx.measureText(t + '…').width > maxW) {
                            t = t.slice(0, -1);
                        }
                        return t + '…';
                    }

                    function drawHeader() {
                        const cx = canvas.width / 2;
                        ctx.fillStyle = '#111827';
                        ctx.textAlign = 'center';
                        ctx.textBaseline = 'middle';
                        ctx.font = '700 22px sans-serif';
                        ctx.fillText('REKAM MEDIK KEDOKTERAN GIGI', cx, 28);
                        ctx.font = '600 15px sans-serif';
                        ctx.fillText('CABANG ' + RM_BRANCH + ' KLINIK GIGI DAENGTISIA', cx, 54);
                        ctx.font = '600 14px sans-serif';
                        ctx.fillText('MAKASSAR', cx, 76);
                    }

                    // Page-1 biodata grid; returns the Y where the table starts.
                    function drawBiodata(top) {
                        const w = canvas.width;
                        const cols = TEMPLATE.bioCols;
                        const rowH = TEMPLATE.bioRowH;
                        const rows = TEMPLATE.bioRows;
                        const bottom = top + rowH * rows;

                        ctx.strokeStyle = '#111827';
                        ctx.lineWidth = 1;
                        ctx.beginPath();
                        for (let i = 0; i <= rows; i++) {
                            ctx.moveTo(0, top + i * rowH);
                            ctx.lineTo(w, top + i * rowH);
                        }
                        cols.forEach(function (x) {
                            ctx.moveTo(x, top);
                            ctx.lineTo(x, bottom);
                        });
                        ctx.stroke();

                        ctx.fillStyle = '#111827';
                        ctx.textBaseline = 'middle';
                        for (let i = 0; i < rows; i++) {
                            const yMid = top + i * rowH + rowH / 2;
                            const L = RM_BIO.left[i] || ['', ''];
                            const R = RM_BIO.right[i] || ['', ''];
                            ctx.textAlign = 'left';
                            ctx.font = '600 12px sans-serif';
                            ctx.fillText(rmTruncate(L[0], cols[1] - cols[0] - 12), cols[0] + 6, yMid);
                            ctx.fillText(rmTruncate(R[0], cols[3] - cols[2] - 12), cols[2] + 6, yMid);
                            ctx.font = '12px sans-serif';
                            ctx.fillText(': ' + rmTruncate(L[1], cols[2] - cols[1] - 20), cols[1] + 6, yMid);
                            ctx.fillText(': ' + rmTruncate(R[1], cols[4] - cols[3] - 20), cols[3] + 6, yMid);
                        }
                        return bottom;
                    }

                    // Continuation table (all pages): "Hari / Tanggal",
                    // "Pemeriksaan", "Ket" column headers above the writing area.
                    function drawTable(top) {
                        const w = canvas.width;
                        const h = canvas.height;
                        const midX1 = TEMPLATE.leftW;
                        const midX2 = w - TEMPLATE.rightW;
                        const colHeadBottom = top + TEMPLATE.colHeaderH;

                        ctx.strokeStyle = '#111827';
                        ctx.lineWidth = 1.25;
                        ctx.beginPath();
                        ctx.moveTo(0, top); ctx.lineTo(w, top);
                        ctx.moveTo(0, colHeadBottom); ctx.lineTo(w, colHeadBottom);
                        ctx.moveTo(midX1, top); ctx.lineTo(midX1, h);
                        ctx.moveTo(midX2, top); ctx.lineTo(midX2, h);
                        ctx.stroke();

                        ctx.fillStyle = '#111827';
                        ctx.font = '600 16px sans-serif';
                        ctx.textAlign = 'center';
                        ctx.textBaseline = 'middle';
                        const hm = top + TEMPLATE.colHeaderH / 2;
                        ctx.fillText('Hari /', midX1 / 2, hm - 10);
                        ctx.fillText('Tanggal', midX1 / 2, hm + 10);
                        ctx.fillText('Pemeriksaan', (midX1 + midX2) / 2, hm);
                        ctx.fillText('Ket', (midX2 + w) / 2, hm);
                    }

                    function drawTemplate() {
                        const w = canvas.width;
                        const h = canvas.height;

                        ctx.save();
                        ctx.fillStyle = '#ffffff';
                        ctx.fillRect(0, 0, w, h);

                        ctx.strokeStyle = '#111827';
                        ctx.lineWidth = 1.5;
                        ctx.strokeRect(0.75, 0.75, w - 1.5, h - 1.5);

                        drawHeader();

                        // Page 1 = header + biodata + table; Page 2+ = header +
                        // continuation table only (no biodata).
                        let tableTop = TEMPLATE.headerH;
                        if (currentPage <= 1) {
                            tableTop = drawBiodata(TEMPLATE.headerH);
                        }
                        drawTable(tableTop);
                        ctx.restore();
                    }

                    function drawBaseline(img) {
                        const ratio = img.width > 0 ? canvas.width / img.width : 1;
                        const drawH = Math.min(img.height * ratio, canvas.height);
                        ctx.drawImage(img, 0, 0, canvas.width, drawH);
                    }

                    function renderBase() {
                        ctx.clearRect(0, 0, canvas.width, canvas.height);
                        drawTemplate();
                        if (baselineLoaded && baselineImg) {
                            drawBaseline(baselineImg);
                        }
                    }

                    // Load a given page's saved PNG (or blank template) into the
                    // shared canvas. Resets the per-page draw state so the blank
                    // guard and reset stay scoped to the selected page only.
                    function loadPage(src) {
                        existingSrc = src || '';
                        userDrew = false;
                        baselineImg = null;
                        baselineLoaded = false;
                        renderBase();

                        if (existingSrc) {
                            const img = new Image();
                            img.crossOrigin = 'anonymous';
                            img.onload = function () {
                                baselineImg = img;
                                baselineLoaded = true;
                                renderBase();
                            };
                            img.onerror = function () { baselineLoaded = false; };
                            img.src = existingSrc;
                        }
                    }

                    function openEditor(pageNumber, src) {
                        currentPage = parseInt(pageNumber, 10) || 1;
                        pageInput.value = pageNumber;
                        pageLabel.textContent = pageNumber;
                        canvas.dataset.existingSrc = src || '';
                        loadPage(src);
                        overlay.classList.remove('hidden');
                        overlay.classList.add('flex');
                    }

                    // LEGACY-RME-DOCTOR-WORKSPACE-1A — unsaved clinical work is
                    // never discarded silently. The unified sequence lets a
                    // doctor step from a native page straight onto a read-only
                    // archive page, so leaving the editor with live strokes must
                    // be an explicit choice.
                    let submitting = false;

                    function hasUnsavedStrokes() {
                        return userDrew && overlay.classList.contains('flex');
                    }

                    function closeEditor(options) {
                        const force = options && options.force === true;
                        if (!force && hasUnsavedStrokes()
                            && !window.confirm('Tulisan tangan pada halaman ini belum disimpan. Tutup dan buang tulisan yang belum tersimpan?')) {
                            return;
                        }
                        overlay.classList.add('hidden');
                        overlay.classList.remove('flex');
                    }

                    window.addEventListener('beforeunload', function (e) {
                        if (submitting || !hasUnsavedStrokes()) return;
                        e.preventDefault();
                        e.returnValue = '';
                        return '';
                    });

                    // Draw the empty template immediately so the canvas is never
                    // blank before a page is loaded.
                    loadPage(existingSrc);

                    // Read-only previews open the overlay for their own page.
                    document.querySelectorAll('#rm-page-previews .rm-page-preview').forEach(function (fig) {
                        function open() {
                            const storagePage = parseInt(fig.dataset.storagePage, 10) || parseInt(fig.dataset.pageNumber, 10) || 1;
                            if (fig.dataset.formAction) {
                                form.action = fig.dataset.formAction;
                            }
                            openEditor(storagePage, fig.dataset.existingSrc || '');
                        }
                        fig.addEventListener('click', function (e) {
                            if (window.__rmHandwritingSwipeHandled && window.__rmHandwritingSwipeHandled()) {
                                e.preventDefault();
                                return;
                            }
                            open();
                        });
                        fig.addEventListener('keydown', function (e) {
                            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(); }
                        });
                    });

                    // "+ Tambah Halaman RM" opens a brand-new blank page editor.
                    const addBtn = document.getElementById('add-rm-page-btn');
                    if (addBtn) {
                        addBtn.addEventListener('click', function () {
                            const nextPage = parseInt(addBtn.dataset.nextPage, 10) || 2;
                            if (addBtn.dataset.formAction) {
                                form.action = addBtn.dataset.formAction;
                            }
                            openEditor(nextPage, '');
                        });
                    }

                    document.getElementById('close-editor-btn').addEventListener('click', function () { closeEditor(); });
                    overlay.addEventListener('click', function (e) {
                        if (e.target === overlay) closeEditor();
                    });

                    function getPos(e) {
                        const rect = canvas.getBoundingClientRect();
                        const scaleX = canvas.width / rect.width;
                        const scaleY = canvas.height / rect.height;
                        const src = e.touches ? e.touches[0] : e;
                        return {
                            x: (src.clientX - rect.left) * scaleX,
                            y: (src.clientY - rect.top) * scaleY,
                        };
                    }

                    function start(e) {
                        e.preventDefault();
                        drawing = true;
                        const p = getPos(e);
                        ctx.beginPath();
                        ctx.moveTo(p.x, p.y);
                    }

                    function draw(e) {
                        if (!drawing) return;
                        e.preventDefault();
                        userDrew = true;
                        const p = getPos(e);
                        ctx.lineWidth = 2;
                        ctx.lineCap = 'round';
                        ctx.strokeStyle = '#111827';
                        ctx.lineTo(p.x, p.y);
                        ctx.stroke();
                    }

                    function stop() { drawing = false; }

                    canvas.addEventListener('mousedown', start);
                    canvas.addEventListener('mousemove', draw);
                    canvas.addEventListener('mouseup', stop);
                    canvas.addEventListener('mouseleave', stop);
                    canvas.addEventListener('touchstart', start, { passive: false });
                    canvas.addEventListener('touchmove', draw, { passive: false });
                    canvas.addEventListener('touchend', stop);

                    // "Reset ke Tulisan Tersimpan" is non-destructive and affects
                    // ONLY the page currently loaded: it discards the in-progress
                    // additions and restores that page's saved baseline.
                    document.getElementById('clear-canvas-btn').addEventListener('click', function () {
                        userDrew = false;
                        renderBase();
                    });

                    form.addEventListener('submit', function (e) {
                        // Guard: never overwrite an existing page with a blank
                        // canvas while its baseline is still loading.
                        if (existingSrc && !baselineLoaded && !userDrew) {
                            e.preventDefault();
                            window.alert('Tulisan tangan tersimpan belum dimuat. Mohon tunggu sejenak lalu coba lagi agar tulisan lama tidak terhapus.');
                            return;
                        }
                        // Guard: a brand-new page with no strokes would only
                        // persist the empty template — block it.
                        if (!existingSrc && !userDrew) {
                            e.preventDefault();
                            window.alert('Belum ada tulisan tangan untuk disimpan. Silakan tulis pada kanvas terlebih dahulu.');
                            return;
                        }
                        input.value = canvas.toDataURL('image/png');
                        // The save itself navigates away; that is not data loss.
                        submitting = true;
                    });
                })();
                </script>
            @endif

            <script data-rm-scroll-restore>
            (function () {
                const STORAGE_KEY = 'rm_handwriting_scroll_restore';
                const MAX_AGE_MS = 10000;
                const HANDWRITING_ID = 'rm-handwriting-swipe';

                function rememberRmHandwritingScroll() {
                    try {
                        const zone = document.getElementById(HANDWRITING_ID);
                        const payload = {
                            y: window.scrollY || 0,
                            ts: Date.now(),
                            path: window.location.pathname + window.location.search,
                        };
                        if (zone) {
                            payload.zoneTop = zone.getBoundingClientRect().top + window.scrollY;
                        }
                        sessionStorage.setItem(STORAGE_KEY, JSON.stringify(payload));
                    } catch (err) { /* storage unavailable */ }
                }

                function restoreRmHandwritingScroll() {
                    try {
                        const raw = sessionStorage.getItem(STORAGE_KEY);
                        if (!raw) return;
                        const data = JSON.parse(raw);
                        sessionStorage.removeItem(STORAGE_KEY);
                        if (!data?.ts || Date.now() - data.ts > MAX_AGE_MS) return;

                        function applyRestore() {
                            const savedY = parseInt(data.y, 10);
                            if (!Number.isNaN(savedY) && savedY > 50) {
                                window.scrollTo(0, savedY);
                                return;
                            }
                            const zone = document.getElementById(HANDWRITING_ID);
                            if (zone) {
                                zone.scrollIntoView({ block: 'start', behavior: 'instant' });
                            } else if (!Number.isNaN(savedY) && data.zoneTop > 0) {
                                window.scrollTo(0, data.zoneTop);
                            }
                        }

                        requestAnimationFrame(function () {
                            applyRestore();
                            setTimeout(applyRestore, 50);
                            setTimeout(applyRestore, 200);
                            const previewImg = document.querySelector('#rm-page-previews img');
                            if (previewImg && !previewImg.complete) {
                                previewImg.addEventListener('load', applyRestore, { once: true });
                            }
                        });
                    } catch (err) { /* ignore corrupt payload */ }
                }

                window.rememberRmHandwritingScroll = rememberRmHandwritingScroll;

                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', restoreRmHandwritingScroll);
                } else {
                    restoreRmHandwritingScroll();
                }

                document.addEventListener('DOMContentLoaded', function () {
                    const nav = document.getElementById('rm-page-nav');
                    if (!nav) return;
                    nav.querySelectorAll('a[data-rm-page-nav]').forEach(function (link) {
                        link.addEventListener('click', rememberRmHandwritingScroll);
                    });
                });
            })();
            </script>

            @include('rme.visits.medical-record.partials.rm-page-swipe-script')
        </x-ui.card>

        {{-- SATUSEHAT-4A — structured diagnosis card (explicit entry only). --}}
        @include('rme.visits.medical-record.partials.structured-diagnoses', [
            'canUpdate' => $canUpdate ?? (auth()->user()?->can('update', $medicalRecord) ?? false),
        ])

        {{-- FIX-PRE-68-45 Scope A — relocated "Buku RM Pasien" sheet navigation
             (Sprint 64.0). Moved below the handwriting canvas so the doctor sees
             the handwritten RME first; sheet switching still works as before. --}}
        @include('rme.visits.partials.rm-sheet-nav', [
            'canEdit' => auth()->user()?->can('update', $medicalRecord) ?? false,
        ])

        {{-- LEGACY-RME-PDF-HISTORY-1 — the patient's full RME history (native +
             published legacy archive) right beside the RM book, so the doctor
             reads the whole clinical story from inside the workspace. --}}
        @include('rme.visits.partials.patient-rme-clinical-history', [
            'clinicalHistory' => $clinicalHistory ?? collect(),
        ])

        {{-- FIX-PRE-68-45 Scope A — relocated "Riwayat Pencatatan" metadata card. --}}
        <x-ui.card title="Riwayat Pencatatan">
            <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Dicatat oleh</dt>
                    <dd class="mt-1 text-sm text-gray-900">{{ $medicalRecord->recordedBy?->name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Dibuat pada</dt>
                    <dd class="mt-1 text-sm text-gray-900">{{ optional($medicalRecord->created_at)->format('d/m/Y H:i') ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Diperbarui pada</dt>
                    <dd class="mt-1 text-sm text-gray-900">{{ optional($medicalRecord->updated_at)->format('d/m/Y H:i') ?? '—' }}</dd>
                </div>
                @if ($medicalRecord->status === \App\Modules\MedicalRecord\Models\MedicalRecord::STATUS_FINAL)
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Difinalisasi pada</dt>
                        <dd class="mt-1 text-sm text-gray-900">{{ optional($medicalRecord->finalized_at)->format('d/m/Y H:i') ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Difinalisasi oleh</dt>
                        <dd class="mt-1 text-sm text-gray-900">{{ $medicalRecord->finalizedBy?->name ?? '—' }}</dd>
                    </div>
                @endif
            </dl>
        </x-ui.card>

        {{-- FIX-RME-CONSENT-WORKFLOW-PRINT-UX-2 / FIX-02 — "Dokumen RME Pasien"
             relocated here, to the FOOT of the page. The information hierarchy is
             now: visit information -> handwritten RME -> supporting documents, so a
             doctor opening the record lands on the active visit and the clinical
             workspace rather than on the document archive. This is placement only:
             the partial, its data and the documents themselves are unchanged. --}}
        @include('rme.visits.partials.rme-workspace-documents', [
            'workspaceDocuments' => $workspaceDocuments ?? collect(),
        ])

        {{-- FIX-RME-CONSENT-WORKFLOW-PRINT-UX-2 / FIX-03 — the Finalisasi control and
             its state messages now live in the page header. Nothing replaces them
             here: the action was MOVED, not duplicated. --}}
    </div>
</x-settings-shell>
