<?php

namespace Tests\Feature\Feedback;

use App\Jobs\SendFeedbackDigest;
use App\Models\Company;
use App\Models\Feedback;
use App\Models\User;
use App\Notifications\FeedbackDigestReady;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SendFeedbackDigestTest extends TestCase
{
    use RefreshDatabase;

    private function makeFeedback(Company $company, int $score, ?string $comment = null, ?\DateTimeInterface $createdAt = null): Feedback
    {
        $feedback = Feedback::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'user_id' => User::factory()->create()->id,
            'score' => $score,
            'comment' => $comment,
        ]);

        if ($createdAt !== null) {
            $feedback->forceFill(['created_at' => $createdAt])->save();
        }

        return $feedback;
    }

    public function test_sends_a_digest_with_the_correct_nps_distribution(): void
    {
        Notification::fake();

        $company = Company::withoutGlobalScopes()->create(['name' => 'Acme', 'slug' => 'acme']);
        $admin = User::factory()->create(['is_superadmin' => true]);

        $this->makeFeedback($company, 10);
        $this->makeFeedback($company, 9);
        $this->makeFeedback($company, 8);
        $this->makeFeedback($company, 3);

        (new SendFeedbackDigest())->handle();

        Notification::assertSentTo(
            $admin,
            FeedbackDigestReady::class,
            function (FeedbackDigestReady $notification): bool {
                $lines = $notification->toMail($notification)->introLines;
                $summary = implode(' ', $lines);

                // 2 promoters (9-10), 1 passive (7-8), 1 detractor (<=6), 4 total.
                return str_contains($summary, '4 responses')
                    && str_contains($summary, '2 promoters')
                    && str_contains($summary, '1 passives')
                    && str_contains($summary, '1 detractors');
            }
        );
    }

    public function test_excludes_feedback_older_than_7_days(): void
    {
        Notification::fake();

        $company = Company::withoutGlobalScopes()->create(['name' => 'Acme', 'slug' => 'acme']);
        User::factory()->create(['is_superadmin' => true]);

        $this->makeFeedback($company, 10, createdAt: now()->subDays(10));

        (new SendFeedbackDigest())->handle();

        Notification::assertNothingSent();
    }

    public function test_does_not_send_when_there_is_no_feedback_this_week(): void
    {
        Notification::fake();
        User::factory()->create(['is_superadmin' => true]);

        (new SendFeedbackDigest())->handle();

        Notification::assertNothingSent();
    }

    public function test_does_not_error_when_there_are_no_superadmins(): void
    {
        Notification::fake();
        $company = Company::withoutGlobalScopes()->create(['name' => 'Acme', 'slug' => 'acme']);
        $this->makeFeedback($company, 10);

        (new SendFeedbackDigest())->handle();

        Notification::assertNothingSent();
    }
}
