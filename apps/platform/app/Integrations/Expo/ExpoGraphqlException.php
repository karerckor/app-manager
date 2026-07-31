<?php

namespace App\Integrations\Expo;

use RuntimeException;

class ExpoGraphqlException extends RuntimeException
{
    /** @param array<int, array<string, mixed>> $errors */
    public function __construct(
        string $message,
        public readonly array $errors = [],
        public readonly ?int $status = null,
    ) {
        parent::__construct($message);
    }
}
