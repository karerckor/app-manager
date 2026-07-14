<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MediaLibraryInstallTest extends TestCase
{
    use RefreshDatabase;

    public function test_media_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('media'));
    }
}
