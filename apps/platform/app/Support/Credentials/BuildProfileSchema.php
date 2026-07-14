<?php

namespace App\Support\Credentials;

use App\Enums\Platform;
use App\Enums\ProfileType;

class BuildProfileSchema
{
    public static function definitions(): array
    {
        return [
            self::make(
                ProfileType::Development,
                Platform::Ios,
                'Development',
                '.ipa',
                'For personal development and testing on registered devices.',
            ),
            self::make(
                ProfileType::AdHoc,
                Platform::Ios,
                'Ad Hoc',
                '.ipa',
                'For sharing test builds with a fixed list of registered devices.',
            ),
            self::make(
                ProfileType::AppStore,
                Platform::Ios,
                'App Store',
                '.ipa',
                'For TestFlight and App Store distribution.',
            ),
            self::make(
                ProfileType::Enterprise,
                Platform::Ios,
                'Enterprise',
                '.ipa',
                'For in-house distribution through the Apple Developer Enterprise Program.',
            ),
            self::make(
                ProfileType::Development,
                Platform::Android,
                'Development',
                '.apk',
                'For personal development and local device testing.',
            ),
            self::make(
                ProfileType::Preview,
                Platform::Android,
                'Preview',
                '.apk',
                'For sharing with the development team or internal testers.',
            ),
            self::make(
                ProfileType::Production,
                Platform::Android,
                'Production',
                '.aab',
                'For a fully signed Android App Bundle submitted to Google Play.',
            ),
        ];
    }

    public static function forPlatform(Platform|string $platform): array
    {
        $value = $platform instanceof Platform ? $platform->value : $platform;

        return array_values(array_filter(
            self::definitions(),
            fn (array $definition) => $definition['platform'] === $value,
        ));
    }

    public static function definition(ProfileType|string $type, Platform|string $platform): ?array
    {
        $typeValue = $type instanceof ProfileType ? $type->value : $type;
        $platformValue = $platform instanceof Platform ? $platform->value : $platform;

        return collect(self::definitions())->first(
            fn (array $definition) => $definition['value'] === $typeValue
                && $definition['platform'] === $platformValue,
        );
    }

    public static function supportsPlatform(ProfileType $type, Platform|string $platform): bool
    {
        return self::definition($type, $platform) !== null;
    }

    private static function make(
        ProfileType $type,
        Platform $platform,
        string $label,
        string $artifact,
        string $description,
    ): array {
        return [
            'value' => $type->value,
            'platform' => $platform->value,
            'label' => $label,
            'artifact' => $artifact,
            'description' => $description,
        ];
    }
}
