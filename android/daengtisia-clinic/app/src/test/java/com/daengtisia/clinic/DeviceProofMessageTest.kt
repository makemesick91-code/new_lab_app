package com.daengtisia.clinic

import com.daengtisia.clinic.identity.DeviceProofMessage
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotEquals
import org.junit.Test

/**
 * The signed message must be byte-identical to the server's
 * `DeviceProofMessage`. If this drifts, every signature silently fails to
 * verify — so the exact expected string is pinned here rather than rebuilt
 * from the same code under test.
 */
class DeviceProofMessageTest {

    @Test
    fun `builds the exact canonical format the server rebuilds`() {
        assertEquals(
            "daengtisiams-device-proof|v1|device_proof|abc123|ff00",
            DeviceProofMessage.build("device_proof", "abc123", "ff00"),
        )
    }

    @Test
    fun `binds the nonce so a different challenge is a different message`() {
        assertNotEquals(
            DeviceProofMessage.build("device_proof", "nonce-a", "fp"),
            DeviceProofMessage.build("device_proof", "nonce-b", "fp"),
        )
    }

    @Test
    fun `binds the fingerprint so device A's signature cannot serve device B`() {
        assertNotEquals(
            DeviceProofMessage.build("device_proof", "nonce", "fingerprint-a"),
            DeviceProofMessage.build("device_proof", "nonce", "fingerprint-b"),
        )
    }

    @Test
    fun `binds the purpose so an enrollment proof cannot be replayed as a device proof`() {
        assertNotEquals(
            DeviceProofMessage.build(DeviceProofMessage.PURPOSE_ENROLLMENT, "n", "fp"),
            DeviceProofMessage.build(DeviceProofMessage.PURPOSE_DEVICE_PROOF, "n", "fp"),
        )
    }

    @Test
    fun `the doctor login purpose is distinct from the device proof purpose`() {
        // The purpose is part of the signed bytes. If these two ever collided,
        // a signature captured from one flow would verify in the other.
        assertNotEquals(DeviceProofMessage.PURPOSE_DEVICE_PROOF, DeviceProofMessage.PURPOSE_DOCTOR_LOGIN)

        assertNotEquals(
            DeviceProofMessage.build(DeviceProofMessage.PURPOSE_DEVICE_PROOF, "n", "f"),
            DeviceProofMessage.build(DeviceProofMessage.PURPOSE_DOCTOR_LOGIN, "n", "f"),
        )
    }
}
