<?php

namespace Tests\Feature\Publishing\WordPress;

use App\Models\Channel;
use App\Models\Company;
use App\Models\ContentAsset;
use App\Services\Publishing\Exceptions\MalformedPayloadException;
use App\Services\Publishing\WordPressRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class WordPressRendererTest extends TestCase
{
    use RefreshDatabase;

    private WordPressRenderer $renderer;

    private Company $company;

    private Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->renderer = new WordPressRenderer();

        $this->company = Company::withoutGlobalScopes()->create(['name' => 'CBB Auctions', 'slug' => 'cbb-auctions']);

        $this->channel = Channel::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'type' => 'blog', 'name' => 'CBB Blog', 'is_active' => true,
        ]);
    }

    private function makeAsset(array $overrides = []): ContentAsset
    {
        return ContentAsset::withoutGlobalScopes()->create(array_merge([
            'company_id' => $this->company->id,
            'campaign_id' => (string) Str::ulid(),
            'channel_id' => $this->channel->id,
            'type' => 'blog_post',
            'title' => 'Why Silver Age Comics Are Booming',
            'body' => "First paragraph.\n\nSecond paragraph.",
            'media' => [['url' => 'https://cdn.example.com/photo.jpg']],
            'status' => 'approved',
        ], $overrides));
    }

    public function test_renders_title_and_html_paragraphs(): void
    {
        $asset = $this->makeAsset();

        $payload = $this->renderer->render($asset, $this->channel);

        $this->assertSame('blog', $payload->channelType);
        $this->assertSame('Why Silver Age Comics Are Booming', $payload->data['title']);
        $this->assertSame('<p>First paragraph.</p><p>Second paragraph.</p>', $payload->data['content']);
    }

    public function test_escapes_html_in_body(): void
    {
        $asset = $this->makeAsset(['body' => 'Use <script>alert(1)</script> carefully.']);

        $payload = $this->renderer->render($asset, $this->channel);

        $this->assertStringNotContainsString('<script>', $payload->data['content']);
        $this->assertStringContainsString('&lt;script&gt;', $payload->data['content']);
    }

    public function test_passes_through_the_first_media_url(): void
    {
        $asset = $this->makeAsset();

        $payload = $this->renderer->render($asset, $this->channel);

        $this->assertSame('https://cdn.example.com/photo.jpg', $payload->data['image_url']);
    }

    public function test_image_url_is_null_without_media(): void
    {
        $asset = $this->makeAsset(['media' => null]);

        $payload = $this->renderer->render($asset, $this->channel);

        $this->assertNull($payload->data['image_url']);
    }

    public function test_throws_when_title_is_missing(): void
    {
        $asset = $this->makeAsset(['title' => null]);

        $this->expectException(MalformedPayloadException::class);

        $this->renderer->render($asset, $this->channel);
    }

    public function test_supports_blog_only(): void
    {
        $this->assertTrue($this->renderer->supports('blog'));
        $this->assertFalse($this->renderer->supports('email'));
        $this->assertFalse($this->renderer->supports('instagram'));
    }
}
