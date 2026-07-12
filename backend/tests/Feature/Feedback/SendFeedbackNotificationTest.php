<?php

namespace Tests\Feature\Feedback;

use App\Events\FeedbackSubmitted;
use App\Models\Company;
use App\Models\Feedback;
use App\Models\User;
use App\Notifications\FeedbackReceived;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SendFeedbackNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function makeFeedback(Company $company, User $user, int $score = 8): Feedback
    {
        return Feedback::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'score' => $score,
        ]);
    }

    public function test_notifies_every_superadmin(): void
    {
        Notification::fake();

        $superadmin1 = User::factory()->create(['is_superadmin' => true]);
        $superadmin2 = User::factory()->create(['is_superadmin' => true]);
        $regularUser = User::factory()->create(['is_superadmin' => false]);

        $company = Company::withoutGlobalScopes()->create(['name' => 'Acme', 'slug' => 'acme']);
        $feedback = $this->makeFeedback($company, $regularUser);

        FeedbackSubmitted::dispatch($feedback);

        Notification::assertSentTo($superadmin1, FeedbackReceived::class);
        Notification::assertSentTo($superadmin2, FeedbackReceived::class);
        Notification::assertNotSentTo($regularUser, FeedbackReceived::class);
    }

    public function test_does_not_error_when_there_are_no_superadmins(): void
    {
        Notification::fake();

        $user = User::factory()->create(['is_superadmin' => false]);
        $company = Company::withoutGlobalScopes()->create(['name' => 'Acme', 'slug' => 'acme']);
        $feedback = $this->makeFeedback($company, $user);

        FeedbackSubmitted::dispatch($feedback);

        Notification::assertNothingSent();
    }
}
