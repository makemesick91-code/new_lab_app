package com.daengtisia.clinic

import com.daengtisia.clinic.enrollment.DeviceScreen
import com.daengtisia.clinic.enrollment.DeviceStateMachine
import com.daengtisia.clinic.enrollment.RemoteDeviceState
import org.junit.Assert.assertEquals
import org.junit.Test

/**
 * The startup decision decides whether clinical content is shown at all, so
 * every branch — especially every fail-closed branch — is pinned here.
 */
class DeviceStateMachineTest {

    private fun remote(
        enrollment: String? = null,
        device: String? = null,
        trustworthy: Boolean = false,
    ) = RemoteDeviceState(
        enrollmentStatus = enrollment,
        deviceStatus = device,
        deviceEnrollmentStatus = null,
        trustworthy = trustworthy,
    )

    @Test
    fun `no local key means registration`() {
        assertEquals(
            DeviceScreen.NEEDS_REGISTRATION,
            DeviceStateMachine.resolve(hasLocalIdentity = false, enrollmentUuid = null, remote = null),
        )
    }

    @Test
    fun `key but no enrollment means registration`() {
        assertEquals(
            DeviceScreen.NEEDS_REGISTRATION,
            DeviceStateMachine.resolve(true, null, null),
        )
    }

    @Test
    fun `unreachable server never grants access`() {
        // The critical fail-closed case: no answer is not permission.
        assertEquals(
            DeviceScreen.OFFLINE,
            DeviceStateMachine.resolve(true, "uuid", null),
        )
    }

    @Test
    fun `pending enrollment waits for approval`() {
        assertEquals(
            DeviceScreen.PENDING_APPROVAL,
            DeviceStateMachine.resolve(true, "uuid", remote(enrollment = "pending")),
        )
    }

    @Test
    fun `approved but unproven still needs proof`() {
        assertEquals(
            DeviceScreen.NEEDS_PROOF,
            DeviceStateMachine.resolve(true, "uuid", remote(enrollment = "approved", device = "active")),
        )
    }

    @Test
    fun `only a server-declared trustworthy device becomes ready`() {
        assertEquals(
            DeviceScreen.READY,
            DeviceStateMachine.resolve(
                true, "uuid",
                remote(enrollment = "approved", device = "active", trustworthy = true),
            ),
        )
    }

    @Test
    fun `disabled outranks a trustworthy verdict`() {
        // Administrative status wins over cryptography, exactly as on the server.
        assertEquals(
            DeviceScreen.BLOCKED_DISABLED,
            DeviceStateMachine.resolve(
                true, "uuid",
                remote(enrollment = "approved", device = "disabled", trustworthy = true),
            ),
        )
    }

    @Test
    fun `revoked outranks a trustworthy verdict`() {
        assertEquals(
            DeviceScreen.BLOCKED_REVOKED,
            DeviceStateMachine.resolve(
                true, "uuid",
                remote(enrollment = "consumed", device = "revoked", trustworthy = true),
            ),
        )
    }

    @Test
    fun `rejected enrollment is blocked`() {
        assertEquals(
            DeviceScreen.BLOCKED_REJECTED,
            DeviceStateMachine.resolve(true, "uuid", remote(enrollment = "rejected")),
        )
    }

    @Test
    fun `expired enrollment restarts registration`() {
        assertEquals(
            DeviceScreen.NEEDS_REGISTRATION,
            DeviceStateMachine.resolve(true, "uuid", remote(enrollment = "expired")),
        )
    }

    @Test
    fun `an unknown enrollment status never becomes ready`() {
        assertEquals(
            DeviceScreen.NEEDS_REGISTRATION,
            DeviceStateMachine.resolve(true, "uuid", remote(enrollment = "something-new", trustworthy = true)),
        )
    }
}
