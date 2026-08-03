<?php

namespace Tests\Unit;

use App\Enums\TagAnimation;
use Tests\TestCase;

class TagAnimationTest extends TestCase
{
    public function test_values_are_the_five_lowercase_tokens(): void
    {
        $this->assertSame(['pulse', 'ping', 'flash', 'tada', 'heartbeat'], TagAnimation::values());
    }

    public function test_try_from_loose_is_case_insensitive(): void
    {
        $this->assertSame(TagAnimation::HeartBeat, TagAnimation::tryFromLoose('heartBeat'));
        $this->assertSame(TagAnimation::HeartBeat, TagAnimation::tryFromLoose('HEARTBEAT'));
        $this->assertSame(TagAnimation::Pulse, TagAnimation::tryFromLoose('pulse'));
    }

    public function test_try_from_loose_returns_null_for_unusable_input(): void
    {
        $this->assertNull(TagAnimation::tryFromLoose('bounce'));
        $this->assertNull(TagAnimation::tryFromLoose(''));
        $this->assertNull(TagAnimation::tryFromLoose(null));
        $this->assertNull(TagAnimation::tryFromLoose(123));
        $this->assertNull(TagAnimation::tryFromLoose(['pulse']));
    }

    public function test_try_from_loose_passes_through_an_enum_instance(): void
    {
        $this->assertSame(TagAnimation::Tada, TagAnimation::tryFromLoose(TagAnimation::Tada));
    }
}
