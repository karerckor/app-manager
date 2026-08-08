<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('jobs')
            ->whereIn('queue', ['notifications', 'integrations', 'webhooks'])
            ->update(['queue' => 'default']);

        DB::table('failed_jobs')
            ->whereIn('queue', ['notifications', 'integrations', 'webhooks'])
            ->update(['queue' => 'default']);
    }

    public function down(): void
    {
        // The original queue cannot be determined reliably after consolidation.
    }
};
