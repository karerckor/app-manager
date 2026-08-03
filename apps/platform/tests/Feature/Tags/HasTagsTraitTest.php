<?php

namespace Tests\Feature\Tags;

use App\Enums\TagAnimation;
use App\Models\MobileApp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HasTagsTraitTest extends TestCase
{
    use RefreshDatabase;

    public function test_tags_cast_to_array_and_persist(): void
    {
        $app = MobileApp::factory()->create();
        $app->syncTags([['bug', '#ff0000'], ['urgent', null]])->save();

        $this->assertSame([['bug', '#ff0000'], ['urgent']], $app->fresh()->tags);
    }

    public function test_add_and_remove_tag(): void
    {
        $app = MobileApp::factory()->create();
        $app->addTag('Bug', '#FF0000');
        $this->assertTrue($app->hasTag('bug'));
        $this->assertSame([['Bug', '#ff0000']], $app->tags);

        $app->removeTag('bug');
        $this->assertFalse($app->hasTag('bug'));
        $this->assertSame([], $app->tags);
    }

    public function test_sync_normalizes(): void
    {
        $app = MobileApp::factory()->create();
        $app->syncTags([['  Bug  ', '#ABCDEF'], ['bug', '#000000'], ['  ', null]]);

        $this->assertSame([['Bug', '#abcdef']], $app->tags);
    }

    public function test_null_tags_reads_as_empty_via_tag_list(): void
    {
        $app = MobileApp::factory()->create();
        $this->assertSame([], $app->tagList());
        $this->assertFalse($app->hasTag('anything'));
    }

    public function test_legacy_string_tags_can_be_read_removed_and_persisted(): void
    {
        $app = MobileApp::factory()->create();
        DB::table('mobile_apps')->where('id', $app->id)->update([
            'tags' => json_encode(['demo', 'ios'], JSON_THROW_ON_ERROR),
        ]);

        $app = $app->fresh();

        $this->assertSame([['demo'], ['ios']], $app->tags);

        $app->removeTag('demo')->save();

        $this->assertSame([['ios']], $app->fresh()->tags);
    }

    public function test_add_tag_accepts_an_animation(): void
    {
        $app = MobileApp::factory()->create();
        $app->addTag('onboarding', '#10B981', 'heartBeat');

        $this->assertSame([['onboarding', '#10b981', 'heartbeat']], $app->tags);
    }

    public function test_add_tag_accepts_an_animation_enum_and_no_color(): void
    {
        $app = MobileApp::factory()->create();
        $app->addTag('beta', null, TagAnimation::Pulse);

        $this->assertSame([['beta', null, 'pulse']], $app->tags);
    }
}
