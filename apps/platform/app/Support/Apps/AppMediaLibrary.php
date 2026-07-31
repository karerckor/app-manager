<?php

namespace App\Support\Apps;

class AppMediaLibrary
{
    /**
     * @return array<string, array{
     *     label: string,
     *     description: string,
     *     recommendation: string,
     *     platforms: array<string, array{label: string, variants: array<int, array{name: string, label: string, width: int, height: int}>}>
     * }>
     */
    public static function assets(): array
    {
        return [
            'app_icon' => [
                'label' => 'App icon',
                'description' => 'Square launcher and store artwork for iOS and Android.',
                'recommendation' => 'Upload a square PNG at least 1024 × 1024 px. Keep important artwork away from the edges.',
                'platforms' => [
                    'ios' => [
                        'label' => 'iOS',
                        'variants' => [
                            self::definition('ios-icon-40', 'Notification @2x', 40, 40),
                            self::definition('ios-icon-58', 'Settings @2x', 58, 58),
                            self::definition('ios-icon-60', 'Notification @3x', 60, 60),
                            self::definition('ios-icon-76', 'iPad app @1x', 76, 76),
                            self::definition('ios-icon-80', 'Spotlight @2x', 80, 80),
                            self::definition('ios-icon-87', 'Settings @3x', 87, 87),
                            self::definition('ios-icon-120', 'App @2x / Spotlight @3x', 120, 120),
                            self::definition('ios-icon-152', 'iPad app @2x', 152, 152),
                            self::definition('ios-icon-167', 'iPad Pro app @2x', 167, 167),
                            self::definition('ios-icon-180', 'App @3x', 180, 180),
                            self::definition('ios-icon-1024', 'App Store', 1024, 1024),
                        ],
                    ],
                    'android' => [
                        'label' => 'Android',
                        'variants' => [
                            self::definition('android-icon-mdpi', 'Launcher · mdpi', 48, 48),
                            self::definition('android-icon-hdpi', 'Launcher · hdpi', 72, 72),
                            self::definition('android-icon-xhdpi', 'Launcher · xhdpi', 96, 96),
                            self::definition('android-icon-xxhdpi', 'Launcher · xxhdpi', 144, 144),
                            self::definition('android-icon-xxxhdpi', 'Launcher · xxxhdpi', 192, 192),
                            self::definition('android-icon-play-store', 'Google Play', 512, 512),
                        ],
                    ],
                ],
            ],
            'splash_icon' => [
                'label' => 'Splash screen',
                'description' => 'Portrait launch artwork, center-cropped for common iOS and Android canvases.',
                'recommendation' => 'Upload a high-resolution portrait PNG. Keep the logo and essential content in the center safe area.',
                'platforms' => [
                    'ios' => [
                        'label' => 'iOS',
                        'variants' => [
                            self::definition('ios-splash-640x1136', 'iPhone 4-inch @2x', 640, 1136),
                            self::definition('ios-splash-750x1334', 'iPhone 4.7-inch @2x', 750, 1334),
                            self::definition('ios-splash-828x1792', 'iPhone 6.1-inch @2x', 828, 1792),
                            self::definition('ios-splash-1125x2436', 'iPhone 5.8-inch @3x', 1125, 2436),
                            self::definition('ios-splash-1170x2532', 'iPhone 6.1-inch @3x', 1170, 2532),
                            self::definition('ios-splash-1242x2688', 'iPhone 6.5-inch @3x', 1242, 2688),
                            self::definition('ios-splash-1284x2778', 'iPhone 6.7-inch @3x', 1284, 2778),
                            self::definition('ios-splash-1290x2796', 'iPhone 6.7-inch @3x (modern)', 1290, 2796),
                            self::definition('ios-splash-1536x2048', 'iPad 9.7-inch portrait', 1536, 2048),
                            self::definition('ios-splash-1668x2224', 'iPad 10.5-inch portrait', 1668, 2224),
                            self::definition('ios-splash-1668x2388', 'iPad Pro 11-inch portrait', 1668, 2388),
                            self::definition('ios-splash-2048x2732', 'iPad Pro 12.9-inch portrait', 2048, 2732),
                        ],
                    ],
                    'android' => [
                        'label' => 'Android',
                        'variants' => [
                            self::definition('android-splash-port-mdpi', 'Portrait · mdpi', 320, 480),
                            self::definition('android-splash-port-hdpi', 'Portrait · hdpi', 480, 800),
                            self::definition('android-splash-port-xhdpi', 'Portrait · xhdpi', 720, 1280),
                            self::definition('android-splash-port-xxhdpi', 'Portrait · xxhdpi', 960, 1600),
                            self::definition('android-splash-port-xxxhdpi', 'Portrait · xxxhdpi', 1280, 1920),
                        ],
                    ],
                ],
            ],
        ];
    }

    /** @return array<int, array{name: string, label: string, width: int, height: int}> */
    public static function variants(string $collection): array
    {
        $platforms = self::assets()[$collection]['platforms'] ?? [];

        return collect($platforms)
            ->flatMap(fn (array $platform) => $platform['variants'])
            ->values()
            ->all();
    }

    /** @return array{name: string, label: string, width: int, height: int}|null */
    public static function variant(string $collection, string $name): ?array
    {
        return collect(self::variants($collection))->firstWhere('name', $name);
    }

    /** @return array{name: string, label: string, width: int, height: int} */
    private static function definition(string $name, string $label, int $width, int $height): array
    {
        return compact('name', 'label', 'width', 'height');
    }
}
