<?php

namespace App\Services\Publishing;

use App\Domain\Publishing\ValueObjects\ExecutionResult;
use App\Domain\Publishing\ValueObjects\PingResult;
use App\Models\Channel;
use App\Models\ChannelCredentials;
use App\Models\ContentAsset;
use App\Models\Execution;
use App\Services\Publishing\Contracts\ChannelPublisher;
use DateTimeImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class LogChannelPublisher implements ChannelPublisher
{
    public function __construct(
        private readonly ChannelRendererRegistry $renderers,
    ) {}

    public function publish(Execution $execution): ExecutionResult
    {
        $asset = ContentAsset::withoutGlobalScopes()->findOrFail($execution->content_asset_id);
        $channel = Channel::withoutGlobalScopes()->findOrFail($execution->channel_id);

        $payload = $this->renderers->for($channel->type)->render($asset, $channel);

        Log::channel('publishing')->info('LogChannelPublisher: simulating publish', [
            'execution_id' => $execution->id,
            'idempotency_key' => $execution->idempotency_key,
            'company_id' => $execution->company_id,
            'campaign_id' => $execution->campaign_id,
            'channel_type' => $payload->channelType,
            'body_preview' => mb_substr((string) ($payload->data['body'] ?? ''), 0, 120),
        ]);

        return new ExecutionResult(
            platformId: 'log-'.Str::ulid()->toString(),
            url: null,
            publishedAt: new DateTimeImmutable(),
            metadata: ['publisher' => 'log'],
        );
    }

    public function supports(string $channelType): bool
    {
        return in_array($channelType, [
            'facebook', 'instagram', 'linkedin', 'x',
            'email', 'sms', 'blog', 'landing_page',
        ]);
    }

    public function ping(ChannelCredentials $credentials): PingResult
    {
        return new PingResult(reachable: true, error: null);
    }
}
