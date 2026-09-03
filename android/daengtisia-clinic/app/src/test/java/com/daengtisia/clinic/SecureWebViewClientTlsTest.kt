package com.daengtisia.clinic

import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test
import java.io.File

/**
 * `onReceivedSslError` must ALWAYS cancel.
 *
 * Telling the handler to continue past a certificate error is the single most
 * common way an Android app silently accepts a man-in-the-middle certificate,
 * and there is no clinical justification for it — not in debug, not
 * "temporarily".
 *
 * `SslErrorHandler` is an Android framework class with no meaningful JVM
 * substitute, so behavioural unit testing would prove little. Pinning the
 * SOURCE is the honest alternative: it catches the exact regression (someone
 * "fixing" a certificate warning) and it fails loudly if the guard is removed.
 *
 * The scan is COMMENT-AWARE on purpose. A plain substring scan matched the very
 * comments that explain why these calls are forbidden, so the guard failed on a
 * codebase that was already correct. A control that fires on its own
 * documentation trains people to weaken it; stripping comments and string
 * literals first lets prose name the danger while only real code trips it.
 */
class SecureWebViewClientTlsTest {

    private val webViewClientCode: String by lazy {
        val file = File("src/main/java/com/daengtisia/clinic/web/SecureWebViewClient.kt")
        require(file.exists()) { "SecureWebViewClient.kt not found at ${file.absolutePath}" }
        codeOnly(file.readText())
    }

    private val allMainCode: String by lazy {
        val root = File("src/main/java/com/daengtisia/clinic")
        require(root.isDirectory) { "main sources not found at ${root.absolutePath}" }

        val sources = root.walkTopDown().filter { it.extension == "kt" }.toList()
        require(sources.isNotEmpty()) { "no Kotlin sources found — the scan would pass vacuously" }

        sources.joinToString("\n") { codeOnly(it.readText()) }
    }

    @Test
    fun cancelsOnSslError() {
        assertTrue(
            "onReceivedSslError must call handler.cancel()",
            webViewClientCode.contains("handler?.cancel()") || webViewClientCode.contains("handler.cancel()"),
        )
    }

    @Test
    fun neverProceedsThroughAnSslError() {
        val continuesPastError = Regex("""handler\??\.proceed\s*\(""").containsMatchIn(webViewClientCode)
        assertFalse(
            "onReceivedSslError must never continue past a certificate error — that accepts a MITM certificate",
            continuesPastError,
        )
    }

    @Test
    fun hasNoJavascriptBridgeIntoAndroidInternals() {
        assertFalse(
            "a JavaScript bridge would expose Android internals to page JavaScript",
            allMainCode.contains("addJavascriptInterface"),
        )
    }

    @Test
    fun neverEnablesFileAccessInTheWebView() {
        assertFalse(allMainCode.contains("allowFileAccess = true"))
        assertFalse(allMainCode.contains("allowUniversalAccessFromFileURLs = true"))
        assertFalse(allMainCode.contains("allowContentAccess = true"))
    }

    /**
     * Returns [source] with Kotlin comments removed.
     *
     * String and character literals are tracked so a URL such as an https
     * address is never mistaken for the start of a line comment, and block
     * comments are counted rather than flagged because Kotlin allows them to
     * nest. Newlines survive so line numbers stay aligned.
     */
    private fun codeOnly(source: String): String {
        val out = StringBuilder(source.length)
        var i = 0
        var inLineComment = false
        var blockDepth = 0
        var inString = false
        var inRawString = false
        var inChar = false

        fun peek(offset: Int): Char = if (i + offset < source.length) source[i + offset] else ' '

        while (i < source.length) {
            val c = source[i]

            when {
                inLineComment -> {
                    if (c == '\n') {
                        inLineComment = false
                        out.append(c)
                    }
                    i++
                }

                blockDepth > 0 -> {
                    when {
                        c == '/' && peek(1) == '*' -> {
                            blockDepth++
                            i += 2
                        }

                        c == '*' && peek(1) == '/' -> {
                            blockDepth--
                            i += 2
                        }

                        else -> {
                            if (c == '\n') out.append(c)
                            i++
                        }
                    }
                }

                inRawString -> {
                    if (c == '"' && peek(1) == '"' && peek(2) == '"') {
                        inRawString = false
                        i += 3
                    } else {
                        out.append(c)
                        i++
                    }
                }

                inString -> {
                    if (c == '\\') {
                        out.append(c)
                        i++
                        if (i < source.length) {
                            out.append(source[i])
                            i++
                        }
                    } else {
                        if (c == '"') inString = false
                        out.append(c)
                        i++
                    }
                }

                inChar -> {
                    if (c == '\\') {
                        out.append(c)
                        i++
                        if (i < source.length) {
                            out.append(source[i])
                            i++
                        }
                    } else {
                        if (c == '\'') inChar = false
                        out.append(c)
                        i++
                    }
                }

                c == '/' && peek(1) == '/' -> {
                    inLineComment = true
                    i += 2
                }

                c == '/' && peek(1) == '*' -> {
                    blockDepth = 1
                    i += 2
                }

                c == '"' && peek(1) == '"' && peek(2) == '"' -> {
                    inRawString = true
                    i += 3
                }

                c == '"' -> {
                    inString = true
                    out.append(c)
                    i++
                }

                c == '\'' -> {
                    inChar = true
                    out.append(c)
                    i++
                }

                else -> {
                    out.append(c)
                    i++
                }
            }
        }

        return out.toString()
    }
}
