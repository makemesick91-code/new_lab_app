{{--
    REVISION-DOCTOR-PWA-WEBAUTHN-ONLY-ACCESS-1 Stage 2 — the emergency-access console.

    Canonical authenticated shell, so the sidebar and the script stack come with
    it: x-settings-shell -> x-app-layout -> layouts/app.

    NOTHING ON THIS SCREEN IS A SECURITY BOUNDARY. The route middleware and the
    service both check `grant_doctor_break_glass_access`; hiding a button here
    would stop nobody. It shows no credential, no key material and no session
    identifier — only who was admitted, by whom, why, until when, and whether it
    is still open.
--}}
<x-settings-shell title="Akses Darurat Dokter">
    <div class="space-y-6">
        <x-ui.page-header title="Akses Darurat Dokter">
            <x-slot:breadcrumb>Konteks Kerja — Akses Darurat Dokter</x-slot:breadcrumb>
            <x-slot:subtitle>
                Memberi satu dokter akses sementara tanpa perangkat tepercaya, saat tablet
                cabang tidak tersedia. Berbatas waktu, wajib beralasan, dapat dicabut, dan
                seluruhnya teraudit. Ini <strong>bukan</strong> mengembalikan login kata sandi
                untuk semua dokter: dokter lain tetap wajib memakai perangkat tepercaya.
            </x-slot:subtitle>
        </x-ui.page-header>

        @if (session('success'))
            <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
        @endif

        @if ($errors->any())
            <x-ui.alert variant="danger">
                <ul class="list-disc space-y-1 pl-5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </x-ui.alert>
        @endif

        <x-ui.card>
            <x-slot:title>Beri akses darurat</x-slot:title>

            <x-ui.alert variant="warning" class="mb-4">
                Gunakan hanya bila jalur perangkat tepercaya benar-benar tidak tersedia.
                Jendela maksimal {{ $maxHours }} jam dan akan tertutup sendiri — tidak ada
                yang perlu dijalankan untuk menutupnya.
            </x-ui.alert>

            <form method="POST" action="{{ route('rme.doctor-break-glass.store') }}" class="space-y-4">
                @csrf

                <div class="grid gap-4 md:grid-cols-2">
                    <x-ui.select name="user_id" label="Dokter" required>
                        <option value="">— pilih dokter —</option>
                        @foreach ($doctorAccounts as $account)
                            <option value="{{ $account->id }}" @selected(old('user_id') == $account->id)>
                                {{ $account->name }}
                            </option>
                        @endforeach
                    </x-ui.select>

                    <x-ui.input
                        type="number"
                        name="hours"
                        label="Durasi (jam)"
                        min="1"
                        max="{{ $maxHours }}"
                        :value="old('hours', 4)"
                        required
                    />
                </div>

                <x-ui.textarea
                    name="reason"
                    label="Alasan"
                    rows="3"
                    required
                    placeholder="Contoh: tablet SPN4 mati total pukul 09.15, pasien menunggu, unit pengganti belum tiba."
                >{{ old('reason') }}</x-ui.textarea>

                <p class="text-xs text-gray-500">
                    Minimal {{ $reasonMin }} karakter. Alasan ini masuk ke jejak audit dan
                    dibaca saat peninjauan insiden.
                </p>

                <x-ui.button type="submit" variant="warning">Beri akses darurat</x-ui.button>
            </form>
        </x-ui.card>

        <x-ui.card>
            <x-slot:title>Riwayat akses darurat</x-slot:title>

            @if ($grants->isEmpty())
                <x-ui.empty-state
                    title="Belum ada akses darurat"
                    description="Tidak ada akses darurat yang pernah diberikan pada deployment ini."
                />
            @else
                <x-ui.table>
                    <x-slot:head>
                        <tr>
                            <th class="px-4 py-2 text-left">Dokter</th>
                            <th class="px-4 py-2 text-left">Status</th>
                            <th class="px-4 py-2 text-left">Diberikan oleh</th>
                            <th class="px-4 py-2 text-left">Berlaku sampai</th>
                            <th class="px-4 py-2 text-left">Alasan</th>
                            <th class="px-4 py-2 text-left">Aksi</th>
                        </tr>
                    </x-slot:head>

                    @foreach ($grants as $grant)
                        @php($state = $grant->state())
                        <tr class="border-t border-hairline align-top">
                            <td class="px-4 py-3">
                                <div class="font-medium">{{ $grant->user?->name ?? '—' }}</div>
                                <div class="text-xs text-gray-500">
                                    Diberikan {{ $grant->granted_at?->format('d/m/Y H:i') }}
                                    @if ($grant->first_used_at)
                                        · dipakai {{ $grant->first_used_at->format('d/m/Y H:i') }}
                                    @else
                                        · belum dipakai
                                    @endif
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <x-ui.badge :tone="match ($state) {
                                    'active' => 'warning',
                                    'revoked' => 'danger',
                                    default => 'secondary',
                                }">
                                    {{ ['active' => 'Aktif', 'revoked' => 'Dicabut', 'expired' => 'Kedaluwarsa'][$state] }}
                                </x-ui.badge>
                            </td>
                            <td class="px-4 py-3 text-sm">{{ $grant->grantedBy?->name ?? '—' }}</td>
                            <td class="px-4 py-3 text-sm">{{ $grant->expires_at?->format('d/m/Y H:i') }}</td>
                            <td class="px-4 py-3 text-sm">
                                {{ $grant->reason }}
                                @if ($grant->isRevoked())
                                    <div class="mt-1 text-xs text-gray-500">
                                        Dicabut oleh {{ $grant->revokedBy?->name ?? '—' }}:
                                        {{ $grant->revoked_reason }}
                                    </div>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                @if ($state === 'active')
                                    <form
                                        method="POST"
                                        action="{{ route('rme.doctor-break-glass.revoke', $grant) }}"
                                        class="space-y-2"
                                    >
                                        @csrf
                                        <x-ui.input
                                            name="reason"
                                            placeholder="Alasan pencabutan"
                                            required
                                        />
                                        <x-ui.button type="submit" variant="danger" size="sm">
                                            Cabut
                                        </x-ui.button>
                                    </form>
                                @else
                                    <span class="text-xs text-gray-400">—</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </x-ui.table>

                <div class="mt-4">{{ $grants->links() }}</div>
            @endif
        </x-ui.card>
    </div>
</x-settings-shell>
