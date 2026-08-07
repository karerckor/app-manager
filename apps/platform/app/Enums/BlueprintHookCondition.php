<?php

namespace App\Enums;

enum BlueprintHookCondition: string
{
    case MetadataIncomplete = 'metadata_incomplete';
    case MetadataMatches = 'metadata_matches';
    case FileMissing = 'file_missing';
    case FilePresent = 'file_present';
    case PlatformMissing = 'platform_missing';
    case PlatformPresent = 'platform_present';
    case BlueprintMismatch = 'blueprint_mismatch';
    case BlueprintMatches = 'blueprint_matches';

    public function label(): string
    {
        return match ($this) {
            self::MetadataIncomplete => 'JSON does not match the schema',
            self::MetadataMatches => 'JSON matches the schema',
            self::FileMissing => 'A file is missing',
            self::FilePresent => 'A file is present',
            self::PlatformMissing => 'A platform is missing',
            self::PlatformPresent => 'A platform is present',
            self::BlueprintMismatch => 'Application does not match the blueprint',
            self::BlueprintMatches => 'Application matches the blueprint',
        };
    }

    public function configKey(): ?string
    {
        return match ($this) {
            self::FileMissing, self::FilePresent => 'file_name',
            self::PlatformMissing, self::PlatformPresent => 'platform',
            default => null,
        };
    }
}
