package com.daengtisia.clinic.web

import java.net.URI

/**
 * The WebView origin policy.
 *
 * Pure Kotlin on purpose: this is the single decision that keeps the clinic app
 * from becoming a general-purpose browser, so it is unit-testable without an
 * emulator and is covered directly by tests.
 *
 * RULES — all must hold, and the default is DENY:
 *  - scheme is exactly https (no http, no file, no content, no javascript,
 *    no intent, no data)
 *  - host is the clinic host or a subdomain of it
 *  - no userinfo (`https://evil.com@daengtisia.online` must not pass by
 *    looking right to a human)
 */
class UrlAllowlist(private val clinicHost: String) {

    fun isAllowed(url: String?): Boolean {
        if (url.isNullOrBlank()) return false

        val uri = try {
            URI(url)
        } catch (_: Exception) {
            return false
        }

        // Scheme must be https, compared case-insensitively.
        if (!"https".equals(uri.scheme, ignoreCase = true)) return false

        // `https://attacker.example@daengtisia.online/` and friends: the host a
        // human reads is not the host the parser uses. Refuse outright.
        if (uri.userInfo != null) return false

        val host = uri.host?.lowercase() ?: return false
        val expected = clinicHost.lowercase()

        return host == expected || host.endsWith(".$expected")
    }

    /**
     * External http(s) links a clinician might tap. Phase 3 blocks them
     * outright rather than handing them to Chrome: a dedicated clinic device is
     * not a browsing device.
     */
    fun isExternalWeb(url: String?): Boolean {
        if (url.isNullOrBlank()) return false
        val scheme = try {
            URI(url).scheme?.lowercase()
        } catch (_: Exception) {
            null
        }
        return (scheme == "http" || scheme == "https") && !isAllowed(url)
    }
}
