<?php

namespace App\Domain\Campaign\ValueObjects;

readonly class CampaignBlueprint
{
    /**
     * @param  string[]  $supportingPoints
     * @param  array<string, mixed>  $tone
     * @param  array<string, mixed>  $successMetrics
     * @param  array<string, mixed>  $channelStrategy
     */
    public function __construct(
        public readonly string $goal,
        public readonly string $audience,
        public readonly string $coreMessage,
        public readonly array $supportingPoints,
        public readonly string $callToAction,
        public readonly ?string $offer,
        public readonly array $tone,
        public readonly ?string $landingPage,
        public readonly array $successMetrics,
        public readonly array $channelStrategy,
        public readonly string $version = '1.0',
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            goal: (string) $data['goal'],
            audience: (string) $data['audience'],
            coreMessage: (string) $data['core_message'],
            supportingPoints: (array) $data['supporting_points'],
            callToAction: (string) $data['call_to_action'],
            offer: isset($data['offer']) ? (string) $data['offer'] : null,
            tone: (array) $data['tone'],
            landingPage: isset($data['landing_page']) ? (string) $data['landing_page'] : null,
            successMetrics: (array) $data['success_metrics'],
            channelStrategy: (array) $data['channel_strategy'],
            version: (string) ($data['version'] ?? '1.0'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'goal' => $this->goal,
            'audience' => $this->audience,
            'core_message' => $this->coreMessage,
            'supporting_points' => $this->supportingPoints,
            'call_to_action' => $this->callToAction,
            'offer' => $this->offer,
            'tone' => $this->tone,
            'landing_page' => $this->landingPage,
            'success_metrics' => $this->successMetrics,
            'channel_strategy' => $this->channelStrategy,
        ];
    }
}
