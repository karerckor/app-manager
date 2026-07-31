<?php

namespace App\Integrations\Webhooks;

use App\Enums\BuildStatus;
use App\Enums\ProfileType;
use App\Models\Team;
use App\Support\Tags\TagRules;
use Illuminate\Validation\Rule;

class WebhookSubscriptionFilters
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(Team $team): array
    {
        return [
            'filters' => ['sometimes', 'array:mobile_app_ids,tags,build_statuses,profile_types'],
            'filters.mobile_app_ids' => ['sometimes', 'array', 'max:100'],
            'filters.mobile_app_ids.*' => [
                'string',
                'uuid',
                'distinct',
                Rule::exists('mobile_apps', 'id')->where(
                    fn ($query) => $query->where('team_id', $team->id),
                ),
            ],
            'filters.tags' => ['sometimes', 'array', 'max:50'],
            'filters.tags.*' => ['string', 'max:'.TagRules::MAX_TEXT_LENGTH],
            'filters.build_statuses' => ['sometimes', 'array', 'max:10'],
            'filters.build_statuses.*' => [
                'string',
                'distinct',
                Rule::in(array_map(fn (BuildStatus $status) => $status->value, BuildStatus::cases())),
            ],
            'filters.profile_types' => ['sometimes', 'array', 'max:10'],
            'filters.profile_types.*' => [
                'string',
                'distinct',
                Rule::in(array_map(fn (ProfileType $profile) => $profile->value, ProfileType::cases())),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $filters
     * @return array{
     *     mobile_app_ids: list<string>,
     *     tags: list<string>,
     *     build_statuses: list<string>,
     *     profile_types: list<string>
     * }
     */
    public static function normalize(?array $filters): array
    {
        return [
            'mobile_app_ids' => self::strings($filters['mobile_app_ids'] ?? []),
            'tags' => self::strings($filters['tags'] ?? [], caseInsensitive: true),
            'build_statuses' => self::strings($filters['build_statuses'] ?? []),
            'profile_types' => self::strings($filters['profile_types'] ?? []),
        ];
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $values, bool $caseInsensitive = false): array
    {
        if (! is_array($values)) {
            return [];
        }

        $normalized = [];
        $seen = [];

        foreach ($values as $value) {
            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            $value = trim($value);
            $key = $caseInsensitive ? mb_strtolower($value) : $value;

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $normalized[] = $value;
        }

        return $normalized;
    }
}
