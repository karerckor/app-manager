<?php

namespace App\Enums;

enum WebhookEventType: string
{
    case MobileAppCreated = 'mobile_app.created';
    case MobileAppUpdated = 'mobile_app.updated';
    case MobileAppDeleted = 'mobile_app.deleted';
    case AppIdentifierCreated = 'app_identifier.created';
    case AppIdentifierUpdated = 'app_identifier.updated';
    case AppIdentifierDeleted = 'app_identifier.deleted';
    case CredentialCreated = 'credential.created';
    case CredentialUpdated = 'credential.updated';
    case CredentialDeleted = 'credential.deleted';
    case BuildCreated = 'build.created';
    case BuildStarted = 'build.started';
    case BuildCancelRequested = 'build.cancel_requested';
    case BuildCompleted = 'build.completed';
    case TeamInvitationCreated = 'team.invitation.created';
    case TeamInvitationAccepted = 'team.invitation.accepted';
    case TeamInvitationRevoked = 'team.invitation.revoked';
    case TeamMemberRoleChanged = 'team.member.role_changed';
    case TeamMemberRemoved = 'team.member.removed';
    case WebhookTest = 'webhook.test';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $event) => $event->value, self::cases());
    }

    /** @return list<string> */
    public static function subscribableValues(): array
    {
        return array_values(array_filter(
            self::values(),
            fn (string $event) => $event !== self::WebhookTest->value,
        ));
    }
}
