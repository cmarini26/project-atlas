<?php

namespace Tests\Feature\Publishing;

use App\Models\ChannelCredentials;
use App\Models\Company;
use App\Models\DigitalTwin;
use App\Services\Publishing\ChannelCredentialsRepository;
use App\Services\Publishing\Exceptions\AuthenticationException;
use App\Services\Publishing\Exceptions\CredentialsExpiredException;
use App\Services\Publishing\Exceptions\CredentialsNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChannelCredentialsRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private ChannelCredentialsRepository $repository;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = $this->app->make(ChannelCredentialsRepository::class);

        $this->company = Company::withoutGlobalScopes()->create([
            'name' => 'CBB Auctions', 'slug' => 'cbb', 'industry' => 'auction',
        ]);

        DigitalTwin::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'status' => 'active', 'health_score' => 80,
        ]);
    }

    private function makeCredentials(array $overrides = []): ChannelCredentials
    {
        return ChannelCredentials::withoutGlobalScopes()->create(array_merge([
            'company_id' => $this->company->id,
            'channel_type' => 'email',
            'status' => 'active',
            'credentials' => json_encode(['api_key' => 'test-key']),
            'expires_at' => null,
        ], $overrides));
    }

    public function test_returns_active_credentials(): void
    {
        $this->makeCredentials(['status' => 'active']);

        $result = $this->repository->for($this->company->id, 'email');

        $this->assertEquals('email', $result->channel_type);
        $this->assertEquals('active', $result->status);
    }

    public function test_throws_not_found_when_no_credentials_exist(): void
    {
        $this->expectException(CredentialsNotFoundException::class);

        $this->repository->for($this->company->id, 'email');
    }

    public function test_throws_not_found_when_credentials_are_revoked(): void
    {
        $this->makeCredentials(['status' => 'revoked']);

        $this->expectException(CredentialsNotFoundException::class);

        $this->repository->for($this->company->id, 'email');
    }

    public function test_throws_expired_when_status_is_expired(): void
    {
        $this->makeCredentials(['status' => 'expired']);

        $this->expectException(CredentialsExpiredException::class);

        $this->repository->for($this->company->id, 'email');
    }

    public function test_throws_expired_when_expires_at_is_in_the_past(): void
    {
        $this->makeCredentials([
            'status' => 'active',
            'expires_at' => now()->subDay(),
        ]);

        $this->expectException(CredentialsExpiredException::class);

        $this->repository->for($this->company->id, 'email');
    }

    public function test_does_not_throw_expired_when_expires_at_is_in_the_future(): void
    {
        $this->makeCredentials([
            'status' => 'active',
            'expires_at' => now()->addDay(),
        ]);

        $result = $this->repository->for($this->company->id, 'email');

        $this->assertEquals('active', $result->status);
    }

    public function test_throws_authentication_exception_when_status_is_error(): void
    {
        $this->makeCredentials(['status' => 'error']);

        $this->expectException(AuthenticationException::class);

        $this->repository->for($this->company->id, 'email');
    }

    public function test_error_exception_is_not_retryable(): void
    {
        $this->makeCredentials(['status' => 'error']);

        try {
            $this->repository->for($this->company->id, 'email');
            $this->fail('Expected AuthenticationException not thrown.');
        } catch (AuthenticationException $e) {
            $this->assertFalse($e->isRetryable());
        }
    }

    public function test_expired_exception_is_not_retryable(): void
    {
        $this->makeCredentials(['status' => 'expired']);

        try {
            $this->repository->for($this->company->id, 'email');
            $this->fail('Expected CredentialsExpiredException not thrown.');
        } catch (CredentialsExpiredException $e) {
            $this->assertFalse($e->isRetryable());
        }
    }

    public function test_resolves_by_channel_type_for_correct_company(): void
    {
        $otherCompany = Company::withoutGlobalScopes()->create([
            'name' => 'Other Co', 'slug' => 'other', 'industry' => 'retail',
        ]);
        DigitalTwin::withoutGlobalScopes()->create([
            'company_id' => $otherCompany->id, 'status' => 'active', 'health_score' => 80,
        ]);

        ChannelCredentials::withoutGlobalScopes()->create([
            'company_id' => $otherCompany->id,
            'channel_type' => 'email',
            'status' => 'active',
            'credentials' => json_encode(['api_key' => 'other-key']),
        ]);

        $this->expectException(CredentialsNotFoundException::class);

        $this->repository->for($this->company->id, 'email');
    }
}
