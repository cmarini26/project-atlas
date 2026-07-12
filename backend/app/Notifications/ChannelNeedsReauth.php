<?php

namespace App\Notifications;

use App\Models\ChannelCredentials;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ChannelNeedsReauth extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly ChannelCredentials $credentials) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $channelType = ucfirst($this->credentials->channel_type);

        return (new MailMessage())
            ->subject("Reconnect your {$channelType} account")
            ->greeting("Atlas can't reach {$channelType} anymore")
            ->line("Atlas's connection to your {$channelType} account stopped working during a routine health check — this usually means the connection was revoked or the access token expired.")
            ->action('Reconnect in Settings', url('/app/settings'))
            ->line('Publishing to this channel is paused until you reconnect it.');
    }
}
