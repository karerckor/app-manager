<?php

namespace App\Enums;

enum SentryTargetMode: string
{
    case All = 'all';
    case Blueprints = 'blueprints';
    case Selected = 'selected';
}
