package com.daengtisia.clinic.kiosk

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import com.daengtisia.clinic.ui.MainActivity

/**
 * Bring a dedicated device back to the clinic app after a reboot.
 *
 * Only acts when this app is Device Owner: on a shared or unprovisioned tablet
 * an app that force-launches itself on boot is malware behaviour, not a
 * feature.
 */
class BootReceiver : BroadcastReceiver() {

    override fun onReceive(context: Context, intent: Intent) {
        if (intent.action != Intent.ACTION_BOOT_COMPLETED) return
        if (!KioskController(context).isDeviceOwner()) return

        val launch = Intent(context, MainActivity::class.java).apply {
            addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
        }
        context.startActivity(launch)
    }
}
