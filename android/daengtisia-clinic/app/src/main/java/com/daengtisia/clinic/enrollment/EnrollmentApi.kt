package com.daengtisia.clinic.enrollment

import org.json.JSONObject
import java.io.BufferedReader
import java.net.HttpURLConnection
import java.net.URL
import javax.net.ssl.HttpsURLConnection

/**
 * The device channel client.
 *
 * Uses the platform's HttpsURLConnection rather than a third-party HTTP stack:
 * fewer dependencies on a device that will hold clinical sessions, and TLS
 * behaviour stays the platform's well-reviewed default.
 *
 * TLS IS NON-NEGOTIABLE. Every request asserts an https connection and this
 * class never installs a TrustManager or HostnameVerifier — the platform's
 * validation is the point, and overriding it is how apps accidentally accept
 * MITM certificates.
 */
class EnrollmentApi(private val baseUrl: String) {

    data class EnrollmentRequestResult(
        val enrollmentUuid: String,
        val pairingCode: String,
        val expiresAt: String?,
    )

    data class Challenge(val nonce: String, val purpose: String)

    fun requestEnrollment(
        publicKeyBase64: String,
        keyAlgorithm: String,
        platform: String,
        deviceModel: String,
        osVersion: String,
        appVersion: String,
    ): EnrollmentRequestResult? {
        val body = JSONObject()
            .put("public_key", publicKeyBase64)
            .put("key_algorithm", keyAlgorithm)
            .put("platform", platform)
            .put("device_model", deviceModel)
            .put("os_version", osVersion)
            .put("app_version", appVersion)

        val json = post("device-api/v1/enrollment/request", body) ?: return null

        return EnrollmentRequestResult(
            enrollmentUuid = json.optString("enrollment_uuid"),
            pairingCode = json.optString("pairing_code"),
            expiresAt = json.optString("expires_at").ifBlank { null },
        )
    }

    fun enrollmentStatus(uuid: String): RemoteDeviceState? {
        val json = get("device-api/v1/enrollment/$uuid/status") ?: return null
        val device = json.optJSONObject("device")

        return RemoteDeviceState(
            enrollmentStatus = json.optString("status").ifBlank { null },
            deviceStatus = device?.optString("status")?.ifBlank { null },
            deviceEnrollmentStatus = device?.optString("enrollment_status")?.ifBlank { null },
            trustworthy = device?.optBoolean("trustworthy", false) ?: false,
        )
    }

    fun requestChallenge(fingerprint: String): Challenge? {
        val json = post("device-api/v1/challenge", JSONObject().put("fingerprint", fingerprint)) ?: return null
        val nonce = json.optString("nonce")
        if (nonce.isBlank()) return null

        return Challenge(nonce = nonce, purpose = json.optString("purpose"))
    }

    /** Submit the signature. True only on an explicit server-side `verified`. */
    fun submitProof(nonce: String, signatureBase64: String): Boolean {
        val json = post(
            "device-api/v1/proof",
            JSONObject().put("nonce", nonce).put("signature", signatureBase64),
        ) ?: return false

        return json.optBoolean("verified", false)
    }

    // -----------------------------------------------------------------------

    private fun open(path: String): HttpsURLConnection? {
        val url = URL(baseUrl.trimEnd('/') + "/" + path.trimStart('/'))

        // Cleartext is refused here as well as in the manifest's network
        // security config: defence in depth, and it makes the intent explicit
        // at the call site.
        val connection = url.openConnection()
        if (connection !is HttpsURLConnection) return null

        return connection.apply {
            connectTimeout = 15_000
            readTimeout = 15_000
            setRequestProperty("Accept", "application/json")
        }
    }

    private fun get(path: String): JSONObject? = runCatching {
        val conn = open(path) ?: return null
        conn.requestMethod = "GET"
        readJson(conn)
    }.getOrNull()

    private fun post(path: String, body: JSONObject): JSONObject? = runCatching {
        val conn = open(path) ?: return null
        conn.requestMethod = "POST"
        conn.doOutput = true
        conn.setRequestProperty("Content-Type", "application/json")
        conn.outputStream.use { it.write(body.toString().toByteArray(Charsets.UTF_8)) }
        readJson(conn)
    }.getOrNull()

    private fun readJson(conn: HttpURLConnection): JSONObject? {
        val code = conn.responseCode
        // Any non-2xx is a denial. The server answers denials identically on
        // purpose, so there is nothing to parse out of them.
        if (code !in 200..299) {
            conn.errorStream?.close()
            return null
        }

        val text = conn.inputStream.bufferedReader().use(BufferedReader::readText)

        return runCatching { JSONObject(text) }.getOrNull()
    }
}
