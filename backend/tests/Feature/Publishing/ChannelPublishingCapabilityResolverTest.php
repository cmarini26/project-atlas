<?php

namespace Tests\Feature\Publishing;

use App\Models\Channel;
use App\Models\ChannelCredentials;
use App\Models\Company;
use App\Services\Publishing\ChannelPublishingCapabilityResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChannelPublishingCapabilityResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_wordpress_credentials_enable_only_the_companys_active_blog_channel(): void
    {
        $company = Company::withoutGlobalScopes()->create(['name' => 'Northwind', 'slug' => 'northwind']);
        $other = Company::withoutGlobalScopes()->create(['name' => 'Other', 'slug' => 'other']);
        $blog = $this->blog($company, 'https://northwind.example');
        $otherBlog = $this->blog($other, 'https://other.example');

        $this->credentials($company, 'active');

        $resolver = $this->app->make(ChannelPublishingCapabilityResolver::class);

        $this->assertTrue($resolver->supportsPublishing($company, $blog));
        $this->assertSame('https://northwind.example', $resolver->publishingTarget($company, $blog));
        $this->assertFalse($resolver->supportsPublishing($company, $otherBlog));
    }

    public function test_revoked_or_expired_wordpress_credentials_keep_blog_draft_only(): void
    {
        $company = Company::withoutGlobalScopes()->create(['name' => 'Northwind', 'slug' => 'northwind']);
        $blog = $this->blog($company, 'https://northwind.example');

        $resolver = $this->app->make(ChannelPublishingCapabilityResolver::class);

        $credentials = $this->credentials($company, 'revoked');

        $this->assertFalse($resolver->supportsPublishing($company, $blog));
        $this->assertNull($resolver->publishingTarget($company, $blog));

        $credentials->update(['status' => 'active', 'expires_at' => now()->subMinute()]);

        $this->assertFalse($resolver->supportsPublishing($company, $blog));
        $this->assertNull($resolver->publishingTarget($company, $blog));
    }

    private function blog(Company $company, string $siteUrl): Channel
    {
        return Channel::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'type' => 'blog',
            'name' => 'WordPress Blog',
            'config' => ['site_url' => $siteUrl],
            'is_active' => true,
        ]);
    }

    private function credentials(Company $company, string $status, $expiresAt = null): ChannelCredentials
    {
        return ChannelCredentials::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'channel_type' => 'blog',
            'provider_type' => 'wordpress',
            'credentials' => json_encode(['username' => 'atlas', 'app_password' => 'secret']),
            'status' => $status,
            'expires_at' => $expiresAt,
        ]);
    }
}
