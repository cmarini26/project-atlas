<?php

namespace Tests\Feature\Publishing\WordPress;

use App\Services\Publishing\WordPressMediaUploader;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

class WordPressMediaUploaderTest extends TestCase
{
    public function test_uploads_image_and_returns_media_id(): void
    {
        $history = [];
        $uploader = $this->makeUploader([
            new Response(200, ['Content-Type' => 'image/jpeg'], 'fake-bytes'),
            new Response(201, [], json_encode(['id' => 42])),
        ], $history);

        $id = $uploader->uploadFeaturedImage('https://blog.example.com', 'https://cdn.example.com/photo.jpg', 'admin', 'app-pass');

        $this->assertSame(42, $id);

        /** @var RequestInterface $uploadRequest */
        $uploadRequest = $history[1]['request'];
        $this->assertSame('https://blog.example.com/wp-json/wp/v2/media', (string) $uploadRequest->getUri());
        $this->assertStringContainsString('Basic ', $uploadRequest->getHeaderLine('Authorization'));
    }

    public function test_returns_null_when_the_image_fetch_fails(): void
    {
        $uploader = $this->makeUploader([
            new ConnectException('refused', new Request('GET', 'https://cdn.example.com/photo.jpg')),
        ]);

        $id = $uploader->uploadFeaturedImage('https://blog.example.com', 'https://cdn.example.com/photo.jpg', 'admin', 'app-pass');

        $this->assertNull($id);
    }

    public function test_returns_null_when_the_upload_request_fails(): void
    {
        $uploader = $this->makeUploader([
            new Response(200, ['Content-Type' => 'image/jpeg'], 'fake-bytes'),
            new ConnectException('refused', new Request('POST', 'https://blog.example.com/wp-json/wp/v2/media')),
        ]);

        $id = $uploader->uploadFeaturedImage('https://blog.example.com', 'https://cdn.example.com/photo.jpg', 'admin', 'app-pass');

        $this->assertNull($id);
    }

    public function test_returns_null_when_the_response_has_no_id(): void
    {
        $uploader = $this->makeUploader([
            new Response(200, ['Content-Type' => 'image/jpeg'], 'fake-bytes'),
            new Response(201, [], json_encode([])),
        ]);

        $id = $uploader->uploadFeaturedImage('https://blog.example.com', 'https://cdn.example.com/photo.jpg', 'admin', 'app-pass');

        $this->assertNull($id);
    }

    private function makeUploader(array $responses, ?array &$history = null): WordPressMediaUploader
    {
        $stack = HandlerStack::create(new MockHandler($responses));

        if ($history !== null) {
            $stack->push(Middleware::history($history));
        }

        return new WordPressMediaUploader(new Client(['handler' => $stack]));
    }
}
