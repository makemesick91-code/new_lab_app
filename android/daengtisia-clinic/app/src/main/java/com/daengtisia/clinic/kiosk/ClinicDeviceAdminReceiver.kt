package com.daengtisia.clinic.kiosk

import android.app.admin.DeviceAdminReceiver
import android.content.Context
import android.content.Intent

/**
 * The Device Owner receiver.
 *
 * Present so the app CAN be provisioned as an Android Dedicated Device
 * (Device Owner) via `adb shell dpm set-device-owner` on a freshly wiped
 * device, or via QR/NFC provisioning in the field.
 *
 * This is NOT screen pinning. Screen pinning is user-dismissible and is not a
 * security control; Device Owner + Lock Task is the real dedicated-device
 * mechanism.
 */
class ClinicDeviceAdminReceiver : DeviceAdminReceiver() {

    override fun onEnabled(context: Context, intent: Intent) {
        super.onEnabled(context, intent)
    }

    override fun onDisabled(context: Context, intent: Intent) {
        super.onDisabled(context, intent)
    }
}
