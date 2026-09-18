{{--
    DOCTOR-PWA-WEBAUTHN-1 — the second step of a doctor's browser login.

    There is no authenticated user on this page, deliberately: the password step
    has been torn down and only a short-lived marker names the account. Nothing
    here grants access; the assertion posted from this form does, and only after
    the server has verified the signature, the device, the doctor authorization
    and the credential.
--}}
<x-guest-layout>
    <div class="mb-4">
        <h2 class="text-lg font-semibold text-ink">Verifikasi Perangkat Klinik</h2>
        <p class="mt-1 text-sm text-ink-soft">
            {{ $userName }}, sentuh sensor sidik jari atau masukkan PIN perangkat untuk melanjutkan.
        </p>
    </div>

    <x-auth-session-status class="mb-4" :status="session('status')" />

    @error('credential')
        <div class="mb-4 rounded-lg bg-danger-50 p-3 text-sm text-danger-700">{{ $message }}</div>
    @enderror

    @error('email')
        <div class="mb-4 rounded-lg bg-danger-50 p-3 text-sm text-danger-700">{{ $message }}</div>
    @enderror

    <form method="POST" action="{{ route('doctor-device-webauthn.store') }}"
          data-webauthn-assert
          data-options-url="{{ route('doctor-device-webauthn.options') }}">
        @csrf
        <input type="hidden" name="credential[id]">
        <input type="hidden" name="credential[rawId]">
        <input type="hidden" name="credential[type]">
        <input type="hidden" name="credential[response][clientDataJSON]">
        <input type="hidden" name="credential[response][authenticatorData]">
        <input type="hidden" name="credential[response][signature]">
        <input type="hidden" name="credential[response][userHandle]">

        <button type="submit" data-webauthn-start
                class="w-full rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">
            Verifikasi Perangkat
        </button>

        <p class="mt-3 text-sm text-danger-700 hidden" data-webauthn-error></p>
    </form>

    <p class="mt-6 text-center text-sm">
        <a href="{{ route('login') }}" class="text-ink-soft underline">Kembali ke halaman masuk</a>
    </p>

    @push('scripts')
        <script>
            document.querySelectorAll('form[data-webauthn-assert]').forEach((form) => {
                const button = form.querySelector('[data-webauthn-start]');
                const error = form.querySelector('[data-webauthn-error]');

                const run = async (event) => {
                    if (form.dataset.webauthnReady === '1') {
                        return;
                    }

                    if (event) {
                        event.preventDefault();
                    }

                    error.classList.add('hidden');

                    if (!window.doctorDeviceWebAuthn?.isSupported()) {
                        error.textContent = 'Browser ini tidak mendukung verifikasi perangkat. (kode: unsupported)';
                        error.classList.remove('hidden');
                        return;
                    }

                    button.disabled = true;

                    try {
                        const credential = await window.doctorDeviceWebAuthn.assert(form.dataset.optionsUrl);

                        form.querySelector('[name="credential[id]"]').value = credential.id;
                        form.querySelector('[name="credential[rawId]"]').value = credential.rawId;
                        form.querySelector('[name="credential[type]"]').value = credential.type;
                        form.querySelector('[name="credential[response][clientDataJSON]"]').value =
                            credential.response.clientDataJSON;
                        form.querySelector('[name="credential[response][authenticatorData]"]').value =
                            credential.response.authenticatorData;
                        form.querySelector('[name="credential[response][signature]"]').value =
                            credential.response.signature;
                        form.querySelector('[name="credential[response][userHandle]"]').value =
                            credential.response.userHandle ?? '';

                        form.dataset.webauthnReady = '1';
                        form.submit();
                    } catch (e) {
                        /*
                         * BUGFIX-DOCTOR-PWA-WEBAUTHN-VERIFY-DEVICE-NO-FEEDBACK-2
                         *
                         * Every failure now says something DIFFERENT and
                         * something ACTIONABLE. The previous single message
                         * collapsed a missing credential, an untrusted origin,
                         * an expired session and a hung authenticator into one
                         * sentence, so an operator could never tell which had
                         * happened — and when the ceremony hung instead of
                         * rejecting, this block never ran at all.
                         *
                         * The reason CODE is appended deliberately. It is the
                         * one thing an operator can quote in an incident report
                         * without describing the clinic's device estate, and its
                         * absence is why the last incident had to be diagnosed
                         * from nginx logs.
                         */
                        const reasons = {
                            timeout: 'Verifikasi perangkat tidak selesai dalam 60 detik. Coba lagi, atau gunakan perangkat tepercaya lain.',
                            no_credential_or_denied: 'Perangkat ini belum memiliki kredensial dokter yang terdaftar, atau verifikasi dibatalkan. Daftarkan perangkat ini lebih dahulu bila memang belum terdaftar.',
                            ceremony_cancelled: 'Verifikasi perangkat dibatalkan.',
                            origin_not_trusted: 'Alamat halaman ini tidak dipercaya untuk verifikasi perangkat. Buka aplikasi dari alamat resmi klinik.',
                            device_state_invalid: 'Perangkat ini sedang tidak dapat dipakai untuk verifikasi.',
                            unsupported: 'Browser ini tidak mendukung verifikasi perangkat.',
                            session_expired: 'Sesi login telah berakhir. Silakan masuk kembali.',
                            options_rejected: 'Server menolak permintaan verifikasi. Hubungi admin bila berulang.',
                            network_unavailable: 'Tidak dapat menghubungi server. Periksa koneksi lalu coba lagi.',
                        };

                        const reason = e && e.reason
                            ? e.reason
                            : (e && e.status === 419 ? 'session_expired' : 'unexpected');

                        error.textContent = (reasons[reason] || 'Verifikasi perangkat gagal.')
                            + ' (kode: ' + reason + ')';
                        error.classList.remove('hidden');

                        // Always recoverable. A disabled button with no message
                        // is the exact state that made this look like a dead
                        // control rather than a failed ceremony.
                        button.disabled = false;
                    }
                };

                form.addEventListener('submit', run);
            });
        </script>
    @endpush
</x-guest-layout>
