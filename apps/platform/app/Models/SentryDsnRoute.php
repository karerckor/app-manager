<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['public_key', 'sentry_dsn_id', 'mobile_app_id', 'app_identifier_id'])]
class SentryDsnRoute extends Model
{
    use HasUuids;

    public function sentryDsn(): BelongsTo
    {
        return $this->belongsTo(SentryDsn::class);
    }

    public function mobileApp(): BelongsTo
    {
        return $this->belongsTo(MobileApp::class);
    }

    public function appIdentifier(): BelongsTo
    {
        return $this->belongsTo(AppIdentifier::class);
    }
}
