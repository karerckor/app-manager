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
}
