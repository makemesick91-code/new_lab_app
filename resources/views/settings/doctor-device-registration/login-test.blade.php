{{--
    Step 5 — Uji Login.

    A REPORT, NOT A CONTROL. There is deliberately no button on this page that
    records a pass, and no route that could: the verdict comes from audit rows
    written by the login path itself, attributed to the device the assertion was
    actually performed on. An operator cannot declare this step finished; only a
    real doctor completing a real WebAuthn login on this tablet can.
--}}
<x-doctor-device-registration-shell :device="$device" :steps="$steps" active-step="login-test">
    @php
        $proofSvc = App\Modules\DoctorDevice\Services\DoctorWebAuthnLiveProofService::class;

        $proof = $readinessReport['proof'];
        $verdict = $proof['live_assertion_proof'] ?? $proofSvc::PROOF_NEVER_PROVEN;

        $verdictTone = [
            $proofSvc::PROOF_PASS => 'success',
            $proofSvc::PROOF_STALE => 'warning',
            $proofSvc::PROOF_NEVER_PROVEN => 'neutral',
            $proofSvc::PROOF_UNVERIFIED => 'warning',
        ];
        $verdictLabel = [
            $proofSvc::PROOF_PASS => 'Terbukti dan masih segar',
            $proofSvc::PROOF_STALE => 'Pernah terbukti, sudah kedaluwarsa',
            $proofSvc::PROOF_NEVER_PROVEN => 'Belum pernah ada login berhasil',
            $proofSvc::PROOF_UNVERIFIED => 'Tidak dapat diukur',
        ];
    @endphp

    <x-ui.card>
        <h3 class="text-base font-semibold text-ink">Langkah 5 — Uji Login</h3>

        <div class="mt-4 flex flex-wrap items-center gap-3">
            <x-ui.badge :tone="$verdictTone[$verdict] ?? 'neutral'">{{ $verdictLabel[$verdict] ?? $verdict }}</x-ui.badge>
            @if (($proof['reason'] ?? null))
                <span class="text-sm text-ink-muted">{{ $proof['reason'] }}</span>
            @endif
        </div>

        <x-ui.alert variant="info" class="mt-4">
            Status di atas <strong>diturunkan dari jejak audit login</strong>. Tidak ada tombol untuk menandai
            langkah ini berhasil — uji login harus benar-benar dilakukan di tablet ini.
        </x-ui.alert>

        <dl class="mt-4 grid gap-3 text-sm md:grid-cols-2">
            <div>
                <dt class="text-ink-muted">Bukti terakhir</dt>
                <dd class="text-ink">{{ $proof['last_proof_local'] ?? $proof['last_proof_utc'] ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-ink-muted">Usia bukti</dt>
                <dd class="text-ink">
                    {{ $proof['proof_age_days'] === null ? '—' : $proof['proof_age_days'].' hari' }}
                </dd>
            </div>
            <div>
                <dt class="text-ink-muted">Jendela kesegaran</dt>
                <dd class="text-ink">
                    {{ $proof['freshness_window_days'] === null ? 'Belum dikonfigurasi' : $proof['freshness_window_days'].' hari' }}
                </dd>
            </div>
            <div>
                <dt class="text-ink-muted">Kredensial dapat dipakai</dt>
                <dd class="text-ink">{{ $readinessReport['usable_credentials'] }}</dd>
            </div>
        </dl>
    </x-ui.card>

    <x-ui.card>
        <h3 class="text-base font-semibold text-ink">Cara Menguji</h3>

        @if ($authorizations->isEmpty())
            <x-ui.alert variant="warning" class="mt-4">
                Belum ada dokter berstatus aktif pada perangkat ini, sehingga uji login belum dapat dilakukan.
                <a class="font-semibold underline"
                   href="{{ route('settings.doctor-device-registration.doctors', $device) }}">
                    Kembali ke Authorization Dokter
                </a>
            </x-ui.alert>
        @else
            <ol class="mt-4 list-decimal space-y-2 pl-5 text-sm text-ink-soft">
                <li>Buka aplikasi dokter <strong>pada tablet ini</strong>.</li>
                <li>Masuk sebagai salah satu dokter yang tercantum di bawah.</li>
                <li>Selesaikan verifikasi WebAuthn di perangkat (sidik jari / PIN perangkat).</li>
                <li>Muat ulang halaman ini — status di atas akan berubah dengan sendirinya bila login berhasil.</li>
            </ol>

            <div class="mt-4 overflow-x-auto">
                <x-ui.table>
                    <thead><tr><th>Dokter</th><th>Status Otorisasi</th></tr></thead>
                    <tbody>
                        @foreach ($authorizations as $authorization)
                            <tr>
                                <td class="font-medium text-ink">{{ $authorization->doctor?->name ?? '—' }}</td>
                                <td><x-ui.badge tone="success">{{ $authorization->status }}</x-ui.badge></td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-ui.table>
            </div>
        @endif

        <div class="mt-5">
            <x-ui.button variant="secondary" :href="route('settings.doctor-device-registration.readiness', $device)">
                Lanjut ke Verifikasi Readiness
            </x-ui.button>
        </div>
    </x-ui.card>
</x-doctor-device-registration-shell>
