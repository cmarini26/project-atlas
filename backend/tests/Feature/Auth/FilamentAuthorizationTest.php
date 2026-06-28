<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Filament\Panel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FilamentAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Panel $panel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->panel = app(Panel::class);
    }

    public function test_superadmin_can_access_filament_panel(): void
    {
        $user = User::factory()->superadmin()->create();

        $this->assertTrue($user->canAccessPanel($this->panel));
    }

    public function test_regular_user_cannot_access_filament_panel(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($user->canAccessPanel($this->panel));
    }

    public function test_is_superadmin_returns_true_for_superadmin(): void
    {
        $user = User::factory()->superadmin()->create();

        $this->assertTrue($user->isSuperadmin());
    }

    public function test_is_superadmin_returns_false_for_regular_user(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($user->isSuperadmin());
    }

    public function test_is_superadmin_flag_defaults_to_false_from_factory(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($user->is_superadmin);
    }

    public function test_superadmin_factory_state_sets_flag_to_true(): void
    {
        $user = User::factory()->superadmin()->create();

        $this->assertTrue($user->is_superadmin);
    }

    public function test_is_superadmin_persists_to_database(): void
    {
        $user = User::factory()->superadmin()->create();

        $fresh = User::find($user->id);

        $this->assertNotNull($fresh);
        $this->assertTrue($fresh->is_superadmin);
    }

    public function test_non_superadmin_persists_to_database(): void
    {
        $user = User::factory()->create();

        $fresh = User::find($user->id);

        $this->assertNotNull($fresh);
        $this->assertFalse($fresh->is_superadmin);
    }

    public function test_filament_redirects_unauthenticated_user(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_filament_forbids_authenticated_non_superadmin(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/admin')->assertForbidden();
    }

    public function test_filament_accessible_by_superadmin(): void
    {
        $user = User::factory()->superadmin()->create();

        $this->actingAs($user)->get('/admin')->assertSuccessful();
    }
}
