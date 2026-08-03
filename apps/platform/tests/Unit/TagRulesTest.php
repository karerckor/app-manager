<?php

namespace Tests\Unit;

use App\Support\Tags\TagRules;
use Tests\TestCase;

class TagRulesTest extends TestCase
{
    public function test_normalize_trims_and_lowercases_hex(): void
    {
        $this->assertSame([['Bug', '#ff0000']], TagRules::normalize([['  Bug  ', '#FF0000']]));
    }

    public function test_normalize_drops_empty_text(): void
    {
        $this->assertSame([['ok', null]], TagRules::normalize([['   ', '#fff'], ['ok', null]]));
    }

    public function test_normalize_dedupes_case_insensitively_first_wins(): void
    {
        $this->assertSame([['Bug', '#111111']], TagRules::normalize([['Bug', '#111111'], ['bug', '#222222']]));
    }

    public function test_normalize_coerces_missing_color_to_null(): void
    {
        $this->assertSame([['urgent', null]], TagRules::normalize([['urgent']]));
    }

    public function test_normalize_handles_null_input(): void
    {
        $this->assertSame([], TagRules::normalize(null));
    }

    public function test_rules_shape(): void
    {
        $rules = TagRules::rules('tags');
        $this->assertArrayHasKey('tags', $rules);
        $this->assertArrayHasKey('tags.*', $rules);
        $this->assertArrayHasKey('tags.*.0', $rules);
        $this->assertArrayHasKey('tags.*.1', $rules);
        $this->assertContains('required', $rules['tags.*.0']);
        $this->assertContains('max:'.TagRules::MAX_TEXT_LENGTH, $rules['tags.*.0']);
        $this->assertContains('regex:'.TagRules::HEX_REGEX, $rules['tags.*.1']);
        $this->assertContains('array', $rules['tags']);
        $this->assertContains('array', $rules['tags.*']);
    }

    public function test_normalize_coerces_non_string_color_to_null(): void
    {
        $this->assertSame([['ok', null]], TagRules::normalize([['ok', 123]]));
    }

    public function test_normalize_accepts_legacy_string_tags_and_ignores_extra_elements(): void
    {
        $this->assertSame([['plain', null], ['ok', '#fff']], TagRules::normalize(['plain', ['ok', '#fff', 'extra']]));
    }

    public function test_normalize_compacts_a_text_only_tag(): void
    {
        $this->assertSame([['urgent']], TagRules::normalize([['urgent']]));
        $this->assertSame([['urgent']], TagRules::normalize([['urgent', null]]));
        $this->assertSame([['urgent']], TagRules::normalize([['urgent', null, null]]));
    }

    public function test_normalize_keeps_a_color_only_tag_two_wide(): void
    {
        $this->assertSame([['urgent', '#abcdef']], TagRules::normalize([['urgent', '#ABCDEF', null]]));
    }

    public function test_normalize_keeps_all_three_fields(): void
    {
        $this->assertSame(
            [['onboarding', '#10b981', 'pulse']],
            TagRules::normalize([['onboarding', '#10B981', 'pulse']]),
        );
    }

    public function test_normalize_retains_the_null_color_placeholder_when_only_animation_is_set(): void
    {
        $this->assertSame([['onboarding', null, 'tada']], TagRules::normalize([['onboarding', null, 'tada']]));
    }

    public function test_normalize_canonicalizes_animation_case(): void
    {
        $this->assertSame([['beat', null, 'heartbeat']], TagRules::normalize([['beat', null, 'heartBeat']]));
    }

    public function test_normalize_drops_an_unknown_animation(): void
    {
        $this->assertSame([['x']], TagRules::normalize([['x', null, 'bounce']]));
        $this->assertSame([['x']], TagRules::normalize([['x', null, 42]]));
    }

    public function test_rules_validate_the_animation_field(): void
    {
        $rules = TagRules::rules('tags');

        $this->assertArrayHasKey('tags.*.2', $rules);
        $this->assertContains('nullable', $rules['tags.*.2']);
        $this->assertContains('max:3', $rules['tags.*']);
    }

    public function test_animation_rule_accepts_whitelisted_values_case_insensitively(): void
    {
        $validator = validator(['tags' => [['ok', null, 'heartBeat']]], TagRules::rules());

        $this->assertFalse($validator->fails());
    }

    public function test_animation_rule_rejects_unknown_values(): void
    {
        $validator = validator(['tags' => [['ok', null, 'bounce']]], TagRules::rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('tags.0.2', $validator->errors()->toArray());
    }

    public function test_rules_reject_a_four_element_tuple(): void
    {
        $validator = validator(['tags' => [['ok', '#fff', 'pulse', 'extra']]], TagRules::rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('tags.0', $validator->errors()->toArray());
    }
}
