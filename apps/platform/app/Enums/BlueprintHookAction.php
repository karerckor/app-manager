<?php

namespace App\Enums;

enum BlueprintHookAction: string
{
    case AddTag = 'add_tag';
    case RemoveTag = 'remove_tag';

    public function label(): string
    {
        return match ($this) {
            self::AddTag => 'Add tags',
            self::RemoveTag => 'Remove tags',
        };
    }
}
