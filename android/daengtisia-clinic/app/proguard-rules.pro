# DaengtisiaMS clinic device app — R8 configuration for the production release.
#
# Referenced from app/build.gradle.kts:
#   proguardFiles(getDefaultProguardFile("proguard-android-optimize.txt"), "proguard-rules.pro")
#
# This file is deliberately minimal, and minimal by VERIFICATION rather than by
# neglect. Each category below was checked against the source before deciding
# that no keep rule was needed. A blanket `-keep class com.daengtisia.**` would
# have been the lazy way to make the build go green; it would also have disabled
# most of the shrinking and obfuscation this build type exists to apply.
#
# 1. Framework entry points — NO rule needed.
#    ClinicApplication, ui.MainActivity, kiosk.ClinicDeviceAdminReceiver and
#    kiosk.BootReceiver are all declared in AndroidManifest.xml. AGP generates
#    keep rules for manifest-declared components automatically. Restating them
#    here would add a second, silently-drifting source of truth.
#
# 2. JavaScript bridge — NO rule needed.
#    There is deliberately no addJavascriptInterface and no @JavascriptInterface
#    anywhere in this app (see the comment in ui/MainActivity.kt). There is no
#    bridge whose method names would have to survive obfuscation.
#
# 3. Reflection / JNI / dynamic loading — NO rule needed.
#    No Class.forName, no getDeclaredMethod, no newInstance, no external fun and
#    no System.loadLibrary. Nothing resolves a type or member by name at runtime.
#
# 4. The device-proof wire protocol — NO rule needed, and this is the one that
#    actually mattered. identity/DeviceProofMessage builds the signed message
#    from STRING LITERALS ("daengtisiams-device-proof|v1|<purpose>|<nonce>|
#    <fingerprint>"), not from Kotlin property or class names. Obfuscation
#    therefore cannot change a single byte the device signs, so a release build
#    verifies against the server exactly as a debug build does. Had the message
#    been derived from symbol names, R8 would have silently broken device
#    attestation in production only.
#
# 5. Serialization — NO rule needed.
#    No Gson/Moshi/kotlinx.serialization. org.json is used with literal keys.
#    Enum values()/valueOf and Parcelable CREATOR are already covered by
#    proguard-android-optimize.txt.

# Keep crash reports readable. Obfuscated line numbers on a clinical device
# turn a one-line stack trace into an incident with no evidence, and this app
# has no crash-reporting SDK that would upload a mapping file for us. The
# source file name is still renamed, so this leaks no structure.
-keepattributes SourceFile,LineNumberTable
-renamesourcefileattribute SourceFile
