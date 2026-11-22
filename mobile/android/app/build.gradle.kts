import java.util.Properties

plugins {
    id("com.android.application")
    // The Flutter Gradle Plugin must be applied after the Android and Kotlin Gradle plugins.
    id("dev.flutter.flutter-gradle-plugin")
}

// White-label values written by `dart run tool/white_label.dart --tenant <slug>`.
val tenant = Properties().apply {
    val f = rootProject.file("tenant.properties")
    // UTF-8 so accented names ("Maison Lumière") survive.
    if (f.exists()) f.reader(Charsets.UTF_8).use { load(it) }
}
val tenantApplicationId: String = tenant.getProperty("applicationId", "app.luxesalon.lumiere")
val tenantAppName: String = tenant.getProperty("appName", "Maison Lumière")

// Release signing (CI writes android/key.properties; never commit it).
val keystore = Properties().apply {
    val f = rootProject.file("key.properties")
    if (f.exists()) f.inputStream().use { load(it) }
}

android {
    namespace = "app.luxesalon.luxe_salon"
    compileSdk = flutter.compileSdkVersion
    ndkVersion = flutter.ndkVersion

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    defaultConfig {
        applicationId = tenantApplicationId
        minSdk = maxOf(flutter.minSdkVersion, 23)
        targetSdk = flutter.targetSdkVersion
        versionCode = flutter.versionCode
        versionName = flutter.versionName
        manifestPlaceholders["appLabel"] = tenantAppName
    }

    // One codebase, three apps: `flutter build apk --flavor client -t lib/main_client.dart`.
    flavorDimensions += "app"
    productFlavors {
        create("client") {
            dimension = "app"
            applicationId = tenantApplicationId
            manifestPlaceholders["appLabel"] = tenantAppName
        }
        create("staff") {
            dimension = "app"
            applicationId = tenant.getProperty("staffApplicationId", "app.luxesalon.staff")
            manifestPlaceholders["appLabel"] = "Luxe Staff"
        }
        create("owner") {
            dimension = "app"
            applicationId = tenant.getProperty("ownerApplicationId", "app.luxesalon.owner")
            manifestPlaceholders["appLabel"] = "Luxe Owner"
        }
    }

    signingConfigs {
        if (keystore.getProperty("storeFile") != null) {
            create("release") {
                storeFile = file(keystore.getProperty("storeFile"))
                storePassword = keystore.getProperty("storePassword")
                keyAlias = keystore.getProperty("keyAlias")
                keyPassword = keystore.getProperty("keyPassword")
            }
        }
    }

    buildTypes {
        release {
            signingConfig = signingConfigs.findByName("release") ?: signingConfigs.getByName("debug")
            isMinifyEnabled = false
        }
    }
}

kotlin {
    compilerOptions {
        jvmTarget = org.jetbrains.kotlin.gradle.dsl.JvmTarget.JVM_17
    }
}

flutter {
    source = "../.."
}
