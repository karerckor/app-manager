<?php

namespace App\Enums;

enum EncryptionKeyStatus: string
{
    case Active = 'active';
    case Retired = 'retired';
}
