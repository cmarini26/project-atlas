<?php

namespace App\Http\Controllers\App;

use App\Events\FeedbackSubmitted;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Feedback;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class FeedbackController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        /** @var Company $company */
        $company = $request->attributes->get('company');

        $validated = $request->validate([
            'score' => ['required', 'integer', 'min:1', 'max:10'],
            'comment' => ['nullable', 'string', 'max:500'],
            'context' => ['nullable', 'array'],
        ]);

        $feedback = Feedback::create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'score' => $validated['score'],
            'comment' => $validated['comment'] ?? null,
            'context' => $validated['context'] ?? null,
        ]);

        FeedbackSubmitted::dispatch($feedback);

        return back();
    }
}
