<?php

namespace App\Console\Commands;

use App\Enums\EmailConsentStatus;
use App\Enums\EmailContactSource;
use App\Enums\EmailContactStatus;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\EmailAudience;
use App\Models\EmailContact;
use App\Models\User;
use App\Services\Company\CompanyService;
use App\Services\Discovery\BusinessDiscoveryService;
use App\Services\Onboarding\OnboardingAssetService;
use App\Services\Onboarding\OnboardingProfileService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use JsonException;

class SeedStagingProfile extends Command
{
    protected $signature = 'atlas:seed-staging
        {profile=northwind : Which synthetic profile to seed}
        {--owner-email=northwind-owner@atlas.test : Owner email for the seeded company}
        {--owner-name=Northwind Owner : Owner name for the seeded company}
        {--owner-password= : Password for the synthetic owner; required outside local/testing}
        {--start-discovery : Start Business Discovery after seeding}';

    protected $description = 'Seed a synthetic staging business profile for Atlas validation';

    public function __construct(
        private readonly CompanyService $companyService,
        private readonly OnboardingProfileService $onboardingProfiles,
        private readonly OnboardingAssetService $onboardingAssets,
        private readonly BusinessDiscoveryService $discovery,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('Refusing to seed a synthetic staging profile in the production environment.');

            return self::FAILURE;
        }

        if (! app()->environment(['local', 'testing']) && ! $this->option('owner-password')) {
            $this->error('The --owner-password option is required outside local/testing environments.');

            return self::FAILURE;
        }

        $profile = (string) $this->argument('profile');

        if ($profile !== 'northwind') {
            $this->error("Unsupported staging profile [{$profile}]. Only [northwind] is currently available.");

            return self::FAILURE;
        }

        $data = $this->loadProfile($profile);
        $owner = $this->seedOwner();
        $company = $this->seedCompany($owner, $data);

        $this->seedOnboarding($company, $data);
        $this->seedAudiences($company, $data);

        if ($this->option('start-discovery')) {
            $this->discovery->start($company);
        }

        $this->info("Seeded staging profile [{$profile}] for company [{$company->name}].");
        $this->line('Owner: '.$owner->email);
        $this->line('Company slug: '.$company->slug);
        $this->line('Seed file: docs/testing/'.$profile.'-skin-studio-seed.json');

        if ($this->option('start-discovery')) {
            $this->line('Business Discovery: started');
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadProfile(string $profile): array
    {
        $path = base_path("../docs/testing/{$profile}-skin-studio-seed.json");

        if (! is_file($path)) {
            throw new \RuntimeException("Staging seed file not found: {$path}");
        }

        try {
            /** @var array<string, mixed> $data */
            $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new \RuntimeException('Invalid staging seed JSON: '.$e->getMessage(), previous: $e);
        }

        return $data;
    }

    private function seedOwner(): User
    {
        $password = (string) ($this->option('owner-password') ?: '');

        return User::withoutGlobalScopes()->updateOrCreate(
            ['email' => (string) $this->option('owner-email')],
            [
                'name' => (string) $this->option('owner-name'),
                'password' => Hash::make($password !== '' ? $password : 'password'),
                'email_verified_at' => now(),
                'is_superadmin' => false,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function seedCompany(User $owner, array $data): Company
    {
        $companyData = $data['company'];
        $name = (string) $companyData['name'];

        $company = Company::withoutGlobalScopes()->where('name', $name)->first();

        if ($company === null) {
            $company = $this->companyService->create($owner, [
                'name' => $name,
                'industry' => (string) $companyData['industry'],
                'description' => (string) $companyData['description'],
                'website_url' => (string) data_get($data, 'onboarding.assets.details.website.url', ''),
            ]);
        } else {
            $company->update([
                'industry' => (string) $companyData['industry'],
                'description' => (string) $companyData['description'],
                'website_url' => (string) data_get($data, 'onboarding.assets.details.website.url', ''),
            ]);

            CompanyMembership::withoutGlobalScopes()->updateOrCreate(
                ['company_id' => $company->id, 'user_id' => $owner->id],
                ['role' => 'owner', 'joined_at' => now()],
            );
        }

        return $company->fresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function seedOnboarding(Company $company, array $data): void
    {
        /** @var list<string> $goals */
        $goals = $data['onboarding']['goals'];
        $this->onboardingProfiles->saveGoals($company, $goals);

        /** @var list<string> $enabled */
        $enabled = $data['onboarding']['assets']['enabled'];
        /** @var list<string> $primary */
        $primary = $data['onboarding']['assets']['primary'];
        $this->onboardingAssets->syncEnabledAssets($company, $enabled, $primary);

        /** @var array<string, array<string, mixed>> $details */
        $details = $data['onboarding']['assets']['details'];
        $this->onboardingAssets->saveAssetDetails($company, $details);

        /** @var array{marketing_frequency:string,marketing_owner:string,is_seasonal:bool,seasonal_months:list<int>,primary_cta:string} $preferences */
        $preferences = $data['onboarding']['preferences'];
        $this->onboardingProfiles->savePreferences($company, $preferences);
        $this->onboardingProfiles->markCompleted($company);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function seedAudiences(Company $company, array $data): void
    {
        $audiences = [
            'new_leads' => 'New leads',
            'facial_clients' => 'Facial clients',
            'membership_prospects' => 'Membership prospects',
            'reactivation_candidates' => 'Reactivation candidates',
        ];

        $audienceModels = [];

        foreach ($audiences as $slug => $name) {
            $audienceModels[$slug] = EmailAudience::withoutGlobalScopes()->updateOrCreate(
                ['company_id' => $company->id, 'name' => $name],
                ['status' => 'active'],
            );
        }

        foreach ($data['seed_subscribers'] as $subscriber) {
            $contact = EmailContact::withoutGlobalScopes()->updateOrCreate(
                [
                    'company_id' => $company->id,
                    'normalized_email' => EmailContact::normalizeEmail((string) $subscriber['email']),
                ],
                [
                    'email' => (string) $subscriber['email'],
                    'display_name' => (string) $subscriber['name'],
                    'source' => EmailContactSource::Manual->value,
                    'consent_status' => EmailConsentStatus::Unknown->value,
                    'status' => EmailContactStatus::Active->value,
                ],
            );

            $segment = (string) $subscriber['segment'];
            if (isset($audienceModels[$segment])) {
                $audienceModels[$segment]->members()->syncWithoutDetaching([$contact->id]);
            }
        }
    }
}
