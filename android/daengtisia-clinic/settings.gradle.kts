// FEATURE-DOCTOR-TRUSTED-ANDROID-DEVICE-LOCK-1 Phase 3 — DaengtisiaMS Clinic App.
//
// A native Kotlin shell around a hardened WebView plus a cryptographic device
// identity layer. The clinical application itself remains the existing Laravel
// system; nothing is reimplemented natively.

pluginManagement {
    repositories {
        google()
        mavenCentral()
        gradlePluginPortal()
    }
}

dependencyResolutionManagement {
    repositoriesMode.set(RepositoriesMode.FAIL_ON_PROJECT_REPOS)
    repositories {
        google()
        mavenCentral()
    }
}

rootProject.name = "daengtisia-clinic"
include(":app")
