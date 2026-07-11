<?php

namespace Tests\Unit\Observatory;

use App\Services\Observatory\Connectors\Instagram\Exceptions\InstagramApiException;
use App\Services\Observatory\Connectors\Instagram\InstagramProfileData;
use App\Services\Observatory\Connectors\Instagram\InstagramProfileFetcher;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Tests\TestCase;

class InstagramProfileFetcherTest extends TestCase
{
    private function makeFetcher(MockHandler $mock): InstagramProfileFetcher
    {
        $stack = HandlerStack::create($mock);
        $client = new Client(['handler' => $stack]);

        return new InstagramProfileFetcher(client: $client);
    }

    public function test_fetch_profile_maps_a_full_graph_api_response(): void
    {
        $fetcher = $this->makeFetcher(new MockHandler([
            new Response(200, [], json_encode([
                'id' => '17841400000000',
                'username' => 'cbb_auctions',
                'name' => 'CBB Auctions',
                'biography' => 'Comic book auctions every week.',
                'website' => 'https://cbbauctions.com',
                'profile_picture_url' => 'https://example.com/pic.jpg',
                'followers_count' => 4210,
                'follows_count' => 180,
            ], JSON_THROW_ON_ERROR)),
        ]));

        $profile = $fetcher->fetchProfile('token-123');

        $this->assertInstanceOf(InstagramProfileData::class, $profile);
        $this->assertSame('17841400000000', $profile->accountId);
        $this->assertSame('cbb_auctions', $profile->username);
        $this->assertSame('CBB Auctions', $profile->displayName);
        $this->assertSame('Comic book auctions every week.', $profile->bio);
        $this->assertSame('https://cbbauctions.com', $profile->website);
        $this->assertSame('https://example.com/pic.jpg', $profile->profilePictureUrl);
        $this->assertSame(4210, $profile->followerCount);
        $this->assertSame(180, $profile->followingCount);
    }

    public function test_fetch_profile_handles_missing_optional_fields(): void
    {
        $fetcher = $this->makeFetcher(new MockHandler([
            new Response(200, [], json_encode([
                'id' => '17841400000000',
                'username' => 'cbb_auctions',
            ], JSON_THROW_ON_ERROR)),
        ]));

        $profile = $fetcher->fetchProfile('token-123');

        $this->assertNull($profile->displayName);
        $this->assertNull($profile->bio);
        $this->assertNull($profile->website);
        $this->assertNull($profile->profilePictureUrl);
        $this->assertNull($profile->followerCount);
        $this->assertNull($profile->followingCount);
    }

    public function test_throws_when_response_is_missing_required_fields(): void
    {
        $fetcher = $this->makeFetcher(new MockHandler([
            new Response(200, [], json_encode(['name' => 'No id or username'], JSON_THROW_ON_ERROR)),
        ]));

        $this->expectException(InstagramApiException::class);
        $this->expectExceptionMessage('missing the required id/username fields');

        $fetcher->fetchProfile('token-123');
    }

    public function test_throws_when_the_request_fails(): void
    {
        $mock = new MockHandler([
            new ConnectException('Connection refused', new Request('GET', 'https://graph.instagram.com/me')),
        ]);

        $fetcher = $this->makeFetcher($mock);

        $this->expectException(InstagramApiException::class);
        $this->expectExceptionMessage('Instagram Graph API request failed');

        $fetcher->fetchProfile('token-123');
    }
}
