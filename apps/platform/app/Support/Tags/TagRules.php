<?php

namespace App\Support\Tags;

use App\Enums\TagAnimation;
use Closure;

class TagRules
{
    public const MAX_TEXT_LENGTH = 50;

    public const HEX_REGEX = '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/';

    /**
     * Validation rules for a tags field posted as a 2D array of
     * [text, hexColor?, animation?] tuples.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(string $key = 'tags'): array
    {
        return [
            $key => ['nullable', 'array'],
            "{$key}.*" => ['array', 'min:1', 'max:3'],
            "{$key}.*.0" => ['required', 'string', 'max:'.self::MAX_TEXT_LENGTH],
            "{$key}.*.1" => ['nullable', 'string', 'regex:'.self::HEX_REGEX],
            "{$key}.*.2" => ['nullable', 'string', self::animationRule()],
        ];
    }

    /**
     * Normalize raw tuples: trim text, drop empty text, dedupe by text
     * (case-insensitive, first wins), lowercase hex, canonicalize the animation
     * token, then compact trailing nulls.
     *
     * A tag carrying an animation but no color keeps the null placeholder at
     * index 1 — a positional tuple has no other way to express the gap.
     *
     * Legacy string entries are accepted so records written before tags became
     * tuples can still be read, edited, and saved in the canonical shape.
     *
     * @param  array<int, mixed>|null  $tags
     * @return list<array{0: string, 1?: string|null, 2?: string|null}>
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

            $seen[$key] = true;

            $rawColor = is_array($tag) ? ($tag[1] ?? null) : null;
            $color = is_string($rawColor) && $rawColor !== '' ? strtolower($rawColor) : null;

            $animation = is_array($tag)
                ? TagAnimation::tryFromLoose($tag[2] ?? null)?->value
                : null;

            $normalized[] = match (true) {
                $animation !== null => [$text, $color, $animation],
                $color !== null => [$text, $color],
                default => [$text],
            };
        }

        return $normalized;
    }

    /**
     * The animation whitelist is checked through a closure rather than
     * Rule::enum, which is case-sensitive and would reject "heartBeat".
     */
    private static function animationRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if ($value === null || $value === '') {
                return;
            }

            if (TagAnimation::tryFromLoose($value) === null) {
                $fail('The :attribute must be one of: '.implode(', ', TagAnimation::values()).'.');
            }
        };
    }
}
