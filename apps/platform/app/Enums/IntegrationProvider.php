<?php

namespace App\Enums;

enum IntegrationProvider: string
{
    case GitHub = 'github';
    case Http = 'http';
}
