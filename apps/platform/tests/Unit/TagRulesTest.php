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

    public function test_normalize_ignores_non_array_and_extra_elements(): void
    {
        $this->assertSame([['ok', '#fff']], TagRules::normalize(['plain', ['ok', '#fff', 'extra']]));
    }
}
