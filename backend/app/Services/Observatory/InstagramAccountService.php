<?php

namespace App\Services\Observatory;

use App\Models\InstagramAccount;
use App\Models\Integration;

/**
 * Keeps the typed InstagramAccount snapshot (Milestone 12 Phase 1) in sync
 * with each Instagram Observation's profile payload. Called from
 * InstagramAnalyst alongside Fact extraction — not from InstagramConnector,
 * which (like WebsiteConnector) only fetches and maps data; it never
 * persists anything itself.
 */
class InstagramAccountService
{
    /** @param array<string, mixed> $profile */
    public function syncSnapshot(Integration $integration, array $profile): InstagramAccount
    {
        return InstagramAccount::withoutGlobalScopes()->updateOrCreate(
            ['integration_id' => $integration->id],
            [
                'company_id' => $integration->company_id,
                'account_id' => (string) ($profile['account_id'] ?? ''),
                'username' => (string) ($profile['username'] ?? ''),
                'display_name' => $profile['display_name'] ?? null,
                'profile_picture_url' => $profile['profile_picture_url'] ?? null,
                'bio' => $profile['bio'] ?? null,
                'website' => $profile['website'] ?? null,
                'follower_count' => $profile['follower_count'] ?? null,
                'following_count' => $profile['following_count'] ?? null,
                'last_synced_at' => now(),
            ],
        );
    }
}
