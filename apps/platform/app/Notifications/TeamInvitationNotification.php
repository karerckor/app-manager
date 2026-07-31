<?php

namespace App\Notifications;

use App\Models\TeamInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TeamInvitationNotification extends Notification
{
    use Queueable;

    public function __construct(public TeamInvitation $invitation) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("You're invited to {$this->invitation->team->name}")
            ->greeting('You have been invited')
            ->line("{$this->invitation->inviter?->name} invited you to join {$this->invitation->team->name} as {$this->invitation->role}.")
            ->action('Accept invitation', route('team-invitations.show', ['invitation' => $this->invitation]))
            ->line('This invitation expires in 7 days. Sign in with the invited email address to accept it.');
    }
}
