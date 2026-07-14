<?php

namespace App\Enums;

enum ProfileType: string
{
    // iOS
    case AppStore = 'appstore';
    case Development = 'development'; // reused by Android
    case Enterprise = 'enterprise';
    case AdHoc = 'adhoc';
    // Android
    case Production = 'production';
    case Preview = 'preview';
}
