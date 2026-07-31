<?php

namespace App\Http\Concerns;

use Illuminate\Validation\ValidationException;

trait NormalizesFileMetadata
{
    /**
     * Accepts a JSON object string or an already decoded associative array.
     *
     * @return array<string, mixed>|null
     */
    protected function fileMetadata(mixed $value): ?array
    {
        if (blank($value)) {
            return null;
        }

        if (is_array($value)) {
            if (array_is_list($value)) {
                $this->rejectMetadata();
            }

            return $value;
        }

        if (! is_string($value) || ! is_object(json_decode($value))) {
            $this->rejectMetadata();
        }

        return json_decode($value, true, flags: JSON_THROW_ON_ERROR);
    }

    private function rejectMetadata(): never
    {
        throw ValidationException::withMessages([
            'metadata' => 'Metadata must be a JSON object.',
        ]);
    }
}
