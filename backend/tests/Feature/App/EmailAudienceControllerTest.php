<?php

namespace Tests\Feature\App;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\EmailAudience;
use App\Models\EmailContact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailAudienceControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_requires_auth(): void
    {
        $this->get('/app/settings/email/audiences')->assertRedirect('/login');
    }

    public function test_index_lists_the_companys_audiences_with_member_counts(): void
    {
        [$user, $company] = $this->userWithCompany();

        $audience = EmailAudience::create(['company_id' => $company->id, 'name' => 'Newsletter', 'status' => 'active']);
        $contact = EmailContact::create([
            'company_id' => $company->id, 'email' => 'a@example.com', 'normalized_email' => 'a@example.com',
        ]);
        $audience->members()->attach($contact->id);

        $this->actingAs($user)
            ->get('/app/settings/email/audiences')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('App/Settings/Email/Audiences/Index')
                ->where('audiences.0.name', 'Newsletter')
                ->where('audiences.0.member_count', 1)
            );
    }

    public function test_index_does_not_list_another_companys_audiences(): void
    {
        [$user] = $this->userWithCompany();
        $other = Company::withoutGlobalScopes()->create(['name' => 'Other', 'slug' => 'other-co']);
        EmailAudience::create(['company_id' => $other->id, 'name' => 'Other Newsletter', 'status' => 'active']);

        $this->actingAs($user)
            ->get('/app/settings/email/audiences')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('audiences', 0));
    }

    public function test_store_creates_an_audience(): void
    {
        [$user, $company] = $this->userWithCompany();

        $this->actingAs($user)
            ->post('/app/settings/email/audiences', ['name' => 'Newsletter'])
            ->assertRedirect();

        $this->assertDatabaseHas('email_audiences', ['company_id' => $company->id, 'name' => 'Newsletter', 'status' => 'active']);
    }

    public function test_store_requires_a_name(): void
    {
        [$user] = $this->userWithCompany();

        $this->actingAs($user)
            ->post('/app/settings/email/audiences', [])
            ->assertSessionHasErrors(['name']);
    }

    public function test_update_renames_an_audience(): void
    {
        [$user, $company] = $this->userWithCompany();
        $audience = EmailAudience::create(['company_id' => $company->id, 'name' => 'Old Name', 'status' => 'active']);

        $this->actingAs($user)
            ->patch("/app/settings/email/audiences/{$audience->id}", ['name' => 'New Name'])
            ->assertRedirect();

        $this->assertSame('New Name', $audience->fresh()->name);
    }

    public function test_update_archives_an_audience_without_deleting_it(): void
    {
        [$user, $company] = $this->userWithCompany();
        $audience = EmailAudience::create(['company_id' => $company->id, 'name' => 'Newsletter', 'status' => 'active']);

        $this->actingAs($user)
            ->patch("/app/settings/email/audiences/{$audience->id}", ['archived' => true])
            ->assertRedirect();

        $this->assertSame('archived', $audience->fresh()->status->value);
        $this->assertDatabaseHas('email_audiences', ['id' => $audience->id]);
    }

    public function test_a_company_cannot_update_another_companys_audience(): void
    {
        [$user] = $this->userWithCompany();
        $other = Company::withoutGlobalScopes()->create(['name' => 'Other', 'slug' => 'other-co']);
        $audience = EmailAudience::create(['company_id' => $other->id, 'name' => 'Other Newsletter', 'status' => 'active']);

        $this->actingAs($user)
            ->patch("/app/settings/email/audiences/{$audience->id}", ['name' => 'Hijacked'])
            ->assertNotFound();

        $this->assertSame('Other Newsletter', $audience->fresh()->name);
    }

    public function test_show_returns_members(): void
    {
        [$user, $company] = $this->userWithCompany();
        $audience = EmailAudience::create(['company_id' => $company->id, 'name' => 'Newsletter', 'status' => 'active']);
        $contact = EmailContact::create([
            'company_id' => $company->id, 'email' => 'a@example.com', 'normalized_email' => 'a@example.com',
        ]);
        $audience->members()->attach($contact->id);

        $this->actingAs($user)
            ->get("/app/settings/email/audiences/{$audience->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('App/Settings/Email/Audiences/Show')
                ->where('members.0.email', 'a@example.com')
            );
    }

    public function test_a_company_cannot_view_another_companys_audience(): void
    {
        [$user] = $this->userWithCompany();
        $other = Company::withoutGlobalScopes()->create(['name' => 'Other', 'slug' => 'other-co']);
        $audience = EmailAudience::create(['company_id' => $other->id, 'name' => 'Other Newsletter', 'status' => 'active']);

        $this->actingAs($user)
            ->get("/app/settings/email/audiences/{$audience->id}")
            ->assertNotFound();
    }

    public function test_add_member_creates_the_contact_and_attaches_it(): void
    {
        [$user, $company] = $this->userWithCompany();
        $audience = EmailAudience::create(['company_id' => $company->id, 'name' => 'Newsletter', 'status' => 'active']);

        $this->actingAs($user)
            ->post("/app/settings/email/audiences/{$audience->id}/members", [
                'email' => 'Alice@Example.com',
                'display_name' => 'Alice',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('email_contacts', [
            'company_id' => $company->id,
            'normalized_email' => 'alice@example.com',
        ]);
        $this->assertSame(1, $audience->fresh()->members()->count());
    }

    public function test_add_member_requires_a_valid_email(): void
    {
        [$user, $company] = $this->userWithCompany();
        $audience = EmailAudience::create(['company_id' => $company->id, 'name' => 'Newsletter', 'status' => 'active']);

        $this->actingAs($user)
            ->post("/app/settings/email/audiences/{$audience->id}/members", ['email' => 'not-an-email'])
            ->assertSessionHasErrors(['email']);
    }

    public function test_remove_member_detaches_without_deleting_the_contact(): void
    {
        [$user, $company] = $this->userWithCompany();
        $audience = EmailAudience::create(['company_id' => $company->id, 'name' => 'Newsletter', 'status' => 'active']);
        $contact = EmailContact::create([
            'company_id' => $company->id, 'email' => 'a@example.com', 'normalized_email' => 'a@example.com',
        ]);
        $audience->members()->attach($contact->id);

        $this->actingAs($user)
            ->delete("/app/settings/email/audiences/{$audience->id}/members/{$contact->id}")
            ->assertRedirect();

        $this->assertSame(0, $audience->fresh()->members()->count());
        $this->assertDatabaseHas('email_contacts', ['id' => $contact->id]);
    }

    public function test_a_company_cannot_remove_another_companys_contact(): void
    {
        [$user, $company] = $this->userWithCompany();
        $other = Company::withoutGlobalScopes()->create(['name' => 'Other', 'slug' => 'other-co']);
        $audience = EmailAudience::create(['company_id' => $company->id, 'name' => 'Newsletter', 'status' => 'active']);
        $otherContact = EmailContact::create([
            'company_id' => $other->id, 'email' => 'a@example.com', 'normalized_email' => 'a@example.com',
        ]);

        $this->actingAs($user)
            ->delete("/app/settings/email/audiences/{$audience->id}/members/{$otherContact->id}")
            ->assertNotFound();
    }

    /** @return array{User, Company} */
    private function userWithCompany(string $role = 'owner'): array
    {
        $user = User::factory()->create();
        $company = Company::withoutGlobalScopes()->create(['name' => 'Test Co', 'slug' => 'test-co']);
        CompanyMembership::create(['company_id' => $company->id, 'user_id' => $user->id, 'role' => $role]);

        return [$user, $company];
    }
}
