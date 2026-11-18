{{--
    DMS memo M4.4 / M1.x, PTM PR5 (rule 242 §10) — "Foto Klinis" of this visit.

    Rendered only when the controller passes a gallery (ClinicalPhotoGalleryPresenter):
    never while `rme.clinical_photos` is OFF, never for an account that may neither
    read nor upload this visit's photos. Every flag below was decided there through
    RmeClinicalPhotoService; this partial only renders, and the upload, image and
    void routes decide everything again.

    - The upload form exists only when the server would accept it (rule 135); an
      uploader refused by the encounter state sees the server's reason and a
      disabled control instead.
    - Images come only from the private, no-store image route (each view audited),
      lazily; never a public URL, never inlined.
    - The void form (a confirm step) is per photo and only when the viewer holds the
      void permission and did not upload it (maker ≠ checker, decided server-side).
    - "Foto dibatalkan" is the holders' list; the reason is shown only there (§7).
--}}
<x-ui.card title="Foto Klinis" description="Dokumentasi klinis kunjungan ini. Foto bersifat rahasia dan hanya dapat dibuka oleh tenaga klinis yang berwenang." data-testid="clinical-photos-card">
    @if (($errors ?? null)?->hasAny(['photo', 'void_reason']))
        <x-ui.alert variant="danger" class="mb-4">
            {{ $errors->first('photo') ?: $errors->first('void_reason') }}
        </x-ui.alert>
    @endif

    @if ($gallery['canUpload'])
        <form method="POST" action="{{ route('rme.visits.clinical-photos.store', $visit) }}" enctype="multipart/form-data" class="space-y-2" data-testid="clinical-photo-upload-form">
            @csrf
            <label for="clinical-photo-input" class="block text-sm font-medium text-navy">
                Tambah foto klinis<span class="text-danger"> *</span>
            </label>
            <input
                id="clinical-photo-input"
                type="file"
                name="photo"
                accept="image/jpeg,image/png"
                required
                aria-required="true"
                aria-describedby="clinical-photo-input-help"
                class="block w-full rounded-lg border border-hairline bg-surface px-3 py-2 text-sm text-ink file:mr-3 file:rounded-md file:border-0 file:bg-brand-50 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-brand-700"
            >
            <p id="clinical-photo-input-help" class="text-xs text-ink-muted">
                JPEG atau PNG, maksimal 15 MB. Foto disimpan ulang sebagai JPEG; data lokasi dan metadata kamera dihapus.
            </p>
            <x-ui.button type="submit" variant="primary">Unggah Foto</x-ui.button>
        </form>
    @elseif ($gallery['uploadRefusal'])
        <div data-testid="clinical-photo-upload-refused">
            <x-ui.button type="button" variant="primary" disabled>Unggah Foto</x-ui.button>
            <p class="mt-2 text-xs text-ink-soft">{{ $gallery['uploadRefusal'] }}</p>
        </div>
    @endif

    @if ($gallery['canView'])
        <div class="mt-4" data-testid="clinical-photos-gallery">
            @if ($gallery['photos'] === [])
                <p class="text-sm text-ink-muted">Belum ada foto klinis untuk kunjungan ini.</p>
            @else
                <ul class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                    @foreach ($gallery['photos'] as $photo)
                        <li class="space-y-1">
                            <a href="{{ route('rme.clinical-photos.image', $photo['id']) }}" target="_blank" rel="noopener" class="block rounded-lg focus:outline-none focus:ring-2 focus:ring-brand-500">
                                <img
                                    src="{{ route('rme.clinical-photos.image', $photo['id']) }}"
                                    alt="Foto klinis #{{ $photo['id'] }}"
                                    width="{{ $photo['width'] }}"
                                    height="{{ $photo['height'] }}"
                                    loading="lazy"
                                    decoding="async"
                                    class="h-32 w-full rounded-lg border border-hairline bg-navy-50 object-cover"
                                >
                            </a>
                            <p class="text-xs font-medium text-ink">{{ $photo['uploader'] }}</p>
                            <p class="text-xs text-ink-muted">{{ $photo['uploadedAt'] }}</p>
                            @if ($photo['mayVoid'])
                                <x-ui.modal title="Batalkan foto klinis #{{ $photo['id'] }}">
                                    <x-slot:trigger>
                                        <x-ui.button type="button" variant="secondary" size="sm">Batalkan foto…</x-ui.button>
                                    </x-slot:trigger>
                                    <form method="POST" action="{{ route('rme.clinical-photos.void', $photo['id']) }}" class="space-y-3" data-testid="clinical-photo-void-confirm">
                                        @csrf
                                        <p class="text-sm text-ink-soft">
                                            Foto tidak dihapus: foto ditandai dibatalkan beserta alasan dan akun Anda, keluar dari
                                            galeri, dan hanya dapat dilihat oleh pemegang wewenang pembatalan. Pembatalan tidak dapat diulang.
                                        </p>
                                        <label for="clinical-photo-void-reason-{{ $photo['id'] }}" class="block text-sm font-medium text-navy">
                                            Alasan pembatalan<span class="text-danger"> *</span>
                                        </label>
                                        <textarea
                                            id="clinical-photo-void-reason-{{ $photo['id'] }}"
                                            name="void_reason"
                                            rows="3"
                                            required
                                            aria-required="true"
                                            minlength="10"
                                            maxlength="1000"
                                            class="block w-full rounded-lg border-hairline text-sm"
                                        ></textarea>
                                        <div class="flex flex-wrap justify-end gap-2">
                                            <x-ui.button type="button" variant="secondary" x-on:click="open = false">Batal</x-ui.button>
                                            <x-ui.button type="submit" variant="danger">Ya, batalkan foto</x-ui.button>
                                        </div>
                                    </form>
                                </x-ui.modal>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif

    @if ($gallery['voided'] !== [])
        <section class="mt-6 border-t border-hairline pt-4" data-testid="clinical-photos-voided">
            <h4 class="text-sm font-semibold text-navy">Foto dibatalkan</h4>
            <p class="mt-1 text-xs text-ink-muted">
                Hanya terlihat oleh pemegang wewenang pembatalan foto klinis. Foto tidak dihapus; setiap pembukaan dicatat.
            </p>
            <ul class="mt-3 divide-y divide-hairline">
                @foreach ($gallery['voided'] as $photo)
                    <li class="space-y-1 py-3 text-sm">
                        <a href="{{ route('rme.clinical-photos.image', $photo['id']) }}" target="_blank" rel="noopener" class="font-medium text-brand-700 hover:text-brand-800">
                            Lihat foto #{{ $photo['id'] }}
                        </a>
                        <p class="text-xs text-ink-soft">Diunggah oleh {{ $photo['uploader'] }}, {{ $photo['uploadedAt'] }}</p>
                        <p class="text-xs text-ink-soft">Dibatalkan oleh {{ $photo['voider'] }}, {{ $photo['voidedAt'] }}</p>
                        <p class="text-xs text-ink">Alasan: {{ $photo['reason'] }}</p>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</x-ui.card>
