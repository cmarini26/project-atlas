<?php

namespace App\Services\Observatory\Connectors\Instagram;

use DateTimeImmutable;

/**
 * A single Instagram account profile snapshot fetched from the Graph API.
 * Intermediate value object used by InstagramProfileFetcher before the data
 * is serialised into a ConnectorResult payload — mirrors WebPageData's role
 * for the website connector.
 */
readonly class InstagramProfileData
{
    public function __construct(
        public string $accountId,
        public string $username,
        public ?string $displayName,
        public ?string $profilePictureUrl,
        public ?string $bio,
        public ?string $website,
        public ?int $followerCount,
        public ?int $followingCount,
        public DateTimeImmutable $fetchedAt,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'account_id' => $this->accountId,
            'username' => $this->username,
            'display_name' => $this->displayName,
            'profile_picture_url' => $this->profilePictureUrl,
            'bio' => $this->bio,
            'website' => $this->website,
            'follower_count' => $this->followerCount,
            'following_count' => $this->followingCount,
            'fetched_at' => $this->fetchedAt->format('c'),
        ];
    }
}
