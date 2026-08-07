<?php

namespace App\Support\Builds;

class AnsiText
{
    private const ESCAPE_SEQUENCE = <<<'REGEX'
~
    (?:\x1B\]|\x9D).*?(?:\x07|\x1B\\|\x9C) # OSC, including hyperlinks and terminal titles
    |(?:\x1BP|\x90).*?(?:\x1B\\|\x9C)       # DCS
    |(?:\x1B[\^_]|[\x98\x9E\x9F]).*?(?:\x1B\\|\x9C) # SOS, PM, APC
    |(?:\x1B\[|\x9B)[0-?]*[ -\/]*[@-\x7E]     # CSI, including SGR colours
    |\x1B[@-_]                                  # Remaining two-byte escape sequences
~sx
REGEX;

    public static function strip(string $value): string
    {
        return preg_replace(self::ESCAPE_SEQUENCE, '', $value) ?? $value;
    }
}
