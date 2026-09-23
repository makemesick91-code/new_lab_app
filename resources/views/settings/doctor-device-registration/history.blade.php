{{--
    Riwayat — what this tablet's trust has actually done.

    Security history, so nothing here is ever deleted: a revoked credential and
    a revoked authorization both stay listed. No credential material is
    rendered — the public key and the WebAuthn handle are not operator data.
--}}
<x-doctor-device-registration-shell :device="$device" :steps="$steps" active-step="riwayat">
    @php
        $auth = App\Modules\DoctorDevice\Models\DoctorDeviceAuthorization::class;

        $statusTone = [
            $auth::STATUS_ACTIVE => 'success',
            $auth::STATUS_PENDING => 'warning',
            $auth::STATUS_REJECTED => 'danger',
            $auth::STATUS_REVOKED => 'danger',
        ];
    @endphp

    <x-ui.card>
        <h3 class="text-base font-semibold text-ink">Riwayat Otorisasi Dokter</h3>

        @if ($authorizations->isEmpty())
            <x-ui.empty-state class="mt-4" title="Belum ada riwayat"
                              description="Belum ada dokter yang pernah diajukan pada perangkat ini." />
        @else
            <div class="mt-4 overflow-x-auto">
                <x-ui.table>
                    <thead>
                        <tr><th>Dokter</th><th>Status</th><th>Sumber</th><th>Diajukan</th><th>Diputuskan</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($authorizations as $authorization)
                            <tr>
                                <td class="font-medium text-ink">{{ $authorization->doctor?->name ?? '—' }}</td>
                                <td>
                                    <x-ui.badge :tone="$statusTone[$authorization->status] ?? 'neutral'">
                                        {{ $authorization->status }}
                                    </x-ui.badge>
                                </td>
                                <td class="text-sm text-ink-soft">{{ $authorization->request_source }}</td>
                                <td class="text-sm text-ink-soft">{{ $authorization->requested_at?->format('d/m/Y H:i') ?? '—' }}</td>
                                <td class="text-sm text-ink-soft">
                                    {{ $authorization->approved_at?->format('d/m/Y H:i')
                                        ?? $authorization->revoked_at?->format('d/m/Y H:i')
                                        ?? '—' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-ui.table>
            </div>
        @endif
    </x-ui.card>

    <x-ui.card>
        <h3 class="text-base font-semibold text-ink">Riwayat Kredensial</h3>

        @if ($credentials->isEmpty())
            <x-ui.empty-state class="mt-4" title="Belum ada kredensial" description="—" />
        @else
            <div class="mt-4 overflow-x-auto">
                <x-ui.table>
                    <thead>
                        <tr><th>Status Ikatan</th><th>Verifikasi Pengguna</th><th>Didaftarkan</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($credentials as $credential)
                            <tr>
                                <td class="text-sm text-ink-soft">{{ $credential->device_bound_verdict }}</td>
                                <td>{{ $credential->user_verified ? 'Ya' : 'Tidak' }}</td>
                                <td class="text-sm text-ink-soft">{{ $credential->registered_at?->format('d/m/Y H:i') ?? '—' }}</td>
                                <td>
                                    <x-ui.badge :tone="$credential->isRevoked() ? 'danger' : 'success'">
                                        {{ $credential->isRevoked() ? 'Dicabut' : 'Aktif' }}
                                    </x-ui.badge>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-ui.table>
            </div>
        @endif
    </x-ui.card>
</x-doctor-device-registration-shell>
