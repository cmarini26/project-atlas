<?php

namespace Tests\Unit\Observatory;

use App\Services\Observatory\Connectors\Instagram\Exceptions\InstagramApiException;
use App\Services\Observatory\Connectors\Instagram\InstagramMediaFetcher;
use App\Services\Observatory\Connectors\Instagram\InstagramMediaItemData;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Tests\TestCase;

class InstagramMediaFetcherTest extends TestCase
{
    private function makeFetcher(MockHandler $mock): InstagramMediaFetcher
    {
        $stack = HandlerStack::create($mock);
        $client = new Client(['handler' => $stack]);

        return new InstagramMediaFetcher(client: $client);
    }

    public function test_fetch_recent_media_maps_a_full_graph_api_response(): void
    {
        $fetcher = $this->makeFetcher(new MockHandler([
            new Response(200, [], json_encode([
                'data' => [
                    [
                        'id' => '1001',
                        'caption' => 'Ending soon! #comics #auction cc @cbb_collector shop now',
                        'timestamp' => '2026-07-01T12:00:00+0000',
                        'media_type' => 'IMAGE',
                        'permalink' => 'https://instagram.com/p/abc',
                        'like_count' => 120,
                        'comments_count' => 8,
                    ],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]));

        $items = $fetcher->fetchRecentMedia('token-123');

        $this->assertCount(1, $items);
        $item = $items->first();
        $this->assertInstanceOf(InstagramMediaItemData::class, $item);
        $this->assertSame('1001', $item->id);
        $this->assertSame('IMAGE', $item->mediaType);
        $this->assertSame('https://instagram.com/p/abc', $item->permalink);
        $this->assertSame(120, $item->likeCount);
        $this->assertSame(8, $item->commentsCount);
        $this->assertSame(['comics', 'auction'], $item->hashtags);
        $this->assertSame(['cbb_collector'], $item->mentions);
    }

    public function test_fetch_recent_media_handles_missing_optional_fields(): void
    {
        $fetcher = $this->makeFetcher(new MockHandler([
            new Response(200, [], json_encode([
                'data' => [
                    ['id' => '1001', 'timestamp' => '2026-07-01T12:00:00+0000'],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]));

        $item = $fetcher->fetchRecentMedia('token-123')->first();

        $this->assertNull($item->caption);
        $this->assertNull($item->permalink);
        $this->assertNull($item->likeCount);
        $this->assertNull($item->commentsCount);
        $this->assertSame('UNKNOWN', $item->mediaType);
        $this->assertSame([], $item->hashtags);
        $this->assertSame([], $item->mentions);
    }

    public function test_fetch_recent_media_skips_malformed_entries(): void
    {
        $fetcher = $this->makeFetcher(new MockHandler([
            new Response(200, [], json_encode([
                'data' => [
                    ['id' => '', 'timestamp' => '2026-07-01T12:00:00+0000'],
                    ['id' => '1002'],
                    ['id' => '1003', 'timestamp' => '2026-07-02T12:00:00+0000'],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]));

        $items = $fetcher->fetchRecentMedia('token-123');

        $this->assertCount(1, $items);
        $this->assertSame('1003', $items->first()->id);
    }

    public function test_fetch_recent_media_returns_an_empty_collection_for_no_posts(): void
    {
        $fetcher = $this->makeFetcher(new MockHandler([
            new Response(200, [], json_encode(['data' => []], JSON_THROW_ON_ERROR)),
        ]));

        $items = $fetcher->fetchRecentMedia('token-123');

        $this->assertCount(0, $items);
    }

    public function test_throws_when_the_response_is_missing_the_data_array(): void
    {
        $fetcher = $this->makeFetcher(new MockHandler([
            new Response(200, [], json_encode(['error' => 'nope'], JSON_THROW_ON_ERROR)),
        ]));

        $this->expectException(InstagramApiException::class);
        $this->expectExceptionMessage('missing the required data array');

        $fetcher->fetchRecentMedia('token-123');
    }

    public function test_throws_when_the_request_fails(): void
    {
        $mock = new MockHandler([
            new ConnectException('Connection refused', new Request('GET', 'https://graph.instagram.com/me/media')),
        ]);

        $fetcher = $this->makeFetcher($mock);

        $this->expectException(InstagramApiException::class);
        $this->expectExceptionMessage('Instagram Graph API media request failed');

        $fetcher->fetchRecentMedia('token-123');
    }

    public function test_passes_the_limit_as_a_query_parameter(): void
    {
        $history = [];
        $mock = new MockHandler([
            new Response(200, [], json_encode(['data' => []], JSON_THROW_ON_ERROR)),
        ]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        $fetcher = new InstagramMediaFetcher(client: new Client(['handler' => $stack]));

        $fetcher->fetchRecentMedia('token-123', 5);

        $query = $history[0]['request']->getUri()->getQuery();
        $this->assertStringContainsString('limit=5', $query);
    }
}
