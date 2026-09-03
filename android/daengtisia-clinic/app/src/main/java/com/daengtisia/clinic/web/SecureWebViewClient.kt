package com.daengtisia.clinic.web

import android.graphics.Bitmap
import android.net.http.SslError
import android.webkit.SslErrorHandler
import android.webkit.WebResourceRequest
import android.webkit.WebResourceResponse
import android.webkit.WebView
import android.webkit.WebViewClient

/**
 * The hardened WebView client.
 *
 * Two rules, both fail-closed:
 *
 *  1. ORIGIN — only the clinic host loads. Everything else (other hosts,
 *     file://, content://, intent://, javascript:, data:) is refused, and
 *     external links are NOT handed to Chrome: a dedicated clinic device is not
 *     a browsing device.
 *
 *  2. TLS — `onReceivedSslError` ALWAYS cancels. Calling `handler.proceed()` is
 *     the single most common way an Android app silently accepts a
 *     man-in-the-middle certificate, and there is no clinical justification for
 *     it. There is deliberately no debug escape hatch here: a build-type
 *     conditional is exactly how such a bypass reaches production.
 */
class SecureWebViewClient(
    private val allowlist: UrlAllowlist,
    private val onBlocked: (String) -> Unit = {},
    private val onTlsError: (String) -> Unit = {},
) : WebViewClient() {

    override fun shouldOverrideUrlLoading(view: WebView?, request: WebResourceRequest?): Boolean {
        val url = request?.url?.toString()

        if (allowlist.isAllowed(url)) {
            return false // let the WebView load it
        }

        // Blocked. Returning true means "handled" — and we handle it by doing
        // nothing except telling the UI, never by launching an external app.
        onBlocked(url ?: "")
        return true
    }

    override fun shouldInterceptRequest(view: WebView?, request: WebResourceRequest?): WebResourceResponse? {
        val url = request?.url?.toString()

        // Subresources are held to the same origin rule as navigations, so a
        // compromised page cannot pull scripts from somewhere else.
        if (!allowlist.isAllowed(url)) {
            onBlocked(url ?: "")
            return WebResourceResponse("text/plain", "utf-8", null)
        }

        return null
    }

    override fun onReceivedSslError(view: WebView?, handler: SslErrorHandler?, error: SslError?) {
        // ALWAYS cancel. Never proceed. Not in debug, not "temporarily".
        onTlsError(error?.url ?: "")
        handler?.cancel()
    }

    override fun onPageStarted(view: WebView?, url: String?, favicon: Bitmap?) {
        // Last-chance guard: if anything ever navigated the WebView outside the
        // allowlist without passing through shouldOverrideUrlLoading, stop it.
        if (!allowlist.isAllowed(url)) {
            view?.stopLoading()
            onBlocked(url ?: "")
            return
        }
        super.onPageStarted(view, url, favicon)
    }
}
