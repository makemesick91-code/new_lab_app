package com.daengtisia.clinic

import com.daengtisia.clinic.enrollment.DoctorLoginResult
import com.daengtisia.clinic.enrollment.DoctorLoginScreen
import com.daengtisia.clinic.enrollment.DoctorLoginStateMachine
import org.junit.Assert.assertEquals
import org.junit.Test

/**
 * REVISION-DOCTOR-AUTO-DEVICE-APPROVAL-APP-ONLY-LOGIN-1.
 *
 * This decides whether the clinical app is opened, so every branch is pinned —
 * especially the fail-closed ones and the two "approved" cases, which are not
 * the same thing.
 */
class DoctorLoginStateMachineTest {

    private fun result(
        outcome: String?,
        ticket: String? = null,
        enforcement: Boolean = false,
    ) = DoctorLoginResult(
        outcome = outcome,
        authorizationUuid = "uuid",
        doctorName = "dr. Uji",
        deviceName = "Tablet 01",
        enforcementActive = enforcement,
        loginTicket = ticket,
    )

    @Test
    fun `no answer from the server never opens the clinic`() {
        assertEquals(DoctorLoginScreen.OFFLINE, DoctorLoginStateMachine.resolve(null))
    }

    @Test
    fun `pending shows the waiting screen`() {
        assertEquals(
            DoctorLoginScreen.AWAITING_APPROVAL,
            DoctorLoginStateMachine.resolve(result("pending")),
        )
    }

    @Test
    fun `rejected and revoked are distinct and neither opens the clinic`() {
        assertEquals(DoctorLoginScreen.ACCESS_REJECTED, DoctorLoginStateMachine.resolve(result("rejected")))
        assertEquals(DoctorLoginScreen.ACCESS_REVOKED, DoctorLoginStateMachine.resolve(result("revoked")))
    }

    @Test
    fun `approved with a ticket opens the clinic`() {
        assertEquals(
            DoctorLoginScreen.APPROVED_OPEN_CLINIC,
            DoctorLoginStateMachine.resolve(result("active", ticket = "abc", enforcement = true)),
        )
    }

    @Test
    fun `approved without a ticket means enforcement is off, not a failure`() {
        // The enforcement-off world, which is how this ships. Treating it as a
        // failure would strand a doctor on a waiting screen while the ordinary
        // browser login works perfectly.
        assertEquals(
            DoctorLoginScreen.APPROVED_ENFORCEMENT_OFF,
            DoctorLoginStateMachine.resolve(result("active", ticket = null)),
        )
        assertEquals(
            DoctorLoginScreen.APPROVED_ENFORCEMENT_OFF,
            DoctorLoginStateMachine.resolve(result("active", ticket = "  ")),
        )
    }

    @Test
    fun `an unknown outcome fails closed`() {
        assertEquals(DoctorLoginScreen.LOGIN_FAILED, DoctorLoginStateMachine.resolve(result("something-new")))
        assertEquals(DoctorLoginScreen.LOGIN_FAILED, DoctorLoginStateMachine.resolve(result(null)))
    }

    @Test
    fun `polling an approved authorization asks for credentials again rather than opening`() {
        // A polled status carries no ticket, and the password was deliberately
        // never retained, so approval invites a fresh login — it never opens
        // the clinical app on its own.
        assertEquals(DoctorLoginScreen.CREDENTIALS, DoctorLoginStateMachine.resolvePolled("active"))
    }

    @Test
    fun `polling fails closed on an unknown or absent status`() {
        assertEquals(DoctorLoginScreen.OFFLINE, DoctorLoginStateMachine.resolvePolled(null))
        assertEquals(DoctorLoginScreen.LOGIN_FAILED, DoctorLoginStateMachine.resolvePolled("weird"))
        assertEquals(DoctorLoginScreen.AWAITING_APPROVAL, DoctorLoginStateMachine.resolvePolled("pending"))
        assertEquals(DoctorLoginScreen.ACCESS_REJECTED, DoctorLoginStateMachine.resolvePolled("rejected"))
        assertEquals(DoctorLoginScreen.ACCESS_REVOKED, DoctorLoginStateMachine.resolvePolled("revoked"))
    }
}
