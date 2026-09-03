package com.daengtisia.clinic

import com.daengtisia.clinic.web.UrlAllowlist
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * The origin policy is the single decision that stops the clinic app becoming a
 * browser, so it is tested directly rather than by clicking around an emulator.
 */
class UrlAllowlistTest {

    private val allowlist = UrlAllowlist("daengtisia.online")

    @Test
    fun `allows the clinic host over https`() {
        assertTrue(allowlist.isAllowed("https://daengtisia.online/"))
        assertTrue(allowlist.isAllowed("https://daengtisia.online/rme/visits"))
        assertTrue(allowlist.isAllowed("https://DAENGTISIA.ONLINE/login"))
    }

    @Test
    fun `allows a subdomain of the clinic host`() {
        assertTrue(allowlist.isAllowed("https://app.daengtisia.online/login"))
    }

    @Test
    fun `blocks cleartext http even for the clinic host`() {
        assertFalse(allowlist.isAllowed("http://daengtisia.online/"))
    }

    @Test
    fun `blocks every other origin`() {
        assertFalse(allowlist.isAllowed("https://evil.example/"))
        assertFalse(allowlist.isAllowed("https://google.com/"))
    }

    @Test
    fun `blocks a lookalike host that merely ends with the clinic name`() {
        // The classic suffix-match bug: `notdaengtisia.online` must NOT pass.
        assertFalse(allowlist.isAllowed("https://notdaengtisia.online/"))
        assertFalse(allowlist.isAllowed("https://daengtisia.online.evil.com/"))
    }

    @Test
    fun `blocks a userinfo trick that looks like the clinic host`() {
        // Reads as the clinic host to a human; the parser sees evil.com.
        assertFalse(allowlist.isAllowed("https://daengtisia.online@evil.com/"))
        assertFalse(allowlist.isAllowed("https://evil.com@daengtisia.online/"))
    }

    @Test
    fun `blocks non web schemes`() {
        assertFalse(allowlist.isAllowed("file:///etc/passwd"))
        assertFalse(allowlist.isAllowed("content://com.android.providers/x"))
        assertFalse(allowlist.isAllowed("javascript:alert(1)"))
        assertFalse(allowlist.isAllowed("intent://scan/#Intent;scheme=zxing;end"))
        assertFalse(allowlist.isAllowed("data:text/html,<script>alert(1)</script>"))
    }

    @Test
    fun `blocks null blank and malformed input`() {
        assertFalse(allowlist.isAllowed(null))
        assertFalse(allowlist.isAllowed(""))
        assertFalse(allowlist.isAllowed("   "))
        assertFalse(allowlist.isAllowed("ht!tp://%%%"))
    }

    @Test
    fun `recognises external web links without allowing them`() {
        assertTrue(allowlist.isExternalWeb("https://evil.example/"))
        assertFalse(allowlist.isExternalWeb("https://daengtisia.online/"))
        assertFalse(allowlist.isExternalWeb("file:///x"))
    }
}
