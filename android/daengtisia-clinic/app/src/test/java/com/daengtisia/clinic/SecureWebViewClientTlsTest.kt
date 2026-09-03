package com.daengtisia.clinic

import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test
import java.io.File

/**
 * `onReceivedSslError` must ALWAYS cancel.
 *
 * Calling `handler.proceed()` is the single most common way an Android app
 * silently accepts a man-in-the-middle certificate, and there is no clinical
 * justification for it — not in debug, not "temporarily".
 *
 * `SslErrorHandler` is an Android framework class with no meaningful JVM
 * substitute, so behavioural unit testing would prove little. Pinning the
 * SOURCE is the honest alternative: it catches the exact regression (someone
 * "fixing" a certificate warning) and it fails loudly if the guard is removed.
 */
class SecureWebViewClientTlsTest {

    private val source: String by lazy {
        val file = File("src/main/java/com/daengtisia/clinic/web/SecureWebViewClient.kt")
        require(file.exists()) { "SecureWebViewClient.kt not found at ${file.absolutePath}" }
        file.readText()
    }

    @Test
    fun cancelsOnSslError() {
        assertTrue(
            "onReceivedSslError must call handler.cancel()",
            source.contains("handler?.cancel()") || source.contains("handler.cancel()"),
        )
    }

    @Test
    fun neverProceedsThroughAnSslError() {
        val callsProceed = Regex("""handler\??\.proceed\s*\(""").containsMatchIn(source)
        assertFalse(
            "onReceivedSslError must NEVER call proceed() — that accepts a MITM certificate",
            callsProceed,
        )
    }

    @Test
    fun hasNoJavascriptBridgeIntoAndroidInternals() {
        val appSources = File("src/main/java/com/daengtisia/clinic")
            .walkTopDown()
            .filter { it.extension == "kt" }
            .joinToString("\n") { it.readText() }

        assertFalse(
            "addJavascriptInterface exposes Android internals to page JavaScript",
            appSources.contains("addJavascriptInterface"),
        )
    }

    @Test
    fun neverEnablesFileAccessInTheWebView() {
        val appSources = File("src/main/java/com/daengtisia/clinic")
            .walkTopDown()
            .filter { it.extension == "kt" }
            .joinToString("\n") { it.readText() }

        assertFalse(appSources.contains("allowFileAccess = true"))
        assertFalse(appSources.contains("allowUniversalAccessFromFileURLs = true"))
        assertFalse(appSources.contains("allowContentAccess = true"))
    }
}
