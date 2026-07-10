<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use RuntimeException;
use Tests\TestCase;

/**
 * Covers Critical Production Blocker 6's delivery-safety requirements — see
 * docs/plans/Critical-Production-Blockers.md. Complements the existing
 * anti-enumeration/reset-flow coverage in PasswordResetTest.php, which is
 * unmodified and still passes unchanged.
 */
class PasswordResetDeliveryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Flipping app()->environment() to 'production' also disables Laravel's
     * own runningUnitTests()-based CSRF bypass (it checks the same 'env'
     * binding), so these tests must disable CSRF explicitly instead of
     * relying on the implicit testing-only exemption every other test here
     * gets for free.
     */
    private function actingAsProduction(): void
    {
        $this->app['env'] = 'production';
        $this->withoutMiddleware(PreventRequestForgery::class);
    }

    public function test_production_with_log_mailer_refuses_to_attempt_delivery(): void
    {
        Notification::fake();
        $this->actingAsProduction();
        config(['mail.default' => 'log']);

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertNothingSent();
    }

    public function test_production_with_log_mailer_logs_a_critical_structured_message(): void
    {
        Log::spy();
        $this->actingAsProduction();
        config(['mail.default' => 'log']);

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Log::shouldHaveReceived('critical')
            ->once()
            ->withArgs(fn (string $message, array $context) => $message === 'PasswordResetController: refusing to send — production is configured with a non-delivery mailer.'
                && $context['mailer'] === 'log'
            );
    }

    public function test_production_with_log_mailer_still_returns_the_generic_success_response(): void
    {
        $this->actingAsProduction();
        config(['mail.default' => 'log']);

        $user = User::factory()->create();

        $response = $this->post('/forgot-password', ['email' => $user->email]);

        $response->assertRedirect();
        $response->assertSessionHas('success', 'If an account exists for that email, a reset link is on its way.');
    }

    public function test_production_with_postmark_mailer_attempts_delivery_normally(): void
    {
        Notification::fake();
        $this->actingAsProduction();
        config(['mail.default' => 'postmark', 'services.postmark.key' => 'test-token']);

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_local_and_test_configuration_remains_safe_and_sends_normally(): void
    {
        Notification::fake();
        // The guard must never trigger outside 'production' — 'testing'
        // (this suite's real environment) uses the safe 'array' mailer,
        // and 'local' commonly uses 'log'; neither ever delivers real email.
        $this->assertSame('testing', app()->environment());
        $this->assertNotSame('production', app()->environment());

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_delivery_failure_is_caught_and_logged_without_leaking_secrets(): void
    {
        Log::spy();

        Password::shouldReceive('sendResetLink')
            ->once()
            ->andThrow(new RuntimeException('Postmark: 401 Unauthorized'));

        $response = $this->post('/forgot-password', ['email' => 'someone@example.com']);

        $response->assertRedirect();
        $response->assertSessionHas('success', 'If an account exists for that email, a reset link is on its way.');

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                $this->assertArrayNotHasKey('token', $context);
                $this->assertArrayNotHasKey('password', $context);

                return $message === 'PasswordResetController: password reset email failed to send.'
                    && $context['email'] === 'someone@example.com'
                    && $context['error'] === 'Postmark: 401 Unauthorized';
            });
    }

    public function test_no_user_enumeration_regression_when_mailer_is_misconfigured(): void
    {
        Notification::fake();
        $this->actingAsProduction();
        config(['mail.default' => 'log']);

        // Both an existing and a non-existent account get the identical
        // response even while the guard is refusing to attempt delivery.
        $existing = User::factory()->create();

        $this->post('/forgot-password', ['email' => $existing->email])
            ->assertSessionHas('success', 'If an account exists for that email, a reset link is on its way.');

        $this->post('/forgot-password', ['email' => 'nobody@example.com'])
            ->assertSessionHas('success', 'If an account exists for that email, a reset link is on its way.');
    }
}
