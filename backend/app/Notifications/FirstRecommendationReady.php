<?php

namespace App\Notifications;

use App\Models\Recommendation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class FirstRecommendationReady extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Recommendation $recommendation) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject('Your first Atlas recommendation is ready')
            ->greeting('Your first recommendation is ready')
            ->line('Atlas has learned about your business and prepared its first marketing recommendation, with the reasoning behind it.')
            ->action('Review your recommendation', url("/app/recommendations/{$this->recommendation->id}"))
            ->line('You can approve it as-is, edit it first, or pass on it — Atlas learns from whichever you choose.');
    }
}
