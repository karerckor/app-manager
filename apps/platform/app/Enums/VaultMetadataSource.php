<?php

namespace App\Enums;

enum VaultMetadataSource: string
{
    case User = 'user';
    case Pipeline = 'pipeline';
    case Import = 'import';
}
