<?php

namespace Tests\Feature\Notifications;

use App\Events\RecommendationCreated;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Recommendation;
use App\Models\User;
use App\Notifications\FirstRecommendationReady;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SendWelcomeEmailOnFirstRecommendationTest extends TestCase
{
    use RefreshDatabase;

    private function makeRecommendation(Company $company): Recommendation
    {
        return Recommendation::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'status' => 'pending',
        ]);
    }

    public function test_notifies_the_company_owner_on_the_first_recommendation(): void
    {
        Notification::fake();

        $owner = User::factory()->create();
        $company = Company::withoutGlobalScopes()->create(['name' => 'Acme', 'slug' => 'acme']);
        CompanyMembership::create(['company_id' => $company->id, 'user_id' => $owner->id, 'role' => 'owner']);

        $recommendation = $this->makeRecommendation($company);
        RecommendationCreated::dispatch($recommendation);

        Notification::assertSentTo(
            $owner,
            FirstRecommendationReady::class,
            fn (FirstRecommendationReady $notification) => true
        );
    }

    public function test_does_not_notify_again_for_a_second_recommendation(): void
    {
        Notification::fake();

        $owner = User::factory()->create();
        $company = Company::withoutGlobalScopes()->create(['name' => 'Acme', 'slug' => 'acme']);
        CompanyMembership::create(['company_id' => $company->id, 'user_id' => $owner->id, 'role' => 'owner']);

        $first = $this->makeRecommendation($company);
        RecommendationCreated::dispatch($first);

        $second = $this->makeRecommendation($company);
        RecommendationCreated::dispatch($second);

        Notification::assertSentToTimes($owner, FirstRecommendationReady::class, 1);
    }

    public function test_does_not_error_when_the_company_has_no_owner_membership(): void
    {
        Notification::fake();

        $company = Company::withoutGlobalScopes()->create(['name' => 'Orphan Co', 'slug' => 'orphan-co']);
        $recommendation = $this->makeRecommendation($company);

        RecommendationCreated::dispatch($recommendation);

        Notification::assertNothingSent();
    }
}
