package com.daengtisia.clinic.enrollment

/**
 * REVISION-DOCTOR-AUTO-DEVICE-APPROVAL-APP-ONLY-LOGIN-1 — what the app shows a
 * doctor after they submit their credentials.
 *
 * Pure Kotlin so the whole decision is unit-testable without an emulator. It is
 * the logic that decides whether clinical content is opened at all, so it gets
 * direct tests rather than manual clicking.
 */
enum class DoctorLoginScreen {
    /** Nothing submitted yet — show the credential form. */
    CREDENTIALS,

    /** Credentials and device proof accepted; an administrator must approve. */
    AWAITING_APPROVAL,

    /** An administrator refused this doctor on this device. */
    ACCESS_REJECTED,

    /** Approval was withdrawn. */
    ACCESS_REVOKED,

    /**
     * Approved AND the server issued a login ticket — enforcement is on and the
     * clinical app may be opened.
     */
    APPROVED_OPEN_CLINIC,

    /**
     * Approved, but the server issued no ticket because device enforcement is
     * not switched on. The doctor signs in through the ordinary web form, which
     * is exactly how DaengtisiaMS runs today.
     */
    APPROVED_ENFORCEMENT_OFF,

    /** The attempt was refused. The server never says why; neither do we. */
    LOGIN_FAILED,

    /** Server unreachable or unreadable. Never assume access. */
    OFFLINE,
}

/**
 * The server's answer to a login attempt. `outcome` is the server's verdict;
 * the client never computes one.
 */
data class DoctorLoginResult(
    val outcome: String?,
    val authorizationUuid: String?,
    val doctorName: String?,
    val deviceName: String?,
    val enforcementActive: Boolean,
    val loginTicket: String?,
)

/**
 * FAIL CLOSED. Every unknown, absent or unreachable case resolves to a screen
 * that does not open the clinical app. A ticket is the ONLY thing that opens
 * it, and only the server can mint one.
 */
object DoctorLoginStateMachine {

    fun resolve(result: DoctorLoginResult?): DoctorLoginScreen {
        // No answer from the server is not permission to proceed.
        if (result == null) return DoctorLoginScreen.OFFLINE

        return when (result.outcome) {
            "pending" -> DoctorLoginScreen.AWAITING_APPROVAL
            "rejected" -> DoctorLoginScreen.ACCESS_REJECTED
            "revoked" -> DoctorLoginScreen.ACCESS_REVOKED
            "active" ->
                if (!result.loginTicket.isNullOrBlank()) {
                    DoctorLoginScreen.APPROVED_OPEN_CLINIC
                } else {
                    // Approved but no ticket. That is the enforcement-off world,
                    // and pretending otherwise would strand a doctor on a
                    // waiting screen while the ordinary login works fine.
                    DoctorLoginScreen.APPROVED_ENFORCEMENT_OFF
                }
            else -> DoctorLoginScreen.LOGIN_FAILED
        }
    }

    /**
     * Polling an authorization the doctor already raised. Same fail-closed
     * shape, but a bare status carries no ticket, so `active` can only ever
     * invite a fresh login — never open the app.
     */
    fun resolvePolled(status: String?): DoctorLoginScreen = when (status) {
        null -> DoctorLoginScreen.OFFLINE
        "pending" -> DoctorLoginScreen.AWAITING_APPROVAL
        "rejected" -> DoctorLoginScreen.ACCESS_REJECTED
        "revoked" -> DoctorLoginScreen.ACCESS_REVOKED
        // Deliberately back to the credential form rather than straight in: the
        // password is never retained just to auto-login after an approval, so
        // the doctor submits it again. One extra tap, no stored credential.
        "active" -> DoctorLoginScreen.CREDENTIALS
        else -> DoctorLoginScreen.LOGIN_FAILED
    }
}
