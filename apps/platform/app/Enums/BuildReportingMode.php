<?php

namespace App\Enums;

enum BuildReportingMode: string
{
    case Manual = 'manual';
    case Automatic = 'automatic';
    case Eas = 'eas';
}
