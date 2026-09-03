plugins {
    id("com.android.application")
    // Kotlin support is built into AGP 9 — declaring the standalone Kotlin
    // plugin here is an error, not an omission.
}

android {
    namespace = "com.daengtisia.clinic"
    compileSdk = 35

    defaultConfig {
        applicationId = "com.daengtisia.clinic"
        // 26 is the floor for the Keystore features this app relies on
        // (EC key generation with an attested, non-exportable private key).
        minSdk = 26
        targetSdk = 35
        versionCode = 1
        versionName = "0.3.0-phase3"

        testInstrumentationRunner = "androidx.test.runner.AndroidJUnitRunner"

        // The ONLY origin the WebView may load. Compiled in rather than
        // configurable at runtime so a rogue preference cannot repoint the app.
        buildConfigField("String", "CLINIC_HOST", "\"daengtisia.online\"")
        buildConfigField("String", "CLINIC_BASE_URL", "\"https://daengtisia.online/\"")
    }

    buildFeatures {
        buildConfig = true
        viewBinding = false
    }

    buildTypes {
        debug {
            // Debug builds are for the emulator and CI only. They are NOT a
            // production release: see PRODUCTION_SIGNING_GOVERNANCE in the
            // sprint doc — no production signing identity exists yet.
            isMinifyEnabled = false
            applicationIdSuffix = ".debug"
        }
        release {
            isMinifyEnabled = true
            proguardFiles(getDefaultProguardFile("proguard-android-optimize.txt"), "proguard-rules.pro")
            // Deliberately NO signingConfig. Production signing governance is
            // unresolved; wiring a placeholder key here would manufacture a
            // release identity nobody decided to own.
        }
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    testOptions {
        unitTests {
            isReturnDefaultValues = true
        }
    }
}

dependencies {
    implementation("androidx.core:core-ktx:1.13.1")
    implementation("androidx.appcompat:appcompat:1.7.0")

    // Deliberately no HTTP or JSON library: HttpURLConnection and org.json are
    // in the platform. Fewer third-party dependencies is less supply-chain risk
    // on a device that will hold clinical sessions.

    testImplementation("junit:junit:4.13.2")

    androidTestImplementation("androidx.test.ext:junit:1.2.1")
    androidTestImplementation("androidx.test:runner:1.6.2")
    androidTestImplementation("androidx.test:rules:1.6.1")
}
