<?php

namespace App\Services\Publishing;

use App\Models\Channel;
use App\Models\ChannelCredentials;
use App\Models\Company;
use App\Models\MarketingChannel;
use Illuminate\Support\Collection;

class ChannelPublishingCapabilityResolver
{
    public function supportsPublishing(Company|string $company, Channel $channel): bool
    {
        return $this->forChannels($company, collect([$channel]))->get($channel->id, false);
    }

    /**
     * @param  Collection<int, Channel>  $channels
     * @return Collection<string, bool>
     */
    public function forChannels(Company|string $company, Collection $channels): Collection
    {
        $companyId = $company instanceof Company ? $company->id : $company;
        $channelIds = $channels->pluck('id')->all();

        $publishingChannelIds = MarketingChannel::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereIn('channel_id', $channelIds)
            ->where('supports_publishing', true)
            ->pluck('channel_id')
            ->all();

        $hasWordPressCredentials = ChannelCredentials::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('channel_type', 'blog')
            ->where('provider_type', 'wordpress')
            ->where('status', 'active')
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->exists();

        return $channels->mapWithKeys(fn (Channel $channel): array => [
            $channel->id => $channel->company_id === $companyId
                && $channel->is_active
                && (in_array($channel->id, $publishingChannelIds, true)
                    || ($channel->type === 'blog' && $hasWordPressCredentials)),
        ]);
    }

    public function publishingTarget(Company|string $company, Channel $channel): ?string
    {
        if (! $this->supportsPublishing($company, $channel) || $channel->type !== 'blog') {
            return null;
        }

        $siteUrl = $channel->config['site_url'] ?? null;

        return is_string($siteUrl) && $siteUrl !== '' ? rtrim($siteUrl, '/') : null;
    }
}
