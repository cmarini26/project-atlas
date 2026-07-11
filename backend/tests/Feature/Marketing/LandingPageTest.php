<?php

namespace Tests\Feature\Marketing;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the marketing landing page build — see docs/marketing/Landing-Page.md
 * and docs/design/System.md.
 */
class LandingPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_sees_the_marketing_landing_page(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Marketing/Landing'));
    }

    public function test_authenticated_user_is_redirected_to_their_dashboard(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/')
            ->assertRedirect(route('app.dashboard'));
    }

    public function test_root_route_is_named_home(): void
    {
        $this->assertSame('/', route('home', absolute: false));
    }
}
