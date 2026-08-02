<?php

namespace App\Support\Tags;

class TagRules
{
    public const MAX_TEXT_LENGTH = 50;

    public const HEX_REGEX = '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/';

    /**
     * Validation rules for a tags field posted as a 2D array of [text, hexColor?] tuples.
     *
     * @return array<string, array<int, string>>
     */
    public static function rules(string $key = 'tags'): array
    {
        return [
            $key => ['nullable', 'array'],
            "{$key}.*" => ['array', 'min:1', 'max:2'],
            "{$key}.*.0" => ['required', 'string', 'max:'.self::MAX_TEXT_LENGTH],
            "{$key}.*.1" => ['nullable', 'string', 'regex:'.self::HEX_REGEX],
        ];
    }

    /**
     * Normalize raw tuples: trim text, drop empty text, dedupe by text
     * (case-insensitive, first wins), lowercase hex, coerce to 2-element tuples.
     *
     * Legacy string entries are accepted so records written before tags became
     * colored tuples can still be read, edited, and saved in the canonical shape.
     *
     * @param  array<int, mixed>|null  $tags
     * @return array<int, array{0: string, 1: string|null}>
     */
    public static function normalize(?array $tags): array
    {
        $normalized = [];
        $seen = [];

        foreach ($tags ?? [] as $tag) {
            if (! is_array($tag) && ! is_string($tag)) {
                continue;
            }

            $text = is_string($tag) ? trim($tag) : (is_string($tag[0] ?? null) ? trim($tag[0]) : '');

            if ($text === '') {
                continue;
            }

            $key = mb_strtolower($text);

            if (isset($seen[$key])) {
                continue;
            }

            $rawColor = is_array($tag) ? ($tag[1] ?? null) : null;
            $color = is_string($rawColor) && $rawColor !== '' ? strtolower($rawColor) : null;

            $seen[$key] = true;
            $normalized[] = [$text, $color];
        }

        return $normalized;
    }
}
