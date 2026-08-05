<?php

namespace App\Notifications;

use App\Models\TeamInvitation;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TeamInvitationCreated extends Notification
{
    public function __construct(
        private readonly TeamInvitation $invitation,
        private readonly string $token,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject("You've been invited to {$this->invitation->company->name} on Atlas")
            ->greeting('Join your team on Atlas')
            ->line("You've been invited as a {$this->invitation->role} in {$this->invitation->company->name}.")
            ->action('Review invitation', route('team-invitations.show', ['token' => $this->token]))
            ->line('This invitation expires in seven days and can only be used by the invited email address.');
    }
}
