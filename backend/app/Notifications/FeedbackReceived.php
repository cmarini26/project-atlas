<?php

namespace App\Notifications;

use App\Models\Feedback;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class FeedbackReceived extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Feedback $feedback) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $company = $this->feedback->company;
        $user = $this->feedback->user;

        $message = (new MailMessage())
            ->subject("New feedback: {$this->feedback->score}/10 from {$company?->name}")
            ->greeting("Score: {$this->feedback->score}/10")
            ->line("From {$user?->name} ({$user?->email}) at {$company?->name}.");

        if ($this->feedback->comment !== null) {
            $message->line("\"{$this->feedback->comment}\"");
        }

        return $message;
    }
}
