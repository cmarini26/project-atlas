<?php

namespace App\Services\Publishing;

use App\Domain\Publishing\ValueObjects\ExecutionResult;
use App\Domain\Publishing\ValueObjects\PingResult;
use App\Models\ChannelCredentials;
use App\Models\ContentAsset;
use App\Models\Execution;
use App\Services\Publishing\Contracts\ChannelPublisher;
use DateTimeImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class LogChannelPublisher implements ChannelPublisher
{
    public function publish(Execution $execution): ExecutionResult
    {
        $asset = ContentAsset::withoutGlobalScopes()->findOrFail($execution->content_asset_id);

        Log::channel('publishing')->info('LogChannelPublisher: simulating publish', [
            'execution_id' => $execution->id,
            'idempotency_key' => $execution->idempotency_key,
            'company_id' => $execution->company_id,
            'campaign_id' => $execution->campaign_id,
            'channel_id' => $execution->channel_id,
            'asset_type' => $asset->type,
            'body_preview' => mb_substr((string) $asset->body, 0, 120),
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
