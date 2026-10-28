plugins {
    alias(libs.plugins.android.application)
    alias(libs.plugins.kotlin.android)
    alias(libs.plugins.kotlin.compose)
    alias(libs.plugins.kotlin.serialization)
}

val apiBaseUrl: String = (project.findProperty("apiBaseUrl") as String?) ?: "https://app.blissfulevents.co.zw/api/"
// Production API is not decided yet; release builds refuse to point at staging by accident (see RELEASE_CHECKLIST.md).
val productionApiBaseUrl: String = (project.findProperty("productionApiBaseUrl") as String?) ?: "https://PRODUCTION-API-NOT-CONFIGURED.invalid/api/"

fun firebaseField(name: String): String = "\"" + (System.getenv(name) ?: project.findProperty(name) as String? ?: "") + "\""

android {
    namespace = "zw.co.blissfulevents.gigs"
    compileSdk = 35

    defaultConfig {
        applicationId = "zw.co.blissfulevents.gigs"
        minSdk = 26
        targetSdk = 35
        versionCode = (System.getenv("VERSION_CODE") ?: "1").toInt()
        versionName = "0.9.0"
        testInstrumentationRunner = "androidx.test.runner.AndroidJUnitRunner"
        // Firebase push is optional; leave blank to disable (in-app notification history still works).
        buildConfigField("String", "FIREBASE_APP_ID", firebaseField("FIREBASE_APP_ID"))
        buildConfigField("String", "FIREBASE_API_KEY", firebaseField("FIREBASE_API_KEY"))
        buildConfigField("String", "FIREBASE_PROJECT_ID", firebaseField("FIREBASE_PROJECT_ID"))
        buildConfigField("String", "FIREBASE_SENDER_ID", firebaseField("FIREBASE_SENDER_ID"))
    }

    signingConfigs {
        create("release") {
            // Supplied only through CI secrets / local environment — never committed.
            val ks = System.getenv("ANDROID_KEYSTORE_PATH")
            if (ks != null && file(ks).exists()) {
                storeFile = file(ks)
                storePassword = System.getenv("ANDROID_KEYSTORE_PASSWORD")
                keyAlias = System.getenv("ANDROID_KEY_ALIAS")
                keyPassword = System.getenv("ANDROID_KEY_PASSWORD")
            }
        }
    }

    buildTypes {
        debug {
            applicationIdSuffix = ".dev"
            buildConfigField("String", "API_BASE_URL", "\"$apiBaseUrl\"")
            buildConfigField("String", "ENVIRONMENT", "\"Development\"")
            resValue("string", "app_name", "Blissful Gigs (Dev)")
        }
        create("staging") {
            // The installable test APK distributed from app.blissfulevents.co.zw.
            initWith(getByName("release"))
            applicationIdSuffix = ".testing"
            isMinifyEnabled = true
            proguardFiles(getDefaultProguardFile("proguard-android-optimize.txt"), "proguard-rules.pro")
            signingConfig = signingConfigs.getByName("debug")
            matchingFallbacks += listOf("release")
            buildConfigField("String", "API_BASE_URL", "\"https://app.blissfulevents.co.zw/api/\"")
            buildConfigField("String", "ENVIRONMENT", "\"Testing\"")
            resValue("string", "app_name", "Blissful Gigs (Testing)")
        }
        release {
            isMinifyEnabled = true
            isShrinkResources = true
            proguardFiles(getDefaultProguardFile("proguard-android-optimize.txt"), "proguard-rules.pro")
            val release = signingConfigs.getByName("release")
            if (release.storeFile != null) signingConfig = release
            buildConfigField("String", "API_BASE_URL", "\"$productionApiBaseUrl\"")
            buildConfigField("String", "ENVIRONMENT", "\"Production\"")
            resValue("string", "app_name", "Blissful Gigs")
        }
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
    kotlinOptions { jvmTarget = "17" }
    buildFeatures {
        compose = true
        buildConfig = true
    }
    packaging { resources.excludes += "/META-INF/{AL2.0,LGPL2.1}" }
    testOptions { unitTests.isReturnDefaultValues = true }
    lint {
        abortOnError = true
        checkReleaseBuilds = true
        warningsAsErrors = false
    }
}

dependencies {
    implementation(libs.androidx.core.ktx)
    implementation(libs.androidx.lifecycle.runtime.compose)
    implementation(libs.androidx.lifecycle.viewmodel.compose)
    implementation(libs.androidx.activity.compose)
    implementation(libs.androidx.navigation.compose)
    implementation(platform(libs.compose.bom))
    implementation(libs.compose.ui)
    implementation(libs.compose.ui.tooling.preview)
    implementation(libs.compose.material3)
    implementation(libs.compose.material.icons)
    implementation(libs.retrofit)
    implementation(libs.retrofit.kotlinx)
    implementation(libs.okhttp)
    implementation(libs.kotlinx.serialization.json)
    implementation(libs.kotlinx.coroutines.android)
    implementation(libs.coil.compose)
    implementation(platform(libs.firebase.bom))
    implementation(libs.firebase.messaging)
    debugImplementation(libs.compose.ui.tooling)
    debugImplementation(libs.compose.ui.test.manifest)

    testImplementation(libs.junit)
    testImplementation(libs.okhttp.mockwebserver)
    testImplementation(libs.kotlinx.coroutines.test)
    androidTestImplementation(libs.androidx.junit)
    androidTestImplementation(libs.androidx.espresso)
    androidTestImplementation(platform(libs.compose.bom))
    androidTestImplementation(libs.compose.ui.test.junit4)
}
