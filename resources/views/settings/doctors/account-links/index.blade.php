{{--
    FEATURE-DOCTOR-ACCOUNT-PERFORMANCE-INCOME-LINKAGE-1
    Master Data → Relasi Akun Dokter.

    Presentation only. Every eligibility rule (active account, Doctor role,
    one-to-one, explicit relink) is enforced server-side by
    DoctorAccountLinkService — this page only offers what the server already
    considers eligible.
--}}
<x-settings-shell title="Relasi Akun Dokter">
    <div class="space-y-4">
        <x-ui.alert variant="info">
            Menghubungkan akun login ke data dokter menentukan <strong>kinerja</strong> dan
            <strong>pendapatan</strong> siapa yang dapat dibaca akun tersebut. Identitas dokter tidak pernah
            ditebak dari nama atau email — hubungan ini harus dipilih secara eksplisit.
            Menghubungkan akun tidak mengubah riwayat kunjungan, rekam medis, maupun data keuangan yang sudah ada.
        </x-ui.alert>

        @if (session('status'))
            <x-ui.alert variant="success">{{ session('status') }}</x-ui.alert>
        @endif

        @if ($errors->any())
            <x-ui.alert variant="danger">{{ $errors->first() }}</x-ui.alert>
        @endif

        <x-ui.card>
            <form method="GET" action="{{ route('settings.doctors.account-links.index') }}"
                  class="flex flex-wrap items-end gap-3">
                <div class="w-full sm:w-64">
                    <x-ui.input name="search" label="Cari Dokter" :value="$search"
                                placeholder="Nama atau kode dokter" />
                </div>
                <div class="w-full sm:w-56">
                    <x-ui.select name="link_status" label="Status Hubungan">
                        <option value="">Semua</option>
                        <option value="linked" @selected($linkStatus === 'linked')>Sudah terhubung</option>
                        <option value="unlinked" @selected($linkStatus === 'unlinked')>Belum terhubung</option>
                    </x-ui.select>
                </div>
                <x-ui.button type="submit" variant="primary">Terapkan</x-ui.button>
                @if ($search || $linkStatus)
                    <a href="{{ route('settings.doctors.account-links.index') }}"
                       class="text-sm text-ink-soft hover:text-ink">Atur Ulang</a>
                @endif
            </form>
        </x-ui.card>

        <x-ui.card>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-hairline text-sm">
                    <thead class="bg-navy-50">
                        <tr class="text-left text-ink-soft">
                            <th class="px-3 py-2 font-medium">Dokter</th>
                            <th class="px-3 py-2 font-medium">Akun Login</th>
                            <th class="px-3 py-2 font-medium">Status Link</th>
                            <th class="px-3 py-2 font-medium text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-hairline">
                        @forelse ($doctors as $doctor)
                            <tr>
                                <td class="px-3 py-3 align-top">
                                    <div class="font-medium text-ink">{{ $doctor->name }}</div>
                                    <div class="text-xs text-ink-muted">{{ $doctor->code }}</div>
                                </td>

                                <td class="px-3 py-3 align-top">
                                    @if ($doctor->user)
                                        <div class="text-ink">{{ $doctor->user->name }}</div>
                                        <div class="text-xs text-ink-muted">{{ $doctor->user->email }}</div>
                                    @else
                                        <span class="text-ink-muted">—</span>
                                    @endif
                                </td>

                                <td class="px-3 py-3 align-top">
                                    @if ($doctor->user)
                                        <x-ui.badge tone="success">Terhubung</x-ui.badge>
                                    @else
                                        <x-ui.badge tone="warning">Belum Terhubung</x-ui.badge>
                                    @endif
                                </td>

                                <td class="px-3 py-3 align-top">
                                    <div class="flex flex-col items-stretch gap-2 sm:items-end">
                                        <form method="POST"
                                              action="{{ route('settings.doctors.account-links.store', $doctor) }}"
                                              data-link-form="{{ $doctor->id }}"
                                              class="flex flex-wrap items-center justify-end gap-2">
                                            @csrf
                                            <select name="user_id" required
                                                    class="rounded-md border-hairline text-sm focus:border-brand-500 focus:ring-brand-500">
                                                <option value="">— Pilih akun —</option>
                                                @foreach ($candidates as $candidate)
                                                    <option value="{{ $candidate->id }}">{{ $candidate->name }} ({{ $candidate->email }})</option>
                                                @endforeach
                                            </select>

                                            @if ($doctor->user)
                                                <label class="flex items-center gap-1 text-xs text-ink-soft">
                                                    <input type="checkbox" name="confirm_relink" value="1"
                                                           class="rounded border-hairline text-brand-600 focus:ring-brand-500" />
                                                    Ganti akun
                                                </label>
                                            @endif

                                            <x-ui.button type="submit" variant="primary" size="sm">
                                                {{ $doctor->user ? 'Ganti' : 'Hubungkan' }}
                                            </x-ui.button>
                                        </form>

                                        @if ($doctor->user)
                                            <form method="POST"
                                                  action="{{ route('settings.doctors.account-links.destroy', $doctor) }}"
                                                  data-unlink-form="{{ $doctor->id }}"
                                                  onsubmit="return confirm('Putuskan hubungan akun login dengan dokter ini? Riwayat klinis dan keuangan tidak dihapus.');">
                                                @csrf @method('DELETE')
                                                <x-ui.button type="submit" variant="danger" size="sm">Putuskan</x-ui.button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-3 py-6">
                                    <x-ui.empty-state title="Belum ada data dokter"
                                                      description="Tambahkan dokter melalui menu Dokter terlebih dahulu." />
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($candidates->isEmpty())
                <p class="mt-3 text-sm text-ink-muted">
                    Tidak ada akun yang memenuhi syarat. Akun harus aktif, memiliki role Doctor, dan belum
                    terhubung ke dokter lain.
                </p>
            @endif

            <div class="mt-4">{{ $doctors->links() }}</div>
        </x-ui.card>
    </div>
</x-settings-shell>
