<?php

namespace App\Http\Concerns;

use Illuminate\Http\Request;

trait NormalizesArtifactMetadata
{
    private function normalizeMetadata(Request $request): ?array
    {
        $raw = $request->input('metadata');

        if ($raw === null || $raw === '') {
            return null;
        }

        if (is_array($raw)) {
            return $raw;
        }

        $decoded = json_decode((string) $raw, true);
        abort_if(json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded), 422, 'metadata must be a JSON object.');

        return $decoded;
    }
}
