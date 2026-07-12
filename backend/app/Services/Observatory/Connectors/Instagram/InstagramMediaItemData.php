<?php

namespace App\Services\Observatory\Connectors\Instagram;

use DateTimeImmutable;

/**
 * A single Instagram post fetched from the Graph API's /me/media endpoint —
 * Milestone 12 Phase 2 (Instagram Content Intelligence). Intermediate value
 * object used by InstagramMediaFetcher before the data is serialised into a
 * ConnectorResult payload, mirroring InstagramProfileData's role.
 */
readonly class InstagramMediaItemData
{
    /**
     * @param  string[]  $hashtags  extracted from the caption at fetch time
     * @param  string[]  $mentions  extracted from the caption at fetch time
     */
    public function __construct(
        public string $id,
        public ?string $caption,
        public DateTimeImmutable $timestamp,
        public string $mediaType,
        public ?string $permalink,
        public ?int $likeCount,
        public ?int $commentsCount,
        public array $hashtags,
        public array $mentions,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'caption' => $this->caption,
            'timestamp' => $this->timestamp->format('c'),
            'media_type' => $this->mediaType,
            'permalink' => $this->permalink,
            'like_count' => $this->likeCount,
            'comments_count' => $this->commentsCount,
            'hashtags' => $this->hashtags,
            'mentions' => $this->mentions,
        ];
    }

    /** @return string[] */
    public static function extractHashtags(string $caption): array
    {
        preg_match_all('/#(\w+)/u', $caption, $matches);

        return array_values(array_unique($matches[1]));
    }

    /** @return string[] */
    public static function extractMentions(string $caption): array
    {
        preg_match_all('/@(\w+)/u', $caption, $matches);

        return array_values(array_unique($matches[1]));
    }
}
