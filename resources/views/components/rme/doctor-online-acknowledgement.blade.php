@props([
    'name' => 'dokter ini',
    'field' => 'ack',
])

{{--
    DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1 — ruling P11's second
    confirmation, rendered only when the subject doctor is ONLINE right now.

    THE CHECKBOX IS UI, NOT THE BOUNDARY. `DoctorAccessSubjectGuard::
    assertOnlineImpactAcknowledged()` re-reads presence inside the approval
    transaction and refuses when the doctor is online and the acknowledgement is
    absent, so a screen drawn before the doctor came online cannot approve past
    them. `required` here only saves the operator a round trip.
--}}
<x-ui.alert variant="danger">
    <p class="font-semibold">{{ $name }} sedang online.</p>
    <label for="ack-{{ $field }}" class="mt-2 flex items-start gap-2 text-sm">
        <input type="checkbox" id="ack-{{ $field }}" name="acknowledge_online_impact" value="1" required
            class="mt-0.5 rounded border-hairline text-brand-600 focus:ring-brand-500" />
        <span>
            Saya mengerti dokter ini sedang online. Menyetujui sekarang akan mengakhiri sesinya,
            dan input rekam medis tulisan tangan atau odontogram yang belum disimpan dapat hilang.
        </span>
    </label>
</x-ui.alert>
