@php
    // Form-level / auth status *codes* (not human messages). These are rendered
    // inline next to their forms (profile, password, email verification) and must
    // NOT surface as global toasts showing the raw code string.
    $statusCodeDenylist = [
        'profile-updated',
        'password-updated',
        'verification-link-sent',
        'two-factor-authentication-enabled',
        'two-factor-authentication-disabled',
        'recovery-codes-generated',
    ];

    // Map flash session keys to a toast type. `status` is the dominant key used
    // across the app for human-readable success messages.
    $flashTypeMap = [
        'success' => 'success',
        'status'  => 'success',
        'info'    => 'info',
        'message' => 'info',
        'warning' => 'warning',
        'error'   => 'error',
        'danger'  => 'error',
    ];

    $flashToasts = [];
    $seenToasts = [];

    foreach ($flashTypeMap as $sessionKey => $toastType) {
        if (! session()->has($sessionKey)) {
            continue;
        }

        $value = session($sessionKey);

        // Only plain string flash messages become toasts. Structured payloads
        // (e.g. validation bags, import_errors arrays) stay where they are.
        if (! is_string($value) || trim($value) === '') {
            continue;
        }

        if ($sessionKey === 'status' && in_array($value, $statusCodeDenylist, true)) {
            continue;
        }

        // Avoid duplicate repeated messages within the same request/page.
        $dedupeKey = $toastType . '|' . $value;
        if (isset($seenToasts[$dedupeKey])) {
            continue;
        }
        $seenToasts[$dedupeKey] = true;

        $flashToasts[] = [
            'type'    => $toastType,
            'message' => $value,
        ];
    }
@endphp

@if (! empty($flashToasts))
    <div
        x-data="{
            toasts: @js($flashToasts).map((toast, index) => ({ ...toast, id: index, show: false })),
            init() {
                this.$nextTick(() => {
                    this.toasts.forEach((toast) => {
                        toast.show = true;
                        toast.timer = setTimeout(() => this.dismiss(toast), 4000);
                    });
                });
            },
            dismiss(toast) {
                if (toast.timer) {
                    clearTimeout(toast.timer);
                }
                toast.show = false;
                setTimeout(() => {
                    this.toasts = this.toasts.filter((item) => item.id !== toast.id);
                }, 250);
            },
        }"
        class="pointer-events-none fixed inset-x-0 top-0 z-[100] flex flex-col items-center gap-2 px-3 pt-4 sm:inset-x-auto sm:right-4 sm:items-end sm:px-0"
    >
        <template x-for="toast in toasts" :key="toast.id">
            <div
                x-show="toast.show"
                x-transition:enter="transition ease-out duration-200"
                x-transition:enter-start="opacity-0 -translate-y-2 sm:translate-x-2 sm:translate-y-0"
                x-transition:enter-end="opacity-100 translate-y-0 sm:translate-x-0"
                x-transition:leave="transition ease-in duration-150"
                x-transition:leave-start="opacity-100 translate-y-0 sm:translate-x-0"
                x-transition:leave-end="opacity-0 -translate-y-2 sm:translate-x-2 sm:translate-y-0"
                :role="(toast.type === 'error' || toast.type === 'warning') ? 'alert' : 'status'"
                :aria-live="(toast.type === 'error' || toast.type === 'warning') ? 'assertive' : 'polite'"
                class="pointer-events-auto flex w-full max-w-sm items-start gap-3 rounded-lg border bg-white px-4 py-3 text-sm shadow-lg ring-1 ring-black/5"
                :class="{
                    'border-emerald-200 text-emerald-800': toast.type === 'success',
                    'border-blue-200 text-blue-800': toast.type === 'info',
                    'border-amber-200 text-amber-800': toast.type === 'warning',
                    'border-red-200 text-red-800': toast.type === 'error',
                }"
            >
                <span
                    class="mt-0.5 flex h-5 w-5 flex-shrink-0 items-center justify-center rounded-full"
                    :class="{
                        'bg-emerald-100 text-emerald-600': toast.type === 'success',
                        'bg-blue-100 text-blue-600': toast.type === 'info',
                        'bg-amber-100 text-amber-600': toast.type === 'warning',
                        'bg-red-100 text-red-600': toast.type === 'error',
                    }"
                    aria-hidden="true"
                >
                    <template x-if="toast.type === 'success'">
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                    </template>
                    <template x-if="toast.type === 'error'">
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                    </template>
                    <template x-if="toast.type === 'warning' || toast.type === 'info'">
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m0 3.75h.008M12 3l9 15.75H3L12 3z" x-show="toast.type === 'warning'" /><path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25h.75v4.5m-.75 0h1.5M12 7.5h.008" x-show="toast.type === 'info'" /></svg>
                    </template>
                </span>

                <p class="min-w-0 flex-1 break-words" x-text="toast.message"></p>

                <button
                    type="button"
                    @click="dismiss(toast)"
                    class="-mr-1 -mt-1 flex-shrink-0 rounded p-1 text-gray-400 transition hover:bg-gray-100 hover:text-gray-600 focus:outline-none focus:ring-2 focus:ring-gray-300"
                    aria-label="Tutup notifikasi"
                >
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                </button>
            </div>
        </template>
    </div>
@endif
