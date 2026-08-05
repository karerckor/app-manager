<?php

namespace App\Enums;

enum SentryEnvelopeStatus: string
{
    case Pending = 'pending';
    case Forwarded = 'forwarded';
    case RetryScheduled = 'retry_scheduled';
    case Exhausted = 'exhausted';
    case Stored = 'stored';
}
