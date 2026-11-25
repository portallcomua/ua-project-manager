plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
}
android {
    namespace = "ua.uaserver.projectmanager"
    compileSdk = 35
    defaultConfig {
        applicationId = "ua.uaserver.projectmanager"
        minSdk = 26
        targetSdk = 35
        versionCode = 1
        versionName = "0.1.0"
    }
}
dependencies {
    implementation("androidx.work:work-runtime-ktx:2.10.0")
    implementation("androidx.security:security-crypto:1.1.0-alpha06")
}
