package com.daengtisia.clinic.kiosk

import android.app.Activity
import android.app.admin.DevicePolicyManager
import android.content.ComponentName
import android.content.Context
import android.os.Build

/**
 * Android Dedicated Device (Lock Task) control.
 *
 * WHAT THIS IS NOT: screen pinning. Screen pinning can be dismissed by holding
 * Back + Recents and is not a security control. Lock Task under Device Owner is
 * the real mechanism, which is why every call here is guarded by
 * `isDeviceOwner()` and the app degrades gracefully rather than pretending.
 *
 * NOT PROVISIONED IS A SUPPORTED STATE. During development, on a shared tablet,
 * or before provisioning, the app must still run — it simply does not lock the
 * task. Refusing to start would make the app untestable and would tempt someone
 * to add a bypass.
 *
 * There is deliberately NO hard-coded exit PIN and NO secret gesture. Exit is a
 * management action: an administrator removes the Device Owner or the policy
 * allows it. A secret known to every clinic is not a secret.
 */
class KioskController(private val context: Context) {

    private val dpm: DevicePolicyManager? =
        context.getSystemService(Context.DEVICE_POLICY_SERVICE) as? DevicePolicyManager

    private val admin: ComponentName = ComponentName(context, ClinicDeviceAdminReceiver::class.java)

    fun isDeviceOwner(): Boolean =
        dpm?.isDeviceOwnerApp(context.packageName) == true

    /**
     * Allowlist this app for Lock Task. Only meaningful as Device Owner; a
     * no-op otherwise, reported honestly by the return value.
     */
    fun configureLockTaskAllowlist(): Boolean {
        val manager = dpm ?: return false
        if (!isDeviceOwner()) return false

        return runCatching {
            manager.setLockTaskPackages(admin, arrayOf(context.packageName))
            true
        }.getOrDefault(false)
    }

    /**
     * Enter kiosk. Returns whether the device is genuinely locked, so the UI can
     * tell the operator the truth instead of implying protection it lacks.
     */
    fun enterKiosk(activity: Activity): Boolean {
        if (!isDeviceOwner()) return false
        if (!configureLockTaskAllowlist()) return false

        return runCatching {
            activity.startLockTask()
            true
        }.getOrDefault(false)
    }

    /** Leave kiosk. Only an authorised management path should reach this. */
    fun exitKiosk(activity: Activity): Boolean = runCatching {
        activity.stopLockTask()
        true
    }.getOrDefault(false)

    fun isLockTaskActive(): Boolean {
        val manager = dpm ?: return false

        return if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
            runCatching { manager.isLockTaskPermitted(context.packageName) }.getOrDefault(false)
        } else {
            false
        }
    }
}
