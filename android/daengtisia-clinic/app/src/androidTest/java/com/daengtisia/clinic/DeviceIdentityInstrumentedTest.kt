package com.daengtisia.clinic

import android.util.Base64
import androidx.test.ext.junit.runners.AndroidJUnit4
import com.daengtisia.clinic.identity.DeviceIdentityManager
import com.daengtisia.clinic.identity.DeviceProofMessage
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import org.junit.runner.RunWith
import java.security.KeyFactory
import java.security.MessageDigest
import java.security.Signature
import java.security.spec.X509EncodedKeySpec

/**
 * Runs on a real Android runtime against the REAL AndroidKeyStore.
 *
 * A unit test with a mocked Keystore would prove nothing about the property
 * that matters — that the private key is generated and used inside the
 * Keystore, and that the signature it produces verifies against the exported
 * public key exactly as the PHP server will verify it.
 *
 * NOTE ON SCOPE: an emulator's Keystore is software-backed. This proves the
 * protocol and the API usage; it does NOT prove hardware-backed or StrongBox
 * key storage, which needs real clinic hardware (a Phase 4 blocker).
 */
@RunWith(AndroidJUnit4::class)
class DeviceIdentityInstrumentedTest {

    private val alias = "daengtisiams_instrumented_test_key"
    private lateinit var identity: DeviceIdentityManager

    @Before
    fun setUp() {
        identity = DeviceIdentityManager(alias)
        identity.clearIdentity()
    }

    @After
    fun tearDown() {
        identity.clearIdentity()
    }

    @Test
    fun generatesAnIdentityInTheAndroidKeystore() {
        assertFalse(identity.hasIdentity())
        assertTrue(identity.ensureIdentity())
        assertTrue(identity.hasIdentity())

        assertNotNull(identity.publicKeyBase64())
        assertNotNull(identity.fingerprint())
    }

    @Test
    fun ensureIdentityIsIdempotentSoAnApprovedKeyIsNeverRotated() {
        identity.ensureIdentity()
        val first = identity.publicKeyBase64()

        // A restart must not silently rotate a key an administrator approved.
        identity.ensureIdentity()

        assertEquals(first, identity.publicKeyBase64())
    }

    @Test
    fun fingerprintMatchesTheServerFormula() {
        identity.ensureIdentity()

        val publicKey = Base64.decode(identity.publicKeyBase64(), Base64.NO_WRAP)
        val expected = MessageDigest.getInstance("SHA-256")
            .digest(publicKey)
            .joinToString("") { "%02x".format(it) }

        // Server: hash('sha256', der) lowercase hex — must be byte-identical.
        assertEquals(expected, identity.fingerprint())
        assertEquals(64, identity.fingerprint()!!.length)
    }

    @Test
    fun signatureVerifiesAgainstTheExportedPublicKeyExactlyAsTheServerWill() {
        identity.ensureIdentity()

        val nonce = "a".repeat(64)
        val purpose = DeviceProofMessage.PURPOSE_DEVICE_PROOF
        val fingerprint = identity.fingerprint()!!

        val signatureB64 = identity.signChallenge(purpose, nonce)
        assertNotNull(signatureB64)

        // Rebuild the canonical message and verify with the PUBLIC key only —
        // the same inputs and the same algorithm PHP's openssl_verify uses.
        val message = DeviceProofMessage.build(purpose, nonce, fingerprint)
        val publicKey = KeyFactory.getInstance("EC")
            .generatePublic(X509EncodedKeySpec(Base64.decode(identity.publicKeyBase64(), Base64.NO_WRAP)))

        val verifier = Signature.getInstance("SHA256withECDSA")
        verifier.initVerify(publicKey)
        verifier.update(message.toByteArray(Charsets.UTF_8))

        assertTrue(verifier.verify(Base64.decode(signatureB64, Base64.NO_WRAP)))
    }

    @Test
    fun aSignatureOverADifferentNonceDoesNotVerify() {
        identity.ensureIdentity()

        val fingerprint = identity.fingerprint()!!
        val purpose = DeviceProofMessage.PURPOSE_DEVICE_PROOF
        val signatureB64 = identity.signChallenge(purpose, "a".repeat(64))!!

        val wrongMessage = DeviceProofMessage.build(purpose, "b".repeat(64), fingerprint)
        val publicKey = KeyFactory.getInstance("EC")
            .generatePublic(X509EncodedKeySpec(Base64.decode(identity.publicKeyBase64(), Base64.NO_WRAP)))

        val verifier = Signature.getInstance("SHA256withECDSA")
        verifier.initVerify(publicKey)
        verifier.update(wrongMessage.toByteArray(Charsets.UTF_8))

        assertFalse(verifier.verify(Base64.decode(signatureB64, Base64.NO_WRAP)))
    }

    @Test
    fun clearingTheIdentityDestroysItSoRevokedHardwareKeepsNoKey() {
        identity.ensureIdentity()
        assertTrue(identity.hasIdentity())

        identity.clearIdentity()

        assertFalse(identity.hasIdentity())
        assertNull(identity.publicKeyBase64())
        // And it cannot sign any more.
        assertNull(identity.signChallenge(DeviceProofMessage.PURPOSE_DEVICE_PROOF, "a".repeat(64)))
    }

    @Test
    fun aSecondInstallGeneratesADifferentIdentitySoACopiedApkInheritsNoTrust() {
        identity.ensureIdentity()
        val first = identity.publicKeyBase64()

        // A different alias stands in for a different install/device: the key is
        // generated locally, so it cannot match one an administrator approved.
        val other = DeviceIdentityManager("${alias}_second")
        try {
            other.ensureIdentity()
            assertTrue(first != other.publicKeyBase64())
        } finally {
            other.clearIdentity()
        }
    }
}
