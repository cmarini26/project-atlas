<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\CompanyMembership;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CompanySelectorController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $memberships = CompanyMembership::with('company')
            ->where('user_id', $user->id)
            ->get();

        return Inertia::render('App/CompanySelector', [
            'companies' => $memberships->map(fn (CompanyMembership $m) => [
                'id' => $m->company_id,
                'name' => $m->company !== null ? $m->company->name : '',
                'industry' => $m->company !== null ? $m->company->industry : null,
                'role' => $m->role,
            ])->values()->all(),
        ]);
    }

    public function select(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $validated = $request->validate([
            'company_id' => ['required', 'string'],
        ]);

        $membership = CompanyMembership::where('user_id', $user->id)
            ->where('company_id', $validated['company_id'])
            ->firstOrFail();

        $request->session()->put('selected_company_id', $membership->company_id);

        return redirect()->route('app.dashboard');
    }
}
