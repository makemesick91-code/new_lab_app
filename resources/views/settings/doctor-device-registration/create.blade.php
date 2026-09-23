{{--
    Step 1 for a tablet that does not exist yet.

    Posts to the EXISTING device store route with the existing form partial, so
    the validation, the branch scoping and the PENDING_APPROVAL landing state
    are unchanged. The hidden flag only asks that endpoint to return here
    instead of to the device registry — it is a boolean, never a URL.
--}}
<x-settings-shell title="Daftarkan Device Dokter Baru">
    <x-ui.card>
        <h2 class="text-lg font-semibold text-ink">Langkah 1 — Data Device</h2>
        <p class="mt-1 text-sm text-ink-soft">
            Catat tablet klinik yang akan didaftarkan. Perangkat akan tersimpan dengan status
            <em>pending_approval</em> dan belum dapat dipakai login oleh siapa pun.
        </p>

        <form method="POST" action="{{ route('settings.doctor-devices.store') }}" class="mt-5">
            @csrf
            <input type="hidden" name="registration_workflow" value="1">

            @include('settings.doctor-devices._form', ['device' => null, 'branches' => $branches])

            <div class="mt-5 flex gap-2">
                <x-ui.button type="submit" variant="primary">Simpan dan Lanjutkan</x-ui.button>
                <x-ui.button variant="ghost" :href="route('settings.doctor-device-registration.index')">Batal</x-ui.button>
            </div>
        </form>
    </x-ui.card>
</x-settings-shell>
