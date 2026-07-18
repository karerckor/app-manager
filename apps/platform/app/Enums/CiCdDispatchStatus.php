<?php

namespace App\Enums;

enum CiCdDispatchStatus: string
{
    case Created = 'created';
    case Dispatching = 'dispatching';
    case Accepted = 'accepted';
    case Unknown = 'unknown';
    case Failed = 'failed';
}
