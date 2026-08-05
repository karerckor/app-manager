<?php

namespace App\Integrations\Sentry;

final readonly class ParsedEnvelope
{
    /**
     * @param  array<string, mixed>  $header
     * @param  list<array{header: array<string, mixed>, payload: string, json: ?array}>  $items
     */
    public function __construct(
        public array $header,
        public array $items,
    ) {}

    /** @return array<string, mixed> */
    public function routingPayload(): array
    {
        foreach ($this->items as $item) {
            if (in_array($item['header']['type'] ?? null, ['event', 'transaction'], true)
                && is_array($item['json'])) {
                return $item['json'];
            }
        }

        return [];
    }
}
