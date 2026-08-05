import java.util.Properties

plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
}

val appManagerProperties = Properties()
val appManagerPropertiesFile = rootProject.file(".app-manager/android.properties")
if (appManagerPropertiesFile.exists()) {
    appManagerPropertiesFile.reader(Charsets.UTF_8).use(appManagerProperties::load)
}

fun appManagerProperty(name: String, fallback: String = ""): String =
    appManagerProperties.getProperty(name, fallback)

val generatedIcon = rootProject.file(".app-manager/res/drawable/app_manager_icon.png")
val generatedIconJpeg = rootProject.file(".app-manager/res/drawable/app_manager_icon.jpg")
val generatedIconWebp = rootProject.file(".app-manager/res/drawable/app_manager_icon.webp")
val hasGeneratedIcon = generatedIcon.exists() || generatedIconJpeg.exists() || generatedIconWebp.exists()
val hasReleaseSigning = listOf(
    "appManagerKeystorePath",
    "appManagerStorePassword",
    "appManagerKeyAlias",
    "appManagerKeyPassword",
).all { appManagerProperty(it).isNotBlank() }

android {
    namespace = "com.example.appmanager.kotlin"
    compileSdk = 35

    defaultConfig {
        applicationId = appManagerProperty("appManagerApplicationId", "com.example.appmanager.kotlin")
        minSdk = 24
        targetSdk = 35
        versionCode = appManagerProperty("appManagerVersionCode", "1").toInt()
        versionName = appManagerProperty("appManagerVersionName", "1.0.0")
        resValue("string", "app_name", appManagerProperty("appManagerAppName", "App Manager Kotlin"))
        manifestPlaceholders["appManagerIcon"] = if (hasGeneratedIcon) {
            "@drawable/app_manager_icon"
        } else {
            "@android:drawable/sym_def_app_icon"
        }
    }

    sourceSets.getByName("main").res.srcDir(rootProject.file(".app-manager/res"))

    signingConfigs {
        if (hasReleaseSigning) {
            create("appManagerRelease") {
                storeFile = rootProject.file(appManagerProperty("appManagerKeystorePath"))
                storePassword = appManagerProperty("appManagerStorePassword")
                keyAlias = appManagerProperty("appManagerKeyAlias")
                keyPassword = appManagerProperty("appManagerKeyPassword")
            }
        }
    }

    buildTypes {
        release {
            isMinifyEnabled = false
            signingConfig = signingConfigs.findByName("appManagerRelease")
        }
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    kotlinOptions {
        jvmTarget = "17"
    }
}

if (file("google-services.json").exists()) {
    apply(plugin = "com.google.gms.google-services")
}
