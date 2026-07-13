<?php

namespace App\Services\Onboarding;

use App\Enums\MarketingChannelImportance;
use App\Enums\MarketingChannelType;
use App\Models\Company;
use App\Models\MarketingChannel;
use App\Services\MarketingPresence\MarketingPresenceService;
use Illuminate\Support\Collection;

/**
 * Persists onboarding's Marketing Assets (step 4) and Asset Details (step
 * 5) selections — Milestone 15 Phase 1. Reuses the existing
 * MarketingPresenceService/MarketingChannel domain (Milestone 11)
 * unchanged rather than inventing parallel storage — a declared onboarding
 * asset IS a declared MarketingChannel, nothing more.
 */
class OnboardingAssetService
{
    public function __construct(private readonly MarketingPresenceService $marketingPresence) {}

    /**
     * Reconciles a company's declared MarketingChannel rows against the
     * wizard's current enabled/primary selections — idempotent against
     * resubmits (going back to step 4 and changing a selection).
     *
     * @param  list<string>  $enabledTypes
     * @param  list<string>  $primaryTypes  subset of $enabledTypes, max 3 — enforced by the caller's request validation
     * @return Collection<int, MarketingChannel>
     */
    public function syncEnabledAssets(Company $company, array $enabledTypes, array $primaryTypes): Collection
    {
        $existing = MarketingChannel::where('company_id', $company->id)->get()
            ->keyBy(fn (MarketingChannel $channel): string => $channel->type->value);

        foreach ($existing as $type => $channel) {
            if (! in_array($type, $enabledTypes, true)) {
                $channel->delete();
            }
        }

        $result = collect();

        foreach ($enabledTypes as $type) {
            $importance = in_array($type, $primaryTypes, true)
                ? MarketingChannelImportance::Primary->value
                : MarketingChannelImportance::Secondary->value;

            $channel = $existing->get($type);

            $channel = $channel === null
                ? $this->marketingPresence->declare($company, [
                    'type' => $type,
                    'display_name' => MarketingChannelType::from($type)->label(),
                    'importance' => $importance,
                ])
                : $this->marketingPresence->update($channel, ['importance' => $importance]);

            $result->push($channel);
        }

        return $result;
    }

    /**
     * @param  array<string, array<string, mixed>>  $detailsByType  keyed by MarketingChannelType value
     */
    public function saveAssetDetails(Company $company, array $detailsByType): void
    {
        $channels = MarketingChannel::where('company_id', $company->id)->get()
            ->keyBy(fn (MarketingChannel $channel): string => $channel->type->value);

        foreach ($detailsByType as $type => $details) {
            $channel = $channels->get($type);

            // Not a declared asset (stale form data, a type the user
            // disabled since) — silently ignored rather than erroring.
            if ($channel === null) {
                continue;
            }

            [$handleOrUrl, $metadata] = $this->mapDetails($type, $details);

            $this->marketingPresence->update($channel, [
                'handle_or_url' => $handleOrUrl,
                'metadata' => $metadata,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array{0: string|null, 1: array<string, mixed>}
     */
    private function mapDetails(string $type, array $details): array
    {
        return match ($type) {
            'website' => [
                isset($details['url']) ? (string) $details['url'] : null,
                ['platform' => $details['platform'] ?? null],
            ],
            'instagram', 'facebook', 'linkedin', 'youtube', 'x' => [
                isset($details['url']) ? (string) $details['url'] : null,
                [],
            ],
            'google_business_profile' => [
                isset($details['business_name_or_url']) ? (string) $details['business_name_or_url'] : null,
                [],
            ],
            'email' => [
                null,
                ['provider' => $details['provider'] ?? null, 'signup_url' => $details['signup_url'] ?? null],
            ],
            'events', 'print' => [
                null,
                ['description' => $details['description'] ?? null],
            ],
            default => [null, []],
        };
    }
}
