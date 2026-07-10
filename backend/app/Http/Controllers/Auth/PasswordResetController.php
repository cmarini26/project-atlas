<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Mail\ProductionMailerGuard;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class PasswordResetController extends Controller
{
    public function request(): Response
    {
        return Inertia::render('Auth/ForgotPassword');
    }

    public function email(Request $request, ProductionMailerGuard $mailerGuard): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        $mailer = (string) config('mail.default');

        if ($mailerGuard->isMisconfigured(app()->environment(), $mailer)) {
            // MAIL_MAILER=log/array never throws — it "succeeds" by writing
            // the email to a log file instead of delivering it, so this has
            // to be caught explicitly rather than via the catch below.
            Log::critical('PasswordResetController: refusing to send — production is configured with a non-delivery mailer.', [
                'mailer' => $mailer,
            ]);
        } else {
            try {
                Password::sendResetLink($request->only('email'));
            } catch (Throwable $e) {
                Log::error('PasswordResetController: password reset email failed to send.', [
                    'email' => $request->input('email'),
                    'mailer' => $mailer,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Always report the same success message — whether or not the email
        // has an account, and whether or not delivery actually succeeded.
        return back()->with('success', 'If an account exists for that email, a reset link is on its way.');
    }

    public function reset(Request $request, string $token): Response
    {
        return Inertia::render('Auth/ResetPassword', [
            'token' => $token,
            'email' => (string) $request->query('email', ''),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $status = Password::reset(
            $validated,
            function (User $user, string $password): void {
                $user->forceFill(['password' => Hash::make($password)])->save();

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PasswordReset) {
            return back()->withErrors(['email' => __($status)])->onlyInput('email');
        }

        return redirect()->route('login')->with('success', 'Your password has been reset. You can sign in now.');
    }
}
