<?php

namespace App\Enums;

enum SentryEventSource: string
{
    case Auto = 'auto';
    case Contexts = 'contexts';
    case Extra = 'extra';
    case Tags = 'tags';
    case Attributes = 'attributes';
}
