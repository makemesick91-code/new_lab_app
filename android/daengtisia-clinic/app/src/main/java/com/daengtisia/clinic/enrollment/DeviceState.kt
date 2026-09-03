package com.daengtisia.clinic.enrollment

/**
 * What screen the app should be showing.
 *
 * Pure Kotlin so the whole startup decision is unit-testable without an
 * emulator — this is the logic that decides whether clinical content is shown
 * at all, so it deserves direct tests rather than manual clicking.
 */
enum class DeviceScreen {
    /** No key/enrolment yet — offer to register. */
    NEEDS_REGISTRATION,

    /** Pairing requested, waiting for an administrator. */
    PENDING_APPROVAL,

    /** Approved but the key has not proved itself yet. */
    NEEDS_PROOF,

    /** Verified and ACTIVE — the only state that opens the WebView. */
    READY,

    /** Administratively disabled. Recoverable by an administrator. */
    BLOCKED_DISABLED,

    /** Trust permanently withdrawn — a fresh identity is required. */
    BLOCKED_REVOKED,

    /** Pairing was refused. */
    BLOCKED_REJECTED,

    /** Server unreachable / unreadable answer. Never assume trust. */
    OFFLINE,
}

/**
 * Server-reported device state. `trustworthy` is the server's own verdict; the
 * client never computes trust itself.
 */
data class RemoteDeviceState(
    val enrollmentStatus: String?,
    val deviceStatus: String?,
    val deviceEnrollmentStatus: String?,
    val trustworthy: Boolean,
)

/**
 * The startup state machine.
 *
 * FAIL CLOSED: every unknown or unreachable case resolves to a non-READY
 * screen. READY is only ever reached when the SERVER says the device is
 * trustworthy — the client does not get to decide it is trusted.
 */
object DeviceStateMachine {

    fun resolve(hasLocalIdentity: Boolean, enrollmentUuid: String?, remote: RemoteDeviceState?): DeviceScreen {
        if (!hasLocalIdentity || enrollmentUuid.isNullOrBlank()) {
            return DeviceScreen.NEEDS_REGISTRATION
        }

        // No answer from the server is not permission to proceed.
        if (remote == null) return DeviceScreen.OFFLINE

        // Administrative status outranks everything, exactly as on the server.
        when (remote.deviceStatus) {
            "revoked" -> return DeviceScreen.BLOCKED_REVOKED
            "disabled" -> return DeviceScreen.BLOCKED_DISABLED
        }

        return when (remote.enrollmentStatus) {
            "rejected" -> DeviceScreen.BLOCKED_REJECTED
            "expired" -> DeviceScreen.NEEDS_REGISTRATION
            "pending" -> DeviceScreen.PENDING_APPROVAL
            "approved", "consumed" ->
                if (remote.trustworthy) DeviceScreen.READY else DeviceScreen.NEEDS_PROOF
            else -> DeviceScreen.NEEDS_REGISTRATION
        }
    }
}
