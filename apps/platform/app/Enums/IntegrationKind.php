<?php

namespace App\Enums;

enum IntegrationKind: string
{
    case CiCd = 'ci_cd';
    case Webhook = 'webhook';
}
