package com.daengtisia.clinic.ui

import android.annotation.SuppressLint
import android.os.Bundle
import android.view.View
import android.webkit.WebView
import android.widget.LinearLayout
import android.widget.TextView
import androidx.appcompat.app.AppCompatActivity
import com.daengtisia.clinic.BuildConfig
import com.daengtisia.clinic.enrollment.DeviceScreen
import com.daengtisia.clinic.enrollment.DeviceStateMachine
import com.daengtisia.clinic.enrollment.EnrollmentApi
import com.daengtisia.clinic.identity.DeviceIdentityManager
import com.daengtisia.clinic.kiosk.KioskController
import com.daengtisia.clinic.web.SecureWebViewClient
import com.daengtisia.clinic.web.UrlAllowlist
import kotlin.concurrent.thread

/**
 * The single activity.
 *
 * Startup order matters and is deliberate:
 *   Keystore identity → server device state → screen decision → WebView.
 *
 * The WebView is only ever attached when the SERVER reports the device
 * trustworthy. The client never decides it is trusted, and there is no local
 * override — that is the difference between a device check and device security.
 *
 * Phase 3 scope: this proves "registered clinic hardware". It does NOT log a
 * Doctor in; the Laravel session is untouched and enforcement is OFF.
 */
class MainActivity : AppCompatActivity() {

    private lateinit var identity: DeviceIdentityManager
    private lateinit var api: EnrollmentApi
    private lateinit var kiosk: KioskController
    private lateinit var allowlist: UrlAllowlist

    private lateinit var container: LinearLayout
    private lateinit var status: TextView
    private var webView: WebView? = null

    private val prefs by lazy { getSharedPreferences("clinic_device", MODE_PRIVATE) }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)

        identity = DeviceIdentityManager()
        api = EnrollmentApi(BuildConfig.CLINIC_BASE_URL)
        kiosk = KioskController(this)
        allowlist = UrlAllowlist(BuildConfig.CLINIC_HOST)

        container = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            setPadding(48, 96, 48, 48)
        }
        status = TextView(this).apply { textSize = 16f }
        container.addView(status)
        setContentView(container)

        // Lock Task is best-effort: an unprovisioned device still runs, it just
        // is not a dedicated device. The state is reported, never faked.
        kiosk.enterKiosk(this)

        refresh()
    }

    private fun refresh() {
        status.text = "Memeriksa identitas perangkat…"

        thread {
            identity.ensureIdentity()

            val uuid = prefs.getString(KEY_ENROLLMENT_UUID, null)
            val remote = uuid?.let { api.enrollmentStatus(it) }
            val screen = DeviceStateMachine.resolve(identity.hasIdentity(), uuid, remote)

            // A revoked device keeps no stale key material.
            if (screen == DeviceScreen.BLOCKED_REVOKED) {
                identity.clearIdentity()
                prefs.edit().remove(KEY_ENROLLMENT_UUID).apply()
            }

            runOnUiThread { render(screen) }
        }
    }

    private fun render(screen: DeviceScreen) {
        when (screen) {
            DeviceScreen.NEEDS_REGISTRATION -> {
                status.text = "Perangkat belum terdaftar.\nMendaftarkan…"
                startEnrollment()
            }

            DeviceScreen.PENDING_APPROVAL -> status.text = buildString {
                append("Menunggu persetujuan administrator.\n\n")
                append("Kode pemasangan: ")
                append(prefs.getString(KEY_PAIRING_CODE, "—"))
                append("\n\nSampaikan kode ini kepada Super Admin di menu\nMaster Data → Device Dokter.")
            }

            DeviceScreen.NEEDS_PROOF -> {
                status.text = "Memverifikasi kunci perangkat…"
                proveIdentity()
            }

            DeviceScreen.READY -> openClinic()

            DeviceScreen.BLOCKED_DISABLED ->
                status.text = "Perangkat dinonaktifkan.\nHubungi administrator klinik."

            DeviceScreen.BLOCKED_REVOKED ->
                status.text = "Perangkat dicabut permanen.\nDiperlukan pendaftaran identitas baru."

            DeviceScreen.BLOCKED_REJECTED ->
                status.text = "Pendaftaran perangkat ditolak.\nHubungi administrator klinik."

            DeviceScreen.OFFLINE ->
                status.text = "Tidak dapat menghubungi server.\nPeriksa jaringan, lalu coba lagi."
        }
    }

    private fun startEnrollment() {
        thread {
            val publicKey = identity.publicKeyBase64()
            val result = publicKey?.let {
                api.requestEnrollment(
                    publicKeyBase64 = it,
                    keyAlgorithm = DeviceIdentityManager.KEY_ALGORITHM,
                    platform = "android",
                    deviceModel = android.os.Build.MODEL ?: "unknown",
                    osVersion = android.os.Build.VERSION.RELEASE ?: "unknown",
                    appVersion = BuildConfig.VERSION_NAME,
                )
            }

            runOnUiThread {
                if (result == null) {
                    status.text = "Pendaftaran gagal. Periksa jaringan, lalu coba lagi."
                    return@runOnUiThread
                }

                prefs.edit()
                    .putString(KEY_ENROLLMENT_UUID, result.enrollmentUuid)
                    // The pairing code is shown to the operator, so it is held
                    // only for display. It is not a credential and grants
                    // nothing on its own.
                    .putString(KEY_PAIRING_CODE, result.pairingCode)
                    .apply()

                render(DeviceScreen.PENDING_APPROVAL)
            }
        }
    }

    private fun proveIdentity() {
        thread {
            val fingerprint = identity.fingerprint()
            val challenge = fingerprint?.let { api.requestChallenge(it) }
            val signature = challenge?.let { identity.signChallenge(it.purpose, it.nonce) }
            val verified = if (challenge != null && signature != null) {
                api.submitProof(challenge.nonce, signature)
            } else {
                false
            }

            runOnUiThread {
                if (verified) refresh() else status.text = "Verifikasi perangkat gagal."
            }
        }
    }

    @SuppressLint("SetJavaScriptEnabled")
    private fun openClinic() {
        if (webView != null) return

        val view = WebView(this)
        view.settings.apply {
            // The clinical app is a normal server-rendered web application; it
            // needs JS and DOM storage to function.
            javaScriptEnabled = true
            domStorageEnabled = true

            // Everything below is closed on purpose.
            allowFileAccess = false
            allowContentAccess = false
            @Suppress("DEPRECATION")
            allowFileAccessFromFileURLs = false
            @Suppress("DEPRECATION")
            allowUniversalAccessFromFileURLs = false
            javaScriptCanOpenWindowsAutomatically = false
            setSupportMultipleWindows(false)
            mediaPlaybackRequiresUserGesture = true
            cacheMode = android.webkit.WebSettings.LOAD_DEFAULT
        }

        // No addJavascriptInterface anywhere in this app. There is no bridge for
        // page JavaScript to reach Android internals, and the device key is
        // never reachable from the WebView.

        view.webViewClient = SecureWebViewClient(
            allowlist = allowlist,
            onBlocked = { blocked -> runOnUiThread { toastBlocked(blocked) } },
            onTlsError = { runOnUiThread { showTlsFailure() } },
        )

        webView = view
        container.removeAllViews()
        container.addView(view, LinearLayout.LayoutParams(
            LinearLayout.LayoutParams.MATCH_PARENT,
            LinearLayout.LayoutParams.MATCH_PARENT,
        ))

        view.loadUrl(BuildConfig.CLINIC_BASE_URL)
    }

    private fun toastBlocked(url: String) {
        android.widget.Toast
            .makeText(this, "Navigasi di luar DaengtisiaMS diblokir.", android.widget.Toast.LENGTH_SHORT)
            .show()
    }

    private fun showTlsFailure() {
        webView?.visibility = View.GONE
        status.visibility = View.VISIBLE
        status.text = "Koneksi aman gagal diverifikasi.\nHalaman tidak dimuat."
        container.removeAllViews()
        container.addView(status)
        webView = null
    }

    /** Back navigates the clinical app, and never exits into a launcher. */
    @Suppress("DEPRECATION")
    override fun onBackPressed() {
        val view = webView
        if (view != null && view.canGoBack()) {
            view.goBack()
            return
        }
        // Deliberately swallow: on a dedicated device there is nowhere to go.
    }

    private companion object {
        const val KEY_ENROLLMENT_UUID = "enrollment_uuid"
        const val KEY_PAIRING_CODE = "pairing_code"
    }
}
