<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['delivery_id', 'event', 'installation_id', 'payload_hash', 'payload', 'processed_at'])]
class GitHubWebhookDelivery extends Model
{
    public $incrementing = false;

    protected $table = 'github_webhook_deliveries';

    protected $primaryKey = 'delivery_id';

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'payload' => 'encrypted:array',
            'processed_at' => 'datetime',
        ];
    }
}
