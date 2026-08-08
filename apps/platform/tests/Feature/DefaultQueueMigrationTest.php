<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DefaultQueueMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_moves_pending_and_failed_jobs_to_the_default_queue(): void
    {
        $legacyQueues = ['notifications', 'integrations', 'webhooks'];

        foreach ([...$legacyQueues, 'default'] as $queue) {
            DB::table('jobs')->insert([
                'queue' => $queue,
                'payload' => '{}',
                'attempts' => 0,
                'reserved_at' => null,
                'available_at' => now()->timestamp,
                'created_at' => now()->timestamp,
            ]);

            DB::table('failed_jobs')->insert([
                'uuid' => Str::uuid()->toString(),
                'connection' => 'database',
                'queue' => $queue,
                'payload' => '{}',
                'exception' => 'Test exception',
                'failed_at' => now(),
            ]);
        }

        $migration = require database_path('migrations/2026_08_08_000000_move_jobs_to_default_queue.php');
        $migration->up();

        $this->assertSame(4, DB::table('jobs')->where('queue', 'default')->count());
        $this->assertSame(4, DB::table('failed_jobs')->where('queue', 'default')->count());

        foreach ($legacyQueues as $queue) {
            $this->assertDatabaseMissing('jobs', ['queue' => $queue]);
            $this->assertDatabaseMissing('failed_jobs', ['queue' => $queue]);
        }
    }
}
