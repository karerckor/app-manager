<?php

namespace App\Enums;

enum SentryRoutingMethod: string
{
    case Query = 'query';
    case Header = 'header';
    case Event = 'event';
}
