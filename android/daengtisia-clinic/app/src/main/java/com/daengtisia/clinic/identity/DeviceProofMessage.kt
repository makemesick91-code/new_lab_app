package com.daengtisia.clinic.identity

/**
 * The canonical message the device signs.
 *
 * MUST stay byte-identical to the server's
 * `App\Modules\DoctorDevice\Support\DeviceProofMessage`. A fixed, pipe
 * delimited, versioned string is used rather than JSON precisely because JSON
 * key ordering and whitespace are not guaranteed across platforms, and a
 * signature over a non-deterministic encoding is a verification bug waiting to
 * happen.
 *
 *     daengtisiams-device-proof|v1|<purpose>|<nonce>|<fingerprint>
 *
 * Changing this format is a protocol version bump on BOTH sides, not an edit.
 */
object DeviceProofMessage {

    const val PREFIX = "daengtisiams-device-proof"
    const val VERSION = "v1"

    const val PURPOSE_ENROLLMENT = "enrollment"
    const val PURPOSE_DEVICE_PROOF = "device_proof"

    /**
     * REVISION-DOCTOR-AUTO-DEVICE-APPROVAL-APP-ONLY-LOGIN-1 — a doctor login
     * attempt. A DISTINCT purpose so a signature captured from an ongoing
     * device proof cannot be replayed as a login, or the other way round.
     */
    const val PURPOSE_DOCTOR_LOGIN = "doctor_login"

    fun build(purpose: String, nonce: String, fingerprint: String): String =
        listOf(PREFIX, VERSION, purpose, nonce, fingerprint).joinToString("|")
}
