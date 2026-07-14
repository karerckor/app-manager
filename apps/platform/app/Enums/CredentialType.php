<?php

namespace App\Enums;

enum CredentialType: string
{
    case IosCertificate = 'ios_certificate';
    case ProvisioningProfile = 'provisioning_profile';
    case ApnsKey = 'apns_key';
    case AppleApiKey = 'apple_api_key';                  // App Store Connect / TestFlight
    case AndroidKeystore = 'android_keystore';
    case FirebaseConfig = 'firebase_config';             // Firebase HTTP v1
    case GooglePlayServiceAccount = 'google_play_service_account';
    case GoogleServicesJson = 'google_services_json';   // Firebase client config (Android)
    case GoogleServiceInfoPlist = 'google_service_info_plist'; // Firebase client config (iOS)
}
