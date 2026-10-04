{{--
    FEATURE-LEGACY-BATCH-REVIEW-PUBLISH-1 (PR1) — the review workspace.

    §12 asks for few clicks, fast prev/next, a persistent preview and clear
    reasons. §1 and §24 forbid replacing inspection with automatic approval, so
    the ONLY way to attest here is to decide on the focused document: each
    submit button posts exactly one import_id. There is no "mark all" control,
    and the request shape cannot express one.

    Alpine + Blade only — no React, no Vue, no new dependency. Keyboard handling
    is a thin layer over the same forms a mouse would submit, so it cannot do
    anything the visible buttons cannot.

    PRIVACY. KTP/NIK, date of birth and clinical content are never rendered. The
    page images are served by the canonical private page route, which does its
    own authorization per request — this view only links to it.
--}}
<x-settings-shell :title="$heading">
    <div
        class="space-y-6"
        x-data="legacyBatchReview({
            decideUrl: @js(route($routePrefix.'.decide', $session->uuid)),
            mutable: @js($session->isMutable()),
        })"
        @keydown.window="onKey($event)"
    >
        <x-ui.page-header
            :title="$heading"
            subtitle="Periksa setiap dokumen, tandai keputusannya, lalu kirim seluruh keputusan dalam satu tindakan."
        >
            <x-slot:breadcrumb>
                Master Data RME / {{ $heading }} / Sesi {{ Str::limit($session->uuid, 8, '') }}
            </x-slot:breadcrumb>

            <x-slot:actions>
                <x-ui.button :href="route($routePrefix.'.index')" variant="secondary" size="sm">
                    Kembali ke Daftar
                </x-ui.button>

                @if ($counters['pending_submit'] > 0)
                    <form method="POST" action="{{ route($routePrefix.'.submit', $session->uuid) }}">
                        @csrf
                        <x-ui.button type="submit" variant="success">
                            Kirim {{ $counters['pending_submit'] }} Keputusan Ditinjau
                        </x-ui.button>
                    </form>
                @endif
            </x-slot:actions>
        </x-ui.page-header>

        @if (session('status'))
            <x-ui.alert variant="success">{{ session('status') }}</x-ui.alert>
        @endif

        @if ($errors->any())
            <x-ui.alert variant="danger">{{ $errors->first() }}</x-ui.alert>
        @endif

        <div class="flex flex-wrap items-center gap-3 text-sm">
            <x-ui.badge tone="info">{{ $session->statusLabel() }}</x-ui.badge>
            <span class="text-ink-soft">Ditandai ditinjau: <strong>{{ $counters['marked_reviewed'] }}</strong></span>
            <span class="text-ink-soft">Ditahan: <strong>{{ $counters['marked_blocked'] }}</strong></span>
            <span class="text-ink-soft">Perlu perhatian: <strong>{{ $counters['marked_attention'] }}</strong></span>
            <span class="text-ink-soft">Diterapkan: <strong>{{ $counters['applied'] }}</strong></span>
            @if ($counters['refused'] > 0)
                <span class="text-danger-700">Ditolak sistem: <strong>{{ $counters['refused'] }}</strong></span>
            @endif
        </div>

        @if (! $session->isMutable())
            <x-ui.alert variant="info">
                Sesi ini sudah tidak dapat diubah. Buka sesi baru untuk meninjau dokumen lain.
            </x-ui.alert>
        @endif

        <div class="grid gap-6 lg:grid-cols-[22rem_1fr]">
            {{-- Queue. Compact and scrollable so the preview stays in view. --}}
            <x-ui.card padding="p-0">
                <div class="border-b border-hairline px-4 py-3">
                    <p class="text-sm font-semibold text-ink">Antrian Tinjauan</p>
                    <p class="text-xs text-ink-muted">{{ $paginator->total() }} dokumen siap ditinjau</p>
                </div>

                <ul class="max-h-[32rem] divide-y divide-hairline overflow-y-auto">
                    @foreach ($items as $item)
                        @php($isFocus = $focus && (int) $focus['import_id'] === (int) $item['import_id'])
                        <li class="{{ $isFocus ? 'bg-brand-50' : '' }}">
                            <a
                                href="{{ route($routePrefix.'.show', ['session' => $session->uuid, 'import' => $item['import_id']]) }}"
                                class="block px-4 py-3 hover:bg-navy-50 focus:outline-none focus:ring-2 focus:ring-brand-100"
                            >
                                <span class="block text-sm font-medium text-ink">
                                    {{ $item['patient_name'] ?? '—' }}
                                </span>
                                <span class="block text-xs text-ink-muted">
                                    {{ $item['medical_record_number'] ?? '—' }} ·
                                    {{ $item['clinical_date'] ?? 'tanpa tanggal' }}
                                </span>

                                <span class="mt-1 block">
                                    @if ($item['triage_blocking'])
                                        <x-ui.badge tone="warning">{{ $item['triage_status_label'] }}</x-ui.badge>
                                    @elseif ($item['submit_status'] === 'APPLIED')
                                        <x-ui.badge tone="success">Diterapkan</x-ui.badge>
                                    @elseif ($item['submit_status'] === 'REFUSED')
                                        <x-ui.badge tone="danger">Ditolak</x-ui.badge>
                                    @elseif ($item['decision'])
                                        <x-ui.badge tone="info">{{ $item['decision_label'] }}</x-ui.badge>
                                    @else
                                        <span class="text-xs text-ink-muted">Belum ditinjau</span>
                                    @endif
                                </span>
                            </a>
                        </li>
                    @endforeach
                </ul>

                <div class="border-t border-hairline px-4 py-3">{{ $paginator->links() }}</div>
            </x-ui.card>

            {{-- Persistent preview + decision panel. --}}
            <div class="space-y-4">
                @if (! $focus)
                    <x-ui.empty-state
                        title="Tidak ada dokumen untuk ditinjau"
                        description="Antrian tinjauan pada cakupan cabang Anda sedang kosong."
                    />
                @else
                    <x-ui.card padding="p-0">
                        <div class="flex flex-wrap items-baseline justify-between gap-2 border-b border-hairline px-4 py-3">
                            <div>
                                <p class="text-sm font-semibold text-ink">{{ $focus['patient_name'] ?? '—' }}</p>
                                <p class="text-xs text-ink-muted">
                                    Nomor RM {{ $focus['medical_record_number'] ?? '—' }} ·
                                    {{ $focus['branch_name'] ?? 'tanpa cabang' }} ·
                                    Tanggal dokumen {{ $focus['clinical_date'] ?? '—' }} ·
                                    {{ $focus['page_count'] }} halaman
                                </p>
                            </div>

                            <p class="text-xs text-ink-muted">
                                Checksum {{ $focus['checksum_prefix'] ?? '—' }}
                            </p>
                        </div>

                        {{-- Rendered pages, served by the canonical private page
                             route which authorizes every request on its own. --}}
                        <div class="max-h-[34rem] space-y-3 overflow-y-auto bg-navy-50 p-3">
                            @forelse ($focus['page_numbers'] as $pageNumber)
                                <img
                                    src="{{ route($pagePreviewRoute, ['import' => $focus['import_id'], 'page' => $pageNumber]) }}"
                                    alt="Halaman {{ $pageNumber }}"
                                    loading="lazy"
                                    class="mx-auto w-full max-w-3xl rounded border border-hairline bg-white shadow-card"
                                >
                            @empty
                                <x-ui.alert variant="warning">
                                    Halaman dokumen belum tersedia. Dokumen ini akan ditolak sistem
                                    bila dikirim; periksa proses render terlebih dahulu.
                                </x-ui.alert>
                            @endforelse
                        </div>
                    </x-ui.card>

                    @if ($focus['can_clear_triage'] ?? false)
                        <form method="POST" action="{{ route($routePrefix.'.clear-triage', $session->uuid) }}">
                            @csrf
                            <input type="hidden" name="import_id" value="{{ $focus['import_id'] }}">
                            <x-ui.button type="submit" variant="secondary" size="sm">
                                Bebaskan Status Tahan
                            </x-ui.button>
                        </form>
                    @endif

                    @if ($session->isMutable() && ($focus['can_review'] ?? false))
                        {{-- ONE form, ONE import_id. The decision is chosen by
                             which button is pressed; a triaging choice reveals
                             the reason panel, which the server also requires. --}}
                        <x-ui.card padding="p-0">
                            <form method="POST" action="{{ route($routePrefix.'.decide', $session->uuid) }}" x-ref="form">
                                @csrf
                                <input type="hidden" name="import_id" value="{{ $focus['import_id'] }}">
                                <input type="hidden" name="decision" x-model="decision">
                                <input type="hidden" name="next_import_id" value="{{ $nextImportId ?? '' }}">
                                <input type="hidden" name="pages_viewed" value="{{ count($focus['page_numbers']) }}">

                                <div class="space-y-4 p-4">
                                    <div class="flex flex-wrap gap-2">
                                        <x-ui.button type="button" variant="success" x-on:click="mark('REVIEWED')">
                                            Tandai Ditinjau <span class="ml-1 text-xs opacity-70">(R)</span>
                                        </x-ui.button>
                                        <x-ui.button type="button" variant="warning" x-on:click="mark('BLOCKED')">
                                            Tahan <span class="ml-1 text-xs opacity-70">(B)</span>
                                        </x-ui.button>
                                        <x-ui.button type="button" variant="secondary" x-on:click="mark('NEEDS_ATTENTION')">
                                            Perlu Perhatian <span class="ml-1 text-xs opacity-70">(P)</span>
                                        </x-ui.button>
                                    </div>

                                    <div x-show="needsReason" x-cloak class="space-y-3 border-t border-hairline pt-4">
                                        <x-ui.select name="reason_code" label="Alasan" x-model="reasonCode">
                                            <option value="">Pilih alasan</option>
                                            @foreach ($triageReasons as $code)
                                                <option value="{{ $code }}">
                                                    {{ \App\Modules\LegacyImport\BatchReview\Support\LegacyBatchReviewReason::label($code) }}
                                                </option>
                                            @endforeach
                                        </x-ui.select>

                                        <x-ui.textarea
                                            name="reason_note"
                                            label="Catatan (wajib bila memilih Alasan lain)"
                                            rows="2"
                                            maxlength="2000"
                                        />

                                        <div class="flex gap-2">
                                            <x-ui.button type="button" x-on:click="confirmTriage()">
                                                Simpan Keputusan
                                            </x-ui.button>
                                            <x-ui.button type="button" variant="secondary" x-on:click="cancelTriage()">
                                                Batal
                                            </x-ui.button>
                                        </div>
                                    </div>

                                    <p class="text-xs text-ink-muted">
                                        Pintasan papan tombol: <strong>R</strong> tandai ditinjau,
                                        <strong>B</strong> tahan, <strong>P</strong> perlu perhatian,
                                        <strong>←</strong> / <strong>→</strong> dokumen sebelumnya / berikutnya.
                                    </p>
                                </div>
                            </form>
                        </x-ui.card>
                    @elseif ($session->isMutable())
                        <x-ui.alert variant="warning">
                            Anda tidak berwenang meninjau dokumen ini. Pada arsip RME, akun yang
                            mengunggah dokumen tidak boleh meninjaunya sendiri — dokumen ini perlu
                            peninjau lain.
                        </x-ui.alert>
                    @endif

                    <div class="flex items-center justify-between">
                        @if ($prevImportId ?? null)
                            <x-ui.button
                                variant="secondary"
                                size="sm"
                                :href="route($routePrefix.'.show', ['session' => $session->uuid, 'import' => $prevImportId])"
                                x-ref="prev"
                            >← Sebelumnya</x-ui.button>
                        @else
                            <span></span>
                        @endif

                        @if ($nextImportId ?? null)
                            <x-ui.button
                                variant="secondary"
                                size="sm"
                                :href="route($routePrefix.'.show', ['session' => $session->uuid, 'import' => $nextImportId])"
                                x-ref="next"
                            >Berikutnya →</x-ui.button>
                        @else
                            <span></span>
                        @endif
                    </div>
                @endif
            </div>
        </div>
    </div>

    @push('scripts')
        <script>
            // Thin keyboard/reason layer over the same form a mouse submits.
            // It cannot express anything the visible buttons cannot, and it
            // never bypasses the server-side reason requirement.
            function legacyBatchReview(config) {
                return {
                    decision: '',
                    reasonCode: '',
                    needsReason: false,
                    mutable: config.mutable,

                    mark(decision) {
                        if (! this.mutable) {
                            return;
                        }

                        this.decision = decision;

                        if (decision === 'REVIEWED') {
                            this.needsReason = false;
                            this.$refs.form.submit();

                            return;
                        }

                        // Withholding requires a reason, so reveal the panel
                        // instead of submitting. The server enforces this too.
                        this.needsReason = true;
                    },

                    confirmTriage() {
                        if (this.reasonCode === '') {
                            return;
                        }

                        this.$refs.form.submit();
                    },

                    cancelTriage() {
                        this.needsReason = false;
                        this.decision = '';
                        this.reasonCode = '';
                    },

                    onKey(event) {
                        // Never hijack typing in the reason note or a filter.
                        const tag = (event.target.tagName || '').toLowerCase();

                        if (tag === 'input' || tag === 'textarea' || tag === 'select') {
                            return;
                        }

                        if (event.metaKey || event.ctrlKey || event.altKey) {
                            return;
                        }

                        const key = event.key.toLowerCase();

                        if (key === 'r') { event.preventDefault(); this.mark('REVIEWED'); return; }
                        if (key === 'b') { event.preventDefault(); this.mark('BLOCKED'); return; }
                        if (key === 'p') { event.preventDefault(); this.mark('NEEDS_ATTENTION'); return; }

                        if (event.key === 'ArrowLeft' && this.$refs.prev) {
                            event.preventDefault();
                            this.$refs.prev.click();

                            return;
                        }

                        if (event.key === 'ArrowRight' && this.$refs.next) {
                            event.preventDefault();
                            this.$refs.next.click();
                        }
                    },
                };
            }
        </script>
    @endpush
</x-settings-shell>
