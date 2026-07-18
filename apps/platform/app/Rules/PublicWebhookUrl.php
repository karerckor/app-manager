<?php

namespace App\Rules;

use App\Integrations\Webhooks\WebhookUrlGuard;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use InvalidArgumentException;

class PublicWebhookUrl implements ValidationRule
{
    public function __construct(private readonly WebhookUrlGuard $guard) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            $this->guard->assertSafe((string) $value);
        } catch (InvalidArgumentException $exception) {
            $fail($exception->getMessage());
        }
    }
}
