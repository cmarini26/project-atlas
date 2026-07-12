<?php

namespace App\Jobs;

use App\Models\Feedback;
use App\Models\User;
use App\Notifications\FeedbackDigestReady;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;

class SendFeedbackDigest implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->onQueue('maintenance');
    }

    public function handle(): void
    {
        $recipients = User::where('is_superadmin', true)->get();

        if ($recipients->isEmpty()) {
            return;
        }

        $feedback = Feedback::withoutGlobalScopes()
            ->with('company')
            ->where('created_at', '>=', now()->subDays(7))
            ->get();

        if ($feedback->isEmpty()) {
            return;
        }

        $distribution = [
            'promoters' => $feedback->filter(fn (Feedback $f) => $f->score >= 9)->count(),
            'passives' => $feedback->filter(fn (Feedback $f) => $f->score >= 7 && $f->score <= 8)->count(),
            'detractors' => $feedback->filter(fn (Feedback $f) => $f->score <= 6)->count(),
            'total' => $feedback->count(),
        ];

        $withComments = $feedback->filter(fn (Feedback $f) => filled($f->comment));

        $notableComments = $withComments
            ->sortBy('score')
            ->take(3)
            ->merge($withComments->sortByDesc('score')->take(2))
            ->unique('id')
            ->take(5)
            ->map(function (Feedback $f): array {
                $companyName = $f->company === null ? 'Unknown company' : $f->company->name;

                return [
                    'score' => $f->score,
                    'comment' => (string) $f->comment,
                    'company' => $companyName,
                ];
            })
            ->values()
            ->all();

        Notification::send($recipients, new FeedbackDigestReady($distribution, $notableComments));
    }
}
