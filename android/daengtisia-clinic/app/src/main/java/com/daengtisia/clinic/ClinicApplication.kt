package com.daengtisia.clinic

import android.app.Application
import android.os.Build
import android.webkit.WebView

/**
 * Application entry point.
 *
 * The one thing done here is disabling WebView contents debugging outside debug
 * builds. Leaving it on ships a remote inspection channel into a clinical
 * device, so it is turned off explicitly rather than relying on a default.
 */
class ClinicApplication : Application() {

    override fun onCreate() {
        super.onCreate()

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.KITKAT) {
            WebView.setWebContentsDebuggingEnabled(BuildConfig.DEBUG)
        }
    }
}
