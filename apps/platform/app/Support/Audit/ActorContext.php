<?php

namespace App\Support\Audit;

use App\Models\ApiKey;
use App\Models\User;

final class ActorContext
{
    public function __construct(
        public readonly ?User $originator,
        public readonly ?ApiKey $apiKey,
        public readonly string $source,
        public readonly ?string $ip = null,
        public readonly ?string $userAgent = null,
    ) {}

    public static function system(): self
    {
        return new self(null, null, 'system');
    }

    public static function forUser(
        User $originator,
        string $source,
        ?ApiKey $apiKey = null,
        ?string $ip = null,
        ?string $userAgent = null,
    ): self {
        return new self($originator, $apiKey, $source, $ip, $userAgent);
    }

    public function actorType(): string
    {
        if ($this->apiKey !== null) {
            return 'api_key';
        }

        return $this->originator !== null ? 'user' : 'system';
    }

    /** @return array<string, mixed> */
    public function toWebhookActor(): array
    {
        $type = $this->actorType();

        if ($type === 'system') {
            return ['type' => 'system'];
        }

        return [
            'type' => $type,
            'id' => $type === 'api_key' ? $this->apiKey?->id : $this->originator?->id,
            'display_name' => $type === 'api_key' ? $this->apiKey?->name : $this->originator?->name,
            'source' => $this->source,
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'originator_id' => $this->originator?->id,
            'api_key_id' => $this->apiKey?->id,
            'source' => $this->source,
            'ip' => $this->ip,
            'user_agent' => $this->userAgent,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $user = $data['originator_id'] ? User::find($data['originator_id']) : null;
        $apiKey = $data['api_key_id'] ? ApiKey::find($data['api_key_id']) : null;

        if ($user === null) {
            return self::system();
        }

        return new self($user, $apiKey, $data['source'], $data['ip'] ?? null, $data['user_agent'] ?? null);
    }
}
