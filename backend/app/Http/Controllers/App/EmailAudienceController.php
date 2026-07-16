<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\EmailAudience;
use App\Models\EmailContact;
use App\Services\Publishing\Email\EmailAudienceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings CRUD for a company's email contacts/audiences — a thin delegate
 * to EmailAudienceService, the same "controller owns no domain logic" shape
 * MarketingPresenceController already uses for declared marketing channels.
 */
class EmailAudienceController extends Controller
{
    public function __construct(private readonly EmailAudienceService $audiences) {}

    public function index(Request $request): Response
    {
        $company = $this->company($request);

        $audiences = EmailAudience::where('company_id', $company->id)
            ->withCount('members')
            ->orderBy('name')
            ->get();

        return Inertia::render('App/Settings/Email/Audiences/Index', [
            'audiences' => $audiences->map(fn (EmailAudience $a) => [
                'id' => $a->id,
                'name' => $a->name,
                'status' => $a->status->value,
                'member_count' => $a->members_count,
            ])->values()->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $company = $this->company($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $this->audiences->createAudience($company, $validated['name']);

        return back()->with('success', 'Audience created.');
    }

    public function update(Request $request, EmailAudience $emailAudience): RedirectResponse
    {
        $company = $this->company($request);
        abort_if($emailAudience->company_id !== $company->id, 404);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'archived' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('name', $validated)) {
            $this->audiences->renameAudience($emailAudience, $validated['name']);
        }

        if (($validated['archived'] ?? false) === true) {
            $this->audiences->archiveAudience($emailAudience);
        }

        return back()->with('success', 'Audience updated.');
    }

    public function show(Request $request, EmailAudience $emailAudience): Response
    {
        $company = $this->company($request);
        abort_if($emailAudience->company_id !== $company->id, 404);

        $members = $emailAudience->members()->orderBy('email')->get();

        return Inertia::render('App/Settings/Email/Audiences/Show', [
            'audience' => [
                'id' => $emailAudience->id,
                'name' => $emailAudience->name,
                'status' => $emailAudience->status->value,
            ],
            'members' => $members->map(fn (EmailContact $c) => [
                'id' => $c->id,
                'email' => $c->email,
                'display_name' => $c->display_name,
                'status' => $c->status->value,
            ])->values()->all(),
        ]);
    }

    public function addMember(Request $request, EmailAudience $emailAudience): RedirectResponse
    {
        $company = $this->company($request);
        abort_if($emailAudience->company_id !== $company->id, 404);

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'display_name' => ['nullable', 'string', 'max:255'],
        ]);

        $contact = $this->audiences->addOrReactivateContact(
            $company,
            $validated['email'],
            $validated['display_name'] ?? null,
        );

        $this->audiences->addMember($emailAudience, $contact);

        return back()->with('success', 'Contact added to audience.');
    }

    public function removeMember(Request $request, EmailAudience $emailAudience, EmailContact $emailContact): RedirectResponse
    {
        $company = $this->company($request);
        abort_if($emailAudience->company_id !== $company->id, 404);
        abort_if($emailContact->company_id !== $company->id, 404);

        $this->audiences->removeMember($emailAudience, $emailContact);

        return back()->with('success', 'Contact removed from audience.');
    }

    private function company(Request $request): Company
    {
        /** @var Company $company */
        $company = $request->attributes->get('company');

        return $company;
    }
}
