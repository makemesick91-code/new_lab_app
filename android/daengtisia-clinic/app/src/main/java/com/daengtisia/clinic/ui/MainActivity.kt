package com.daengtisia.clinic.ui

import android.annotation.SuppressLint
import android.os.Bundle
import android.text.InputType
import android.view.View
import android.webkit.WebView
import android.widget.Button
import android.widget.EditText
import android.widget.LinearLayout
import android.widget.TextView
import androidx.appcompat.app.AppCompatActivity
import com.daengtisia.clinic.BuildConfig
import com.daengtisia.clinic.enrollment.DeviceScreen
import com.daengtisia.clinic.enrollment.DeviceStateMachine
import com.daengtisia.clinic.enrollment.DoctorLoginScreen
import com.daengtisia.clinic.enrollment.DoctorLoginStateMachine
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

            // REVISION-DOCTOR-AUTO-DEVICE-APPROVAL-APP-ONLY-LOGIN-1 — a proven
            // device is hardware trust, not a doctor. The clinical app is only
            // opened after the doctor's own credentials have been through the
            // server, so the next screen is the credential form.
            DeviceScreen.READY -> showDoctorLogin()

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

    // -----------------------------------------------------------------------
    // REVISION-DOCTOR-AUTO-DEVICE-APPROVAL-APP-ONLY-LOGIN-1 — doctor login
    // -----------------------------------------------------------------------

    /**
     * The credential form.
     *
     * The password lives in the EditText and in the argument to one network
     * call. It is never written to SharedPreferences and never held in a field
     * that outlives the attempt — so when approval is still pending the doctor
     * types it again later. One extra tap is cheaper than a credential at rest
     * on a tablet that lives in a clinic corridor.
     */
    private fun showDoctorLogin(message: String? = null) {
        container.removeAllViews()

        val heading = TextView(this).apply {
            textSize = 18f
            text = "Masuk DaengtisiaMS"
        }
        val email = EditText(this).apply {
            hint = "Email dokter"
            inputType = InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_VARIATION_EMAIL_ADDRESS
        }
        val password = EditText(this).apply {
            hint = "Kata sandi"
            inputType = InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_VARIATION_PASSWORD
        }
        val submit = Button(this).apply { text = "Masuk" }
        val note = TextView(this).apply {
            textSize = 14f
            text = message ?: ""
            visibility = if (message == null) View.GONE else View.VISIBLE
        }

        submit.setOnClickListener {
            submit.isEnabled = false
            note.visibility = View.VISIBLE
            note.text = "Memeriksa…"
            attemptDoctorLogin(email.text.toString().trim(), password.text.toString()) {
                submit.isEnabled = true
            }
        }

        listOf(heading, email, password, submit, note).forEach { container.addView(it) }
    }

    private fun attemptDoctorLogin(email: String, password: String, done: () -> Unit) {
        thread {
            val fingerprint = identity.fingerprint()
            val challenge = fingerprint?.let { api.requestLoginChallenge(it) }
            val signature = challenge?.let { identity.signChallenge(it.purpose, it.nonce) }

            val result = if (challenge != null && signature != null) {
                api.doctorLogin(email, password, challenge.nonce, signature)
            } else {
                null
            }

            // The server decides. The client only picks a screen.
            val screen = DoctorLoginStateMachine.resolve(result)

            if (result?.authorizationUuid != null) {
                prefs.edit().putString(KEY_AUTHORIZATION_UUID, result.authorizationUuid).apply()
            }

            runOnUiThread {
                done()
                renderDoctorLogin(screen, result)
            }
        }
    }

    private fun renderDoctorLogin(screen: DoctorLoginScreen, result: com.daengtisia.clinic.enrollment.DoctorLoginResult?) {
        when (screen) {
            DoctorLoginScreen.CREDENTIALS -> showDoctorLogin()

            DoctorLoginScreen.AWAITING_APPROVAL -> showAwaitingApproval(result)

            // Approved AND enforcement is on: the server issued a one-time
            // ticket, and redeeming it in the WebView is what creates the
            // session cookie.
            DoctorLoginScreen.APPROVED_OPEN_CLINIC ->
                openClinic("device-login/" + (result?.loginTicket ?: ""))

            // Approved, but enforcement is off — which is how DaengtisiaMS runs
            // today. Open the ordinary web login rather than inventing a
            // restriction the server is not applying.
            DoctorLoginScreen.APPROVED_ENFORCEMENT_OFF -> openClinic()

            DoctorLoginScreen.ACCESS_REJECTED -> showBlocked(
                "Akses Device Ditolak\n\n" +
                    "Perangkat ini belum/tidak diizinkan untuk akun Anda.\n" +
                    "Hubungi Supervisor RME atau Super Admin."
            )

            DoctorLoginScreen.ACCESS_REVOKED -> showBlocked(
                "Akses Device Dicabut\n\n" +
                    "Izin akun Anda pada perangkat ini telah dicabut.\n" +
                    "Hubungi Supervisor RME atau Super Admin."
            )

            // One message for every failure, matching the server, which answers
            // identically whatever went wrong.
            DoctorLoginScreen.LOGIN_FAILED ->
                showDoctorLogin("Login gagal. Periksa email dan kata sandi Anda.")

            DoctorLoginScreen.OFFLINE ->
                showDoctorLogin("Tidak dapat menghubungi server. Periksa jaringan, lalu coba lagi.")
        }
    }

    private fun showAwaitingApproval(result: com.daengtisia.clinic.enrollment.DoctorLoginResult?) {
        container.removeAllViews()

        // NOT named `text`: a local of that name shadows the implicit receiver's
        // `text` property inside the `apply` blocks below, so `text = "..."` on
        // the button would try to reassign this val instead.
        val info = TextView(this).apply {
            textSize = 16f
            text = buildString {
                append("Persetujuan Device Dibutuhkan\n\n")
                append("Dokter: ").append(result?.doctorName ?: "—").append("\n")
                append("Device: ").append(result?.deviceName ?: "—").append("\n\n")
                append("Status: Menunggu Persetujuan\n\n")
                append("Permintaan telah dikirim kepada Supervisor RME / Super Admin\n")
                append("(menu Approval → Approval Device Dokter).")
            }
        }

        val check = Button(this).apply { text = "Periksa Status" }
        check.setOnClickListener {
            check.isEnabled = false
            pollAuthorization { check.isEnabled = true }
        }

        container.addView(info)
        container.addView(check)
    }

    /**
     * Manual, on-demand status refresh. There is no timer: a tablet polling a
     * pending approval every few seconds all day is a battery and a server cost
     * for information a human is about to deliver anyway.
     */
    private fun pollAuthorization(done: () -> Unit) {
        val uuid = prefs.getString(KEY_AUTHORIZATION_UUID, null)

        if (uuid == null) {
            done()
            showDoctorLogin()
            return
        }

        thread {
            val screen = DoctorLoginStateMachine.resolvePolled(api.doctorAuthorizationStatus(uuid))

            runOnUiThread {
                done()
                when (screen) {
                    // Approved. The password was never kept, so the doctor signs
                    // in again rather than being let in on a stale credential.
                    DoctorLoginScreen.CREDENTIALS ->
                        showDoctorLogin("Device disetujui. Silakan lanjutkan login.")

                    else -> renderDoctorLogin(screen, null)
                }
            }
        }
    }

    private fun showBlocked(message: String) {
        container.removeAllViews()
        status.text = message
        container.addView(status)
    }

    @SuppressLint("SetJavaScriptEnabled")
    private fun openClinic(path: String? = null) {
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

        // A ticket path is appended only when the server issued one; otherwise
        // the ordinary application entry point is loaded, exactly as before.
        view.loadUrl(
            if (path.isNullOrBlank()) {
                BuildConfig.CLINIC_BASE_URL
            } else {
                BuildConfig.CLINIC_BASE_URL.trimEnd('/') + "/" + path.trimStart('/')
            }
        )
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

        // The doctor's own authorization handle, so "Periksa Status" can ask
        // about it. Not a credential: it reveals only that one pairing's coarse
        // state, and it grants nothing.
        const val KEY_AUTHORIZATION_UUID = "authorization_uuid"
    }
}
