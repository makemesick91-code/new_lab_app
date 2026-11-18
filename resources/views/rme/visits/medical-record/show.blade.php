<x-settings-shell title="Rekam Medis">
    @php
        // Rule 243 §2.11 (PR A3, review HIGH-1) — decided on the server
        // (MedicalRecordPolicy::viewAnchorContent): false only when the active
        // sheet is the book and its own (anchor) visit is one this viewer may not
        // read. Then that visit is never named and nothing typed onto the sheet
        // (SOAP, diagnoses, status, recorded/finalized metadata) is shown; only
        // the handwriting pages remain.
        $anchorContentReadable = $anchorContentReadable ?? true;
        $isFinal = $medicalRecord->status === \App\Modules\MedicalRecord\Models\MedicalRecord::STATUS_FINAL;

        // FIX-RME-CONSENT-WORKFLOW-PRINT-UX-2 / FIX-03 — hoisted from the middle of
        // the page so the primary actions (Finalisasi, Cetak RME) can render in the
        // page-header actions slot. Presentation only: every gate below is the same
        // policy/handwriting check as before, evaluated in the same way.
        $isDraft = $medicalRecord->status === \App\Modules\MedicalRecord\Models\MedicalRecord::STATUS_DRAFT;
        $canUpdate = auth()->user()?->can('update', $medicalRecord) ?? false;
        $canFinalize = auth()->user()?->can('finalize', $medicalRecord) ?? false;
        // Rule 240 §1 (memo M3.3) — a FINAL sheet's own content (notes, structured
        // diagnoses, the emergency override) is locked: `editSheet` denies it, and
        // `! $isFinal` mirrors the service for Super Admin, whom Gate::before lets
        // past the policy. The handwriting book keeps asking `update` (page seal
        // is a later part of rule 240).
        $canEditSheet = ! $isFinal && (auth()->user()?->can('editSheet', $medicalRecord) ?? false);
        // DMS-RME-REVISION-PROGRAM-5 (CW5-04, AIR-RMC-25) — editable means the
        // CAPABILITY, not the `update` permission alone: before the encounter is
        // consented the editor, the "+ Tambah Halaman RM" control and the
        // diagnosis forms opened onto a write the service refuses.
        $canAuthorRmeNow = (bool) ($canAuthorRme ?? false);
        // Rule 243 §2.5 — the editor is gated on the ability of the record it
        // writes (the book: appendPage; an in-place sheet page: update), computed
        // in the controller.
        $canEditHandwriting = ($canEditHandwritingTarget ?? $canUpdate) && $canAuthorRmeNow;
        // PR A1c — "+ Tambah Halaman RM" writes a new page to the BOOK
        // (appendPage), so it has its own ability, also computed in the controller.
        $canAppendHandwritingPage = ($canAppendBookPage ?? $canEditHandwritingTarget ?? $canUpdate) && $canAuthorRmeNow;
        $savedHandwriting = $medicalRecord->latestHandwriting();
        $hasHandwriting = $hasRequiredHandwriting ?? false;
        $hasLegacySoap = $anchorContentReadable && (filled($medicalRecord->subjective)
            || filled($medicalRecord->objective)
            || filled($medicalRecord->assessment)
            || filled($medicalRecord->plan));
    @endphp

    <div class="space-y-6">
        <x-ui.page-header
            title="Rekam Medis"
            :subtitle="$anchorContentReadable
                ? $clinicVisit->visit_number.' — '.($clinicVisit->visit_date?->format('d/m/Y') ?? '-')
                : 'Buku RM pasien'"
        >
            <x-slot:breadcrumb>Rekam Medis Elektronik</x-slot:breadcrumb>
            <x-slot:actions>
                @if ($anchorContentReadable)
                    <x-ui.badge :tone="$isFinal ? 'success' : 'warning'">
                        {{ $isFinal ? 'Final' : 'Draft' }}
                    </x-ui.badge>
                @endif

                {{-- FIX-RME-CONSENT-WORKFLOW-PRINT-UX-2 / FIX-03 — "Finalisasi" moved
                     here from the very bottom of the page. MOVED, NOT DUPLICATED:
                     there is no second Finalisasi control further down. The action,
                     its route, its policy gate ($canFinalize) and the mandatory
                     handwriting precondition ($hasHandwriting) are all unchanged. --}}
                @if ($anchorContentReadable && $isDraft && $canFinalize)
                    @if ($hasHandwriting && $canAuthorRme)
                        <form method="POST" action="{{ route('rme.visits.medical-record.finalize', [$clinicVisit, $medicalRecord]) }}"
                              onsubmit="return confirm('Finalkan rekam medis ini? Setelah final, rekam medis terkunci dan hanya dapat dikoreksi melalui amandemen yang disetujui.')">
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
                @php
                    // DMS-RME-REVISION-PROGRAM-5 (CW5-03) — the same three facts
                    // Detail Kunjungan asks before offering this button: the
                    // transition ability (doctor room guard), the completion
                    // ability, and a signed consent. Asking only the second offered
                    // a form the server refuses.
                    $finishOffered = $examVisitInFocus
                        && $clinicVisit->status === \App\Modules\ClinicVisit\Models\ClinicVisit::STATUS_IN_PROGRESS
                        && (auth()->user()?->can('transition', $clinicVisit) ?? false)
                        // Rule 241 §2 — the EXECUTED consent, decided in the controller.
                        && ($clinicVisitConsentExecuted ?? false);
                @endphp
                @if ($finishOffered)
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
                @if ($anchorContentReadable)
                    <x-ui.button variant="secondary" :href="route('rme.visits.show', $clinicVisit)">
                        &larr; Kembali ke Kunjungan
                    </x-ui.button>
                @elseif ($sourceVisit)
                    <x-ui.button variant="secondary" :href="route('rme.visits.show', $sourceVisit)">
                        &larr; Kembali ke Kunjungan
                    </x-ui.button>
                @endif
            </x-slot:actions>
        </x-ui.page-header>

        {{-- FIX-RME-EXAM-CONSENT-ODONTOGRAM-HISTORY-3 / FIX-02 + CORRECTIVE-02 —
             the RME authoring gate.

             Reading is never blocked: the patient's history, the published legacy
             archive and this record itself stay fully visible. Only WRITING the
             current record waits — and it waits on an authorized, consented
             examination being in progress, not merely on this sheet's own visit.

             Presentation only; the service asserts the same authority. --}}
        @unless ($canAuthorRme)
            @include('rme.visits.partials.ptm-authoring-banner', ['ptmBannerSubject' => 'penulisan rekam medis'])
        @endunless

        {{-- FIX-RME-CONSENT-WORKFLOW-PRINT-UX-2 / FIX-03 — the finalization STATE
             messages travel with the relocated button so the doctor learns why it is
             unavailable without scrolling to the bottom of the record. --}}
        @if (! $anchorContentReadable)
            {{-- Rule 243 §2.11 — the anchor's status is not this viewer's to read. --}}
        @elseif ($isDraft && $canFinalize && ! $hasHandwriting)
            <x-ui.alert variant="warning">
                RME belum dapat difinalkan karena catatan tulis tangan dokter belum tersedia.
            </x-ui.alert>
        @elseif (! $isDraft)
            {{-- Rule 240 §1 — the wording equals MedicalRecordService::MESSAGE_FINAL_SHEET_LOCKED
                 (pinned by MedicalRecordFinalizationLockTest). --}}
            <x-ui.alert variant="success" title="Rekam Medis ini telah difinalkan.">
                Rekam medis ini sudah final dan terkunci. Koreksi hanya dapat dilakukan melalui amandemen yang disetujui.
            </x-ui.alert>
        @endif

        {{-- Rule 240 §6 (memo M3.3 PR4) — amendment status and the request offer. --}}
        @include('rme.amendments.partials.record-panel')

        {{-- Rule 243 §2.11 — the parent visit is named only when the viewer may
             open it (`$activeFollowUpOf`, decided in the controller). --}}
        @if ($anchorContentReadable && $clinicVisit->isFollowUpVisit())
            <x-ui.alert variant="info">
                <span class="font-medium">Jenis Kunjungan:</span> {{ $clinicVisit->visitTypeLabel() }}
                @if ($activeFollowUpOf ?? null)
                    &mdash; <span class="font-medium">Kontrol dari:</span>
                    <a href="{{ route('rme.visits.show', $activeFollowUpOf) }}" class="font-mono underline">{{ $activeFollowUpOf->visit_number }}</a>
                @endif
            </x-ui.alert>
        @endif

        {{-- Rule 243 §2.13 — the tabs' visit is decided in the controller: the
             active sheet's visit, or on an unreadable anchor the source visit
             (no tabs without one), so they never show that anchor's room state. --}}
        @if ($workflowNavVisit ?? null)
            @include('rme.visits.partials.visit-workflow-nav', [
                'clinicVisit' => $workflowNavVisit,
                'active' => 'medical-record',
            ])
        @endif

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
                 never shown here, preserving RME privacy rules.
                 Rule 243 §3 (memo M3.1, PR C1) — the rows come from
                 PatientIdentityPresenter (MedicalRecordController): a Doctor gets
                 name, place/date of birth and age, occupation, gender and RM
                 number only; every other role the full table as before (marital
                 status and religion have no column, so "-"). A row's right pair
                 may be empty. --}}
            <div class="overflow-x-auto">
                <table class="w-full border-collapse text-sm">
                    <tbody>
                        @foreach ($patientIdentity->biodataRows as [$bioLeftLabel, $bioLeftValue, $bioRightLabel, $bioRightValue])
                            <tr class="align-top">
                                <th class="border border-gray-200 bg-gray-50 px-3 py-1.5 text-left font-semibold text-gray-700 whitespace-nowrap">{{ $bioLeftLabel }}</th>
                                <td class="w-1/3 border border-gray-200 px-3 py-1.5 text-gray-900">: {{ $bioLeftValue }}</td>
                                @if ($bioRightLabel !== null)
                                    <th class="border border-gray-200 bg-gray-50 px-3 py-1.5 text-left font-semibold text-gray-700 whitespace-nowrap">{{ $bioRightLabel }}</th>
                                    <td class="w-1/3 border border-gray-200 px-3 py-1.5 text-gray-900">: {{ $bioRightValue }}</td>
                                @else
                                    <th class="border border-gray-200 bg-gray-50 px-3 py-1.5"></th>
                                    <td class="w-1/3 border border-gray-200 px-3 py-1.5"></td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Visit-level context retained below the patient biodata table.
                 Rule 243 §2.11 — omitted for an anchor visit the viewer may not read. --}}
            @if ($anchorContentReadable)
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
            @endif
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
            // Rule 240 §4 (memo M3.3) — a page the live encounter may not overwrite
            // is read-only; the controller asks MedicalRecordHandwritingService, the
            // authority that refuses the overwrite. Adding a NEW page stays open.
            $activePageLocked = ($activePageLockMessage ?? null) !== null;
            $canEditActivePage = $canEditHandwriting && ! $activePageLocked;
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
                // Rule 240 §4 — $canonicalHandwritingFormAction, $storagePageNumber
                // and the editor's target ($editorFormAction, $editorPageNumber,
                // $editorRevision) come from MedicalRecordController::show().
            @endphp

            <div
                id="rm-handwriting-swipe"
                class="mb-4 touch-pan-y"
                data-rm-swipe-zone
                @if ($prevSwipeUrl) data-prev-url="{{ $prevSwipeUrl }}" @endif
                @if ($nextSwipeUrl) data-next-url="{{ $nextSwipeUrl }}" @endif
            >
            @include('rme.visits.medical-record.partials.rm-page-navigator', ['canEditHandwriting' => $canAppendHandwritingPage])

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
                    class="rm-page-preview group relative rounded-lg border border-gray-300 bg-white p-2 touch-pan-y {{ $canEditActivePage ? 'cursor-pointer transition hover:border-brand-500 hover:shadow' : '' }}"
                    data-page-number="{{ $activePageNumber }}"
                    data-storage-page="{{ $storagePageNumber }}"
                    @if ($activePageLocked)
                        data-sealed="1"
                    @else
                        data-revision="{{ $activeRmPage['revision'] ?? '' }}"
                        data-form-action="{{ route('rme.visits.medical-record.handwriting.store', [$handwritingFormVisit, $handwritingFormRecord]) }}"
                    @endif
                    data-existing-src="{{ $activeSrc }}"
                    @if ($canEditActivePage) data-rm-editable role="button" tabindex="0" @endif
                >
                    <figcaption class="mb-2 flex items-center justify-between text-xs">
                        <span class="font-semibold text-gray-700">Halaman {{ $activePageNumber }}</span>
                        @if ($activeHasContent)
                            <span class="text-gray-500">Tersimpan pada {{ $activeSavedAt?->format('d/m/Y H:i') }}</span>
                        @endif
                    </figcaption>

                    {{-- Rule 240 §8 (M3.3 PR6) — a page appended by an approved amendment:
                         which amendment, approved when (WITA) and by whom. --}}
                    @if (($activeRmPage['amendment_label'] ?? null) !== null)
                        <p class="mb-2 rounded border border-brand-200 bg-brand-50 px-2 py-1 text-xs font-medium text-brand-800" data-amendment-page="{{ $activeRmPage['amendment_id'] }}">
                            {{-- Review INFO — the book's own page number beside the workspace one. --}}
                            Halaman buku RM {{ $storagePageNumber }} · {{ $activeRmPage['amendment_label'] }}
                        </p>
                    @endif

                    @if ($activePageLocked)
                        {{-- Rule 240 §4 — the service's own reason (sealed, or written by
                             another encounter), pinned by MedicalRecordFinalizationPageSealTest. --}}
                        <p class="mb-2 rounded border border-gray-200 bg-gray-50 px-2 py-1 text-xs text-gray-700">
                            {{ $activePageLockMessage }}
                        </p>
                    @endif

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

                    @if ($canEditActivePage)
                        <span class="pointer-events-none absolute inset-x-0 bottom-2 mx-auto w-fit rounded bg-black/60 px-2 py-0.5 text-[11px] text-white opacity-0 transition group-hover:opacity-100">
                            Klik untuk menulis
                        </span>
                    @endif
                </figure>
            </div>
            @endif
            </div>{{-- /#rm-handwriting-swipe --}}
            {{-- PR A1c — the overlay serves both writes: the in-place page (only a
                 preview marked data-rm-editable opens it) and a new book page. --}}
            @if ($canEditHandwriting || $canAppendHandwritingPage)
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

                        <form method="POST" action="{{ $editorFormAction }}" id="handwriting-form">
                            @csrf
                            @if ($sourceVisit)
                                <input type="hidden" name="source_visit_id" value="{{ $sourceVisit->id }}">
                            @endif
                            <input type="hidden" name="handwriting_data" id="handwriting-data-input">
                            <input type="hidden" name="page_number" id="handwriting-page-input" value="{{ $editorPageNumber }}">
                            {{-- DMS-RME-COMPREHENSIVE-CLOSURE-1 (OD-6-1) — the revision of the page
                                 this device loaded; a save over a page another device changed
                                 meanwhile is refused instead of replacing that drawing. --}}
                            <input type="hidden" name="page_revision" id="handwriting-revision-input" value="{{ $editorRevision }}">

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
                                    :disabled="! $canAuthorRme">Simpan Tulisan Tangan</x-ui.button>
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

                    // Page-1 biodata grid (no KTP). Rule 243 §3 (memo M3.1, PR C2)
                    // — the allowed fields only, for every audience, decided by
                    // PatientIdentityPresenter::rmPageOneBiodata() in the
                    // controller: no address, phone/WhatsApp or email is ever
                    // baked into a new page's PNG. Blank cells stay blank.
                    $rmBio = $rmPageOneBiodata;
                @endphp
                <script>
                (function () {
                    const canvas = document.getElementById('rme-canvas');
                    const ctx = canvas.getContext('2d');
                    const form = document.getElementById('handwriting-form');
                    const input = document.getElementById('handwriting-data-input');
                    const pageInput = document.getElementById('handwriting-page-input');
                    const revisionInput = document.getElementById('handwriting-revision-input');
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
                    // Rule 243 §3 (review MEDIUM-3) — the stored page's image
                    // failed to load (a doctor may get 404/503 for a page 1).
                    let baselineFailed = false;

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

                    // rm-page-canvas:start
                    // Rule 243 §3 (review LOW-A, INFO-2). Plain JS between the
                    // markers: tests/js/rm-page-save-guard.test.mjs reads it.
                    const RM_HIDDEN_CELL_TINT = '#f3f4f6';

                    // The page-1 grid cells left blank by the presenter (no
                    // label): the cells RmPageOneBiodataRedactor blanks.
                    function rmHiddenCellRects(bio, template) {
                        const rects = [];
                        const cols = template.bioCols;
                        for (let i = 0; i < template.bioRows; i++) {
                            const top = template.headerH + i * template.bioRowH;
                            [['left', 0], ['right', 2]].forEach(function (side) {
                                const cell = bio[side[0]][i] || ['', ''];
                                if (cell[0] === '') {
                                    [side[1], side[1] + 1].forEach(function (c) {
                                        rects.push({ x: cols[c], y: top, w: cols[c + 1] - cols[c], h: template.bioRowH });
                                    });
                                }
                            });
                        }
                        return rects;
                    }

                    // One pen segment; `hidden` cells (1 px inside their grid
                    // lines) are clipped out, so no ink lands where the
                    // redactor looks (2 px inside).
                    function rmStrokeSegment(ctx, from, to, hidden, width, height) {
                        ctx.save();
                        if (hidden.length > 0) {
                            ctx.beginPath();
                            ctx.rect(0, 0, width, height);
                            hidden.forEach(function (r) {
                                ctx.rect(r.x + 1, r.y + 1, r.w - 2, r.h - 2);
                            });
                            ctx.clip('evenodd');
                        }
                        ctx.beginPath();
                        ctx.moveTo(from.x, from.y);
                        ctx.lineTo(to.x, to.y);
                        ctx.stroke();
                        ctx.restore();
                    }

                    // Each loadPage() call begins a load; a callback of an
                    // earlier load (another page) is ignored.
                    function rmPageLoadSequence() {
                        let current = 0;
                        return function begin() {
                            const mine = ++current;
                            return function latest(callback) {
                                return function () {
                                    if (mine === current) {
                                        callback.apply(this, arguments);
                                    }
                                };
                            };
                        };
                    }
                    // rm-page-canvas:end

                    const beginPageLoad = rmPageLoadSequence();

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

                        // Rule 243 §3 (review LOW-A) — the blank cells read as
                        // not-for-writing: a light tint (no ink to the redactor,
                        // luminance >= 200); strokes there are clipped away.
                        ctx.fillStyle = RM_HIDDEN_CELL_TINT;
                        rmHiddenCellRects(RM_BIO, TEMPLATE).forEach(function (r) {
                            ctx.fillRect(r.x + 2, r.y + 2, r.w - 4, r.h - 4);
                        });

                        ctx.fillStyle = '#111827';
                        ctx.textBaseline = 'middle';
                        for (let i = 0; i < rows; i++) {
                            const yMid = top + i * rowH + rowH / 2;
                            const L = RM_BIO.left[i] || ['', ''];
                            const R = RM_BIO.right[i] || ['', ''];
                            ctx.textAlign = 'left';
                            // Rule 243 §3 — a blank cell (no label) draws nothing.
                            if (L[0] !== '') {
                                ctx.font = '600 12px sans-serif';
                                ctx.fillText(rmTruncate(L[0], cols[1] - cols[0] - 12), cols[0] + 6, yMid);
                                ctx.font = '12px sans-serif';
                                ctx.fillText(': ' + rmTruncate(L[1], cols[2] - cols[1] - 20), cols[1] + 6, yMid);
                            }
                            if (R[0] !== '') {
                                ctx.font = '600 12px sans-serif';
                                ctx.fillText(rmTruncate(R[0], cols[3] - cols[2] - 12), cols[2] + 6, yMid);
                                ctx.font = '12px sans-serif';
                                ctx.fillText(': ' + rmTruncate(R[1], cols[4] - cols[3] - 20), cols[3] + 6, yMid);
                            }
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
                        // Review INFO-2 — a load still in flight for another
                        // page never paints onto this one.
                        const latest = beginPageLoad();
                        existingSrc = src || '';
                        userDrew = false;
                        baselineImg = null;
                        baselineLoaded = false;
                        baselineFailed = false;
                        renderBase();

                        if (existingSrc) {
                            const img = new Image();
                            img.crossOrigin = 'anonymous';
                            img.onload = latest(function () {
                                baselineImg = img;
                                baselineLoaded = true;
                                renderBase();
                            });
                            img.onerror = latest(function () {
                                baselineLoaded = false;
                                baselineFailed = true;
                            });
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
                    document.querySelectorAll('#rm-page-previews .rm-page-preview[data-rm-editable]').forEach(function (fig) {
                        // Rule 240 §4 — a sealed page never opens the editor.
                        if (fig.dataset.sealed === '1') {
                            return;
                        }
                        function open() {
                            const storagePage = parseInt(fig.dataset.storagePage, 10) || parseInt(fig.dataset.pageNumber, 10) || 1;
                            if (fig.dataset.formAction) {
                                form.action = fig.dataset.formAction;
                            }
                            if (revisionInput) {
                                revisionInput.value = fig.dataset.revision || '';
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
                            // A brand-new page: another device adding it first is a conflict.
                            if (revisionInput) {
                                revisionInput.value = 'none';
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

                    let lastPoint = null;

                    function start(e) {
                        e.preventDefault();
                        drawing = true;
                        lastPoint = getPos(e);
                    }

                    // Rule 243 §3 (review LOW-A) — on page 1 a stroke never
                    // reaches the blank biodata cells (the doctor's view blanks
                    // them, and ink there would lock page 1): each segment is
                    // stroked under a clip that excludes them.
                    function draw(e) {
                        if (!drawing) return;
                        e.preventDefault();
                        userDrew = true;
                        const p = getPos(e);
                        ctx.lineWidth = 2;
                        ctx.lineCap = 'round';
                        ctx.strokeStyle = '#111827';
                        rmStrokeSegment(
                            ctx,
                            lastPoint || p,
                            p,
                            currentPage <= 1 ? rmHiddenCellRects(RM_BIO, TEMPLATE) : [],
                            canvas.width,
                            canvas.height,
                        );
                        lastPoint = p;
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

                    // rm-save-guard:start
                    // Why a save is refused, or null. Rule 243 §3 (review
                    // MEDIUM-3): a stored page whose image is not loaded (still
                    // loading, or failed — a doctor may get 404/503 for a page
                    // 1) is never saved, whatever was drawn: the canvas would
                    // replace it with the template plus the new strokes. A new
                    // page with no strokes would persist the empty template.
                    // Plain JS between the markers: tests/js reads it.
                    function rmSaveRefusal(state) {
                        if (state.existingSrc && !state.baselineLoaded) {
                            return state.baselineFailed
                                ? 'Gambar halaman tersimpan gagal dimuat, sehingga halaman ini tidak dapat disimpan tanpa menghapus tulisan lama. Muat ulang halaman, atau tulis pada halaman baru.'
                                : 'Tulisan tangan tersimpan belum dimuat. Mohon tunggu sejenak lalu coba lagi agar tulisan lama tidak terhapus.';
                        }
                        if (!state.existingSrc && !state.userDrew) {
                            return 'Belum ada tulisan tangan untuk disimpan. Silakan tulis pada kanvas terlebih dahulu.';
                        }
                        return null;
                    }
                    // rm-save-guard:end

                    form.addEventListener('submit', function (e) {
                        const refusal = rmSaveRefusal({
                            existingSrc: existingSrc,
                            baselineLoaded: baselineLoaded,
                            baselineFailed: baselineFailed,
                            userDrew: userDrew,
                        });
                        if (refusal !== null) {
                            e.preventDefault();
                            window.alert(refusal);
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

        {{-- SATUSEHAT-4A — structured diagnosis card (explicit entry only).
             Rule 243 §2.11 — the diagnoses belong to the sheet's own visit. --}}
        @if ($anchorContentReadable)
            @include('rme.visits.medical-record.partials.structured-diagnoses', [
                'canUpdate' => $canEditSheet && $canAuthorRmeNow,
            ])
        @endif

        {{-- FEATURE-RME-DOCTOR-PERFORMED-TREATMENTS-1 (rule 219) — "Tindakan
             Dilakukan", after the diagnosis. Absent while the capability is OFF. --}}
        @if (($performedTreatments ?? null) !== null)
            @include('rme.visits.medical-record.partials.performed-treatments')
        @endif

        {{-- FIX-PRE-68-45 Scope A — relocated "Buku RM Pasien" sheet navigation
             (Sprint 64.0). Moved below the handwriting canvas so the doctor sees
             the handwritten RME first; sheet switching still works as before. --}}
        {{-- Rule 243 §2.5 — "+ Tambah Lembar RM" follows `create` on the visit it
             creates on (computed in the controller), not `update` of the active
             sheet, which for a returning patient is the book. --}}
        @include('rme.visits.partials.rm-sheet-nav', [
            'canEdit' => $canAddSheet ?? (auth()->user()?->can('update', $medicalRecord) ?? false),
        ])

        {{-- LEGACY-RME-PDF-HISTORY-1 — the patient's full RME history (native +
             published legacy archive) right beside the RM book, so the doctor
             reads the whole clinical story from inside the workspace. --}}
        @include('rme.visits.partials.patient-rme-clinical-history', [
            'clinicalHistory' => $clinicalHistory ?? collect(),
        ])

        {{-- FIX-PRE-68-45 Scope A — relocated "Riwayat Pencatatan" metadata card.
             Rule 243 §2.11 — the sheet's record metadata follows its own visit. --}}
        @if ($anchorContentReadable)
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
        @endif

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
