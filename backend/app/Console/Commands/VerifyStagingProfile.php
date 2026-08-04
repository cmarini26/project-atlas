<?php

namespace App\Console\Commands;

use App\Models\Channel;
use App\Models\ChannelCredentials;
use App\Models\Company;
use App\Models\DiscoveryRun;
use App\Models\EmailAudience;
use App\Models\EmailContact;
use App\Models\MarketingChannel;
use App\Models\OnboardingProfile;
use App\Models\Recommendation;
use App\Models\User;
use Illuminate\Console\Command;

class VerifyStagingProfile extends Command
{
    protected $signature = 'atlas:verify-staging
        {profile=northwind : Which synthetic profile to verify}
        {--owner-email=northwind-owner@atlas.test : Expected owner email}
        {--expect-discovery : Fail if no discovery run exists}
        {--expect-recommendation : Fail if no recommendation exists}';

    protected $description = 'Verify a synthetic staging business profile is seeded and ready for staging validation';

    public function handle(): int
    {
        $profile = (string) $this->argument('profile');

        if ($profile !== 'northwind') {
            $this->error("Unsupported staging profile [{$profile}]. Only [northwind] is currently available.");

            return self::FAILURE;
        }

        $expectedOwnerEmail = (string) $this->option('owner-email');
        $failures = [];

        $company = Company::withoutGlobalScopes()->where('name', 'Northwind Skin Studio')->first();
        if ($company === null) {
            $this->error('Northwind Skin Studio company record was not found. Run php artisan atlas:seed-staging first.');

            return self::FAILURE;
        }

        $owner = User::withoutGlobalScopes()->where('email', $expectedOwnerEmail)->first();
        if ($owner === null) {
            $failures[] = "Owner [{$expectedOwnerEmail}] was not found.";
        }

        $profileRow = OnboardingProfile::withoutGlobalScopes()->where('company_id', $company->id)->first();
        if ($profileRow === null || ! $profileRow->isComplete()) {
            $failures[] = 'Onboarding profile is missing or incomplete.';
        }

        $declaredTypes = MarketingChannel::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->get()
            ->map(fn (MarketingChannel $channel) => $channel->type->value)
            ->all();
        $expectedDeclared = ['website', 'email', 'instagram', 'facebook', 'google_business_profile'];
        foreach ($expectedDeclared as $type) {
            if (! in_array($type, $declaredTypes, true)) {
                $failures[] = "Declared marketing channel [{$type}] is missing.";
            }
        }

        $website = MarketingChannel::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('type', 'website')
            ->first();
        if ($website === null || $website->handle_or_url !== 'https://northwindskinstudio.com') {
            $failures[] = 'Website marketing channel is missing the expected northwind URL.';
        }

        $blogChannel = Channel::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('type', 'blog')
            ->first();
        if ($blogChannel === null) {
            $failures[] = 'Real blog publishing channel is missing.';
        }

        $audienceCount = EmailAudience::withoutGlobalScopes()->where('company_id', $company->id)->count();
        if ($audienceCount !== 4) {
            $failures[] = "Expected 4 email audiences, found {$audienceCount}.";
        }

        $contactCount = EmailContact::withoutGlobalScopes()->where('company_id', $company->id)->count();
        if ($contactCount !== 4) {
            $failures[] = "Expected 4 email contacts, found {$contactCount}.";
        }

        $wordpressCredentials = ChannelCredentials::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('channel_type', 'blog')
            ->where('status', 'active')
            ->first();
        $emailCredentials = ChannelCredentials::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('channel_type', 'email')
            ->where('status', 'active')
            ->first();

        $latestDiscovery = DiscoveryRun::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->latest('created_at')
            ->first();
        if ($this->option('expect-discovery') && $latestDiscovery === null) {
            $failures[] = 'Expected at least one discovery run, but none exists.';
        }

        $recommendationCount = Recommendation::withoutGlobalScopes()->where('company_id', $company->id)->count();
        if ($this->option('expect-recommendation') && $recommendationCount < 1) {
            $failures[] = 'Expected at least one recommendation, but none exists.';
        }

        $this->newLine();
        $this->info('Northwind staging verification summary');
        $this->table(
            ['Check', 'Status', 'Details'],
            [
                ['company', 'ok', $company->name.' / '.$company->slug],
                ['owner', $owner !== null ? 'ok' : 'missing', $expectedOwnerEmail],
                ['onboarding', $profileRow !== null && $profileRow->isComplete() ? 'ok' : 'missing', $profileRow !== null && $profileRow->completed_at !== null ? (string) $profileRow->completed_at : 'not completed'],
                ['declared_channels', count($declaredTypes) >= 1 ? 'ok' : 'missing', implode(', ', $declaredTypes)],
                ['blog_channel', $blogChannel !== null ? 'ok' : 'missing', $blogChannel !== null ? $blogChannel->name : 'not found'],
                ['wordpress_connection', $wordpressCredentials !== null ? 'connected' : 'pending', $wordpressCredentials !== null ? $wordpressCredentials->status : 'not connected'],
                ['email_connection', $emailCredentials !== null ? 'connected' : 'pending', $emailCredentials !== null ? $emailCredentials->status : 'not connected'],
                ['audiences', $audienceCount === 4 ? 'ok' : 'mismatch', (string) $audienceCount],
                ['contacts', $contactCount === 4 ? 'ok' : 'mismatch', (string) $contactCount],
                ['discovery', $latestDiscovery !== null ? 'present' : 'pending', $latestDiscovery !== null ? $latestDiscovery->stage->value : 'no discovery run'],
                ['recommendations', $recommendationCount > 0 ? 'present' : 'pending', (string) $recommendationCount],
            ],
        );

        if ($failures !== []) {
            $this->newLine();
            foreach ($failures as $failure) {
                $this->error($failure);
            }

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Northwind staging profile verification passed.');

        if ($wordpressCredentials === null || $emailCredentials === null) {
            $this->warn('Connection-dependent staging checks are still pending: connect WordPress and email in Settings to complete the beta-critical path.');
        }

        return self::SUCCESS;
    }
}
