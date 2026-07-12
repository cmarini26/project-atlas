<?php

namespace App\Listeners;

use App\Events\FeedbackSubmitted;
use App\Models\User;
use App\Notifications\FeedbackReceived;
use Illuminate\Support\Facades\Notification;

/**
 * Notifies the team the moment a customer submits feedback. "The team" is
 * every superadmin user — the same population already gated into the
 * Filament admin panel, so no separate founder/team config is needed.
 */
class SendFeedbackNotification
{
    public function handle(FeedbackSubmitted $event): void
    {
        $recipients = User::where('is_superadmin', true)->get();

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, new FeedbackReceived($event->feedback));
    }
}
