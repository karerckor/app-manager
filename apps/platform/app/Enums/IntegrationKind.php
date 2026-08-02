<?php

namespace App\Enums;

enum IntegrationKind: string
{
    case CiCd = 'ci_cd';
    case AppService = 'app_service';
    case Webhook = 'webhook';
    case Notification = 'notification';
}
