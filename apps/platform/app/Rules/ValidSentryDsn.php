<?php

namespace App\Rules;

use App\Integrations\Sentry\SentryDsnUrl;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use InvalidArgumentException;

class ValidSentryDsn implements ValidationRule
{
    public function __construct(private readonly SentryDsnUrl $dsnUrl) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            $this->dsnUrl->parse((string) $value, assertSafe: true);
        } catch (InvalidArgumentException $exception) {
            $fail($exception->getMessage());
        }
    }
}
