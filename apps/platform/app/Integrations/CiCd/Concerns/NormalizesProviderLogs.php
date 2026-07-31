<?php

namespace App\Integrations\CiCd\Concerns;

trait NormalizesProviderLogs
{
    protected function normalizeLogs(string $output, string $truncatedMessage, int $maxBytes = 500_000): string
    {
        $output = trim($output);

        if ($output === '') {
            return '';
        }

        $output = preg_replace('/\x1B(?:[@-_]|\[[0-?]*[ -\/]*[@-~])/', '', $output) ?? $output;
        $utf8 = iconv('UTF-8', 'UTF-8//IGNORE', $output);
        $output = is_string($utf8) ? $utf8 : $output;

        if (strlen($output) > $maxBytes) {
            $output = $truncatedMessage."\n".substr($output, -$maxBytes);
        }

        return $output;
    }
}
