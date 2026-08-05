<?php

namespace App\Notifications;

use App\Models\TeamInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TeamInvitationNotification extends Notification implements ShouldQueue
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
            ->subject("Invitation to {$this->invitation->team->name}")
            ->greeting('You have been invited')
            ->line("{$this->invitation->inviter->name} invited you to join {$this->invitation->team->name} as {$this->invitation->role->label()}.")
            ->action('Accept invitation', $this->invitation->acceptUrl())
            ->line('This invitation expires in 7 days. Sign in or create an account using this email address to accept it.');
    }
}
