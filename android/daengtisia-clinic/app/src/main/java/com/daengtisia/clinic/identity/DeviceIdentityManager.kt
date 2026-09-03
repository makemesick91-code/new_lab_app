package com.daengtisia.clinic.identity

import android.os.Build
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import android.util.Base64
import java.security.KeyPairGenerator
import java.security.KeyStore
import java.security.MessageDigest
import java.security.PrivateKey
import java.security.Signature

/**
 * The device's cryptographic identity.
 *
 * PRIVATE KEY CUSTODY — the whole point of this class:
 *  - the keypair is generated INSIDE the Android Keystore
 *    (`AndroidKeyStore`), so the private key material never exists in app
 *    memory and cannot be read out by this process or any other;
 *  - it is therefore never transmitted, never written to SharedPreferences,
 *    never handed to the WebView, and never logged;
 *  - only the PUBLIC key leaves the device, and only its fingerprint is used
 *    as an identifier.
 *
 * StrongBox (a separate secure element) is requested OPPORTUNISTICALLY. Many
 * legitimate clinic tablets have no StrongBox, so a hard requirement would
 * refuse perfectly acceptable hardware; the code falls back to the TEE-backed
 * Keystore, which is still non-exportable.
 *
 * Clearing app data destroys the Keystore entry, which destroys the identity.
 * That is intended: the device must then re-enrol and be re-approved. It is
 * also why copying the APK to another device grants nothing — the new install
 * generates a DIFFERENT key that no administrator has approved.
 */
class DeviceIdentityManager(private val keyAlias: String = DEFAULT_ALIAS) {

    companion object {
        const val DEFAULT_ALIAS = "daengtisiams_device_identity_v1"
        const val KEY_ALGORITHM = "EC_P256_SHA256"
        private const val ANDROID_KEYSTORE = "AndroidKeyStore"
        private const val SIGNATURE_ALGORITHM = "SHA256withECDSA"
    }

    private fun keyStore(): KeyStore =
        KeyStore.getInstance(ANDROID_KEYSTORE).apply { load(null) }

    fun hasIdentity(): Boolean = runCatching {
        keyStore().containsAlias(keyAlias)
    }.getOrDefault(false)

    /**
     * Create the identity if absent. Idempotent, so a restart never rotates a
     * key that an administrator has already approved.
     */
    fun ensureIdentity(): Boolean {
        if (hasIdentity()) return true

        return runCatching { generate(useStrongBox = true) }
            // StrongBox is unavailable on most tablets — fall back rather than
            // refuse to run on legitimate hardware.
            .recoverCatching { generate(useStrongBox = false) }
            .isSuccess
    }

    private fun generate(useStrongBox: Boolean) {
        val generator = KeyPairGenerator.getInstance(KeyProperties.KEY_ALGORITHM_EC, ANDROID_KEYSTORE)

        val spec = KeyGenParameterSpec.Builder(keyAlias, KeyProperties.PURPOSE_SIGN)
            .setAlgorithmParameterSpec(java.security.spec.ECGenParameterSpec("secp256r1"))
            .setDigests(KeyProperties.DIGEST_SHA256)
            // Deliberately NOT user-authentication-bound: the device proves the
            // DEVICE is clinic hardware. Proving WHO the user is stays the
            // Laravel session's job — see the phase rules on keeping device
            // identity and user session separate.
            .apply {
                if (useStrongBox && Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) {
                    setIsStrongBoxBacked(true)
                }
            }
            .build()

        generator.initialize(spec)
        generator.generateKeyPair()
    }

    /** Base64 (NO_WRAP) DER X.509 SubjectPublicKeyInfo — what the server stores. */
    fun publicKeyBase64(): String? {
        val entry = keyStore().getCertificate(keyAlias) ?: return null
        return Base64.encodeToString(entry.publicKey.encoded, Base64.NO_WRAP)
    }

    /** SHA-256 over the DER public key, lowercase hex. Matches the server. */
    fun fingerprint(): String? {
        val cert = keyStore().getCertificate(keyAlias) ?: return null
        val digest = MessageDigest.getInstance("SHA-256").digest(cert.publicKey.encoded)
        return digest.joinToString("") { "%02x".format(it) }
    }

    /**
     * Sign a server challenge. The private key is used by REFERENCE inside the
     * Keystore — it is never materialised here, which is why this method
     * returns a signature and there is deliberately no `exportPrivateKey`.
     */
    fun signChallenge(purpose: String, nonce: String): String? {
        val fingerprint = fingerprint() ?: return null
        val privateKey = keyStore().getKey(keyAlias, null) as? PrivateKey ?: return null

        val message = DeviceProofMessage.build(purpose, nonce, fingerprint)

        return runCatching {
            val signer = Signature.getInstance(SIGNATURE_ALGORITHM)
            signer.initSign(privateKey)
            signer.update(message.toByteArray(Charsets.UTF_8))
            Base64.encodeToString(signer.sign(), Base64.NO_WRAP)
        }.getOrNull()
    }

    /**
     * Destroy the identity. Used when the server reports the device REVOKED, so
     * stale key material does not linger on hardware that is no longer trusted.
     */
    fun clearIdentity() {
        runCatching { keyStore().deleteEntry(keyAlias) }
    }
}
