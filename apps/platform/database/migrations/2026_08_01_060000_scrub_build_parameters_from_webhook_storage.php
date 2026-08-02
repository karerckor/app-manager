<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('webhook_events')
            ->select(['id', 'payload'])
            ->orderBy('id')
            ->chunk(100, function ($events): void {
                foreach ($events as $event) {
                    $payload = json_decode((string) $event->payload, true);

                    if (! is_array($payload) || ! array_key_exists('parameters', $payload)) {
                        continue;
                    }

                    unset($payload['parameters']);
                    DB::table('webhook_events')->where('id', $event->id)->update([
                        'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                    ]);
                }
            });

        DB::table('webhook_deliveries')
            ->select(['id', 'payload_snapshot'])
            ->orderBy('id')
            ->chunk(100, function ($deliveries): void {
                foreach ($deliveries as $delivery) {
                    $payload = json_decode((string) $delivery->payload_snapshot, true);

                    if (! is_array($payload)
                        || ! is_array($payload['data'] ?? null)
                        || ! array_key_exists('parameters', $payload['data'])) {
                        continue;
                    }

                    unset($payload['data']['parameters']);
                    DB::table('webhook_deliveries')->where('id', $delivery->id)->update([
                        'payload_snapshot' => json_encode($payload, JSON_THROW_ON_ERROR),
                    ]);
                }
            });
    }

    public function down(): void
    {
        // Scrubbed secrets cannot be restored safely.
    }
};
