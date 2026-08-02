<?php

namespace App\Support\Audit;

use App\Models\Audit;
use App\Models\Credential;
use App\Models\User;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class CredentialAccessAuditRecorder
{
    public function __construct(private readonly ActorContextManager $actorContext) {}

    public function revealed(Credential $credential): Audit
    {
        return $this->record($credential, 'credential_revealed');
    }

    public function downloaded(Credential $credential, Media $media): Audit
    {
        return $this->record($credential, 'credential_file_downloaded', [
            'file_name' => $media->file_name,
        ]);
    }

    /** @param array<string, mixed> $metadata */
    private function record(Credential $credential, string $event, array $metadata = []): Audit
    {
        $context = $this->actorContext->current();

        return Audit::create([
            'user_type' => $context->originator ? User::class : null,
            'user_id' => $context->originator?->id,
            'event' => $event,
            'auditable_type' => Credential::class,
            'auditable_id' => $credential->id,
            'old_values' => [],
            'new_values' => $metadata,
            'ip_address' => $context->ip,
            'user_agent' => $context->userAgent,
            'source' => $context->source,
            'team_id' => $credential->team_id,
            'api_key_id' => $context->apiKey?->id,
        ]);
    }
}
