<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class FeedbackDigestReady extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array{promoters: int, passives: int, detractors: int, total: int}  $distribution
     * @param  array<int, array{score: int, comment: string, company: string}>  $notableComments
     */
    public function __construct(
        private readonly array $distribution,
        private readonly array $notableComments,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage())
            ->subject('Weekly feedback digest')
            ->greeting('This week in customer feedback')
            ->line("{$this->distribution['total']} responses: {$this->distribution['promoters']} promoters, {$this->distribution['passives']} passives, {$this->distribution['detractors']} detractors.");

        foreach ($this->notableComments as $comment) {
            $message->line("— \"{$comment['comment']}\" ({$comment['score']}/10, {$comment['company']})");
        }

        if ($this->notableComments === []) {
            $message->line('No written comments this week.');
        }

        return $message;
    }
}
