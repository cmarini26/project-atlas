<?php

namespace Tests\Feature\Publishing\Meta;

use App\Models\Channel;
use App\Models\Company;
use App\Models\ContentAsset;
use App\Services\Publishing\Exceptions\MalformedPayloadException;
use App\Services\Publishing\MetaRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MetaRendererTest extends TestCase
{
    use RefreshDatabase;

    private MetaRenderer $renderer;

    private Company $company;

    private Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->renderer = new MetaRenderer();

        $this->company = Company::withoutGlobalScopes()->create(['name' => 'CBB Auctions', 'slug' => 'cbb-auctions']);

        $this->channel = Channel::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'type' => 'instagram', 'name' => 'CBB Auctions IG', 'is_active' => true,
        ]);
    }

    private function makeAsset(array $overrides = []): ContentAsset
    {
        return ContentAsset::withoutGlobalScopes()->create(array_merge([
            'company_id' => $this->company->id,
            'campaign_id' => (string) Str::ulid(),
            'channel_id' => $this->channel->id,
            'type' => 'social_post',
            'body' => 'Ending soon: Amazing Fantasy #15.',
            'media' => [['url' => 'https://cdn.example.com/photo.jpg']],
            'status' => 'approved',
        ], $overrides));
    }

    public function test_renders_caption_and_image_url(): void
    {
        $asset = $this->makeAsset();

        $payload = $this->renderer->render($asset, $this->channel);

        $this->assertEquals('instagram', $payload->channelType);
        $this->assertEquals('Ending soon: Amazing Fantasy #15.', $payload->data['caption']);
        $this->assertEquals('https://cdn.example.com/photo.jpg', $payload->data['image_url']);
    }

    public function test_appends_hashtags_to_the_caption(): void
    {
        $asset = $this->makeAsset(['metadata' => ['hashtags' => ['comics', '#auction']]]);

        $payload = $this->renderer->render($asset, $this->channel);

        $this->assertStringContainsString('#comics #auction', $payload->data['caption']);
    }

    public function test_throws_when_no_image_is_present(): void
    {
        $asset = $this->makeAsset(['media' => []]);

        $this->expectException(MalformedPayloadException::class);

        $this->renderer->render($asset, $this->channel);
    }

    public function test_throws_when_media_is_null(): void
    {
        $asset = $this->makeAsset(['media' => null]);

        $this->expectException(MalformedPayloadException::class);

        $this->renderer->render($asset, $this->channel);
    }

    public function test_truncates_captions_over_2200_characters(): void
    {
        $asset = $this->makeAsset(['body' => str_repeat('a', 2300)]);

        $payload = $this->renderer->render($asset, $this->channel);

        $this->assertSame(2200, mb_strlen($payload->data['caption']));
    }

    public function test_supports_instagram_and_facebook_only(): void
    {
        $this->assertTrue($this->renderer->supports('instagram'));
        $this->assertTrue($this->renderer->supports('facebook'));
        $this->assertFalse($this->renderer->supports('email'));
        $this->assertFalse($this->renderer->supports('linkedin'));
    }
}
