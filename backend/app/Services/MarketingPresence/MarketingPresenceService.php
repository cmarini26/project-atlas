<?php

namespace App\Services\MarketingPresence;

use App\Enums\MarketingChannelImportance;
use App\Enums\MarketingChannelObjective;
use App\Enums\MarketingChannelStatus;
use App\Enums\MarketingChannelType;
use App\Enums\PostingFrequency;
use App\Events\MarketingPresenceUpdated;
use App\Models\Channel;
use App\Models\Company;
use App\Models\Integration;
use App\Models\MarketingChannel;
use App\Services\MarketingPresence\Exceptions\ChannelBelongsToDifferentCompanyException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;

/**
 * CRUD and reasoning over a company's declared marketing channels. See
 * specs/core/marketing-presence.md and the Phase 2 section of
 * docs/plans/Milestone-11-Marketing-Presence.md.
 *
 * Every method here trusts the $company/$channel instance it's given for
 * tenant scoping — the same pattern used by ApprovalService and
 * RecommendationService elsewhere in this codebase — and additionally
 * never trusts a caller-supplied company_id/channel_id inside an
 * attributes array (Section "Validation" below).
 */
class MarketingPresenceService
{
    /**
     * Structural fields a generic update() may touch. Deliberately excludes
     * company_id, channel_id, is_connected, supports_publishing, and
     * supports_analytics — those are lifecycle/linkage concerns owned by
     * link() (and, in a future milestone, a real publisher-connection flow),
     * not business-context edits.
     *
     * @var list<string>
     */
    private const array STRUCTURAL_FIELDS = [
        'type', 'display_name', 'handle_or_url', 'status', 'importance',
        'objective', 'audience', 'posting_frequency', 'notes',
    ];

    /**
     * Declare a new marketing channel for a company. Requires no API
     * connection, no credentials, and no linked Channel — see
     * specs/core/marketing-presence.md §12.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function declare(Company $company, array $attributes): MarketingChannel
    {
        $type = $attributes['type'] ?? null;
        $typeEnum = $type instanceof MarketingChannelType ? $type : MarketingChannelType::tryFrom((string) $type);

        // An unresolvable type is left as-is (rather than thrown here) so
        // that validate() below reports it as a normal ValidationException —
        // the same failure mode as any other invalid attribute.
        $defaults = $typeEnum !== null ? $this->suggestedDefaults($typeEnum) : [];

        // Caller-supplied attributes always win over the type's suggested
        // defaults, which in turn only fill in what a bare declare() omits.
        $merged = array_merge(
            ['status' => MarketingChannelStatus::Active->value],
            $defaults,
            $attributes,
        );

        // company_id is never trusted from the caller — tenant isolation is
        // enforced here, not left to the caller to get right.
        $merged['company_id'] = $company->id;
        // declare() never links to a real Channel — link() is the only path,
        // and is explicitly set to false (not unset) so the in-memory model
        // returned below is correct without needing a database round-trip.
        unset($merged['channel_id']);
        $merged['is_connected'] = false;
        $merged['supports_publishing'] = false;
        $merged['supports_analytics'] = false;

        $this->validate($merged);

        $marketingChannel = MarketingChannel::create($merged);

        MarketingPresenceUpdated::dispatch($marketingChannel);

        return $marketingChannel;
    }

    /**
     * Partially update a declared channel's business-context fields. Never
     * touches company_id, channel_id, or the three capability booleans —
     * see STRUCTURAL_FIELDS and link().
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(MarketingChannel $channel, array $attributes): MarketingChannel
    {
        unset(
            $attributes['company_id'],
            $attributes['channel_id'],
            $attributes['is_connected'],
            $attributes['supports_publishing'],
            $attributes['supports_analytics'],
        );

        // Validate the prospective FULL state (current fields overlaid with
        // the caller's partial changes), not just the changed subset — a
        // partial update that empties a required field must still fail.
        $merged = array_merge($channel->only(self::STRUCTURAL_FIELDS), $attributes);
        $this->validate($merged);

        $channel->fill($attributes)->save();

        MarketingPresenceUpdated::dispatch($channel->refresh());

        return $channel;
    }

    public function setStatus(MarketingChannel $channel, MarketingChannelStatus|string $status): MarketingChannel
    {
        $statusEnum = $status instanceof MarketingChannelStatus ? $status : MarketingChannelStatus::from($status);

        $channel->update(['status' => $statusEnum]);

        MarketingPresenceUpdated::dispatch($channel->refresh());

        return $channel;
    }

    /** Convenience wrapper: a business has stopped using this channel. */
    public function disable(MarketingChannel $channel): MarketingChannel
    {
        return $this->setStatus($channel, MarketingChannelStatus::Inactive);
    }

    /** Convenience wrapper: a previously inactive/planned channel is active again. */
    public function reactivate(MarketingChannel $channel): MarketingChannel
    {
        return $this->setStatus($channel, MarketingChannelStatus::Active);
    }

    /**
     * Link a declared channel to a real, technical Channel — the mechanism
     * described in specs/core/marketing-presence.md §12 for upgrading a
     * declaration without redesign. Sets channel_id and is_connected only;
     * supports_publishing/supports_analytics remain false here — those are
     * independent, later upgrades (Phase 6/12), not implied by linking.
     *
     * @throws ChannelBelongsToDifferentCompanyException
     */
    public function link(MarketingChannel $channel, Channel $realChannel): MarketingChannel
    {
        if ($realChannel->company_id !== $channel->company_id) {
            throw new ChannelBelongsToDifferentCompanyException(sprintf(
                'Channel %s belongs to company %s, but MarketingChannel %s belongs to company %s.',
                $realChannel->id,
                $realChannel->company_id ?? 'null (global template)',
                $channel->id,
                $channel->company_id,
            ));
        }

        $channel->update([
            'channel_id' => $realChannel->id,
            'is_connected' => true,
        ]);

        MarketingPresenceUpdated::dispatch($channel->refresh());

        return $channel;
    }

    /**
     * Sensible onboarding defaults per channel type, so a bare declare()
     * (or a future onboarding multi-select) isn't followed by empty forms.
     * Always overridable by explicit caller-supplied attributes.
     *
     * @return array{importance: string, objective: list<string>, posting_frequency: string}
     */
    public function suggestedDefaults(MarketingChannelType|string $type): array
    {
        $typeEnum = $type instanceof MarketingChannelType ? $type : MarketingChannelType::from($type);

        return match ($typeEnum) {
            MarketingChannelType::Website => [
                'importance' => MarketingChannelImportance::Secondary->value,
                'objective' => [MarketingChannelObjective::Seo->value, MarketingChannelObjective::Trust->value],
                'posting_frequency' => PostingFrequency::Unknown->value,
            ],
            MarketingChannelType::Email => [
                'importance' => MarketingChannelImportance::Primary->value,
                'objective' => [MarketingChannelObjective::Retention->value, MarketingChannelObjective::Sales->value],
                'posting_frequency' => PostingFrequency::Monthly->value,
            ],
            MarketingChannelType::Instagram => [
                'importance' => MarketingChannelImportance::Secondary->value,
                'objective' => [MarketingChannelObjective::Awareness->value, MarketingChannelObjective::Community->value],
                'posting_frequency' => PostingFrequency::Weekly->value,
            ],
            MarketingChannelType::Facebook => [
                'importance' => MarketingChannelImportance::Secondary->value,
                'objective' => [MarketingChannelObjective::Awareness->value, MarketingChannelObjective::Community->value],
                'posting_frequency' => PostingFrequency::Weekly->value,
            ],
            MarketingChannelType::LinkedIn => [
                'importance' => MarketingChannelImportance::Secondary->value,
                'objective' => [MarketingChannelObjective::Trust->value, MarketingChannelObjective::Leads->value],
                'posting_frequency' => PostingFrequency::Weekly->value,
            ],
            MarketingChannelType::X => [
                'importance' => MarketingChannelImportance::Experimental->value,
                'objective' => [MarketingChannelObjective::Awareness->value],
                'posting_frequency' => PostingFrequency::Weekly->value,
            ],
            MarketingChannelType::YouTube => [
                'importance' => MarketingChannelImportance::Experimental->value,
                'objective' => [MarketingChannelObjective::Awareness->value, MarketingChannelObjective::Trust->value],
                'posting_frequency' => PostingFrequency::Monthly->value,
            ],
            MarketingChannelType::TikTok => [
                'importance' => MarketingChannelImportance::Experimental->value,
                'objective' => [MarketingChannelObjective::Awareness->value, MarketingChannelObjective::Community->value],
                'posting_frequency' => PostingFrequency::Weekly->value,
            ],
            MarketingChannelType::GoogleBusinessProfile => [
                'importance' => MarketingChannelImportance::Secondary->value,
                'objective' => [MarketingChannelObjective::Trust->value, MarketingChannelObjective::Seo->value, MarketingChannelObjective::Leads->value],
                'posting_frequency' => PostingFrequency::Monthly->value,
            ],
            MarketingChannelType::Events => [
                'importance' => MarketingChannelImportance::Secondary->value,
                'objective' => [MarketingChannelObjective::Awareness->value, MarketingChannelObjective::Trust->value],
                'posting_frequency' => PostingFrequency::Rarely->value,
            ],
            MarketingChannelType::Print => [
                'importance' => MarketingChannelImportance::Secondary->value,
                'objective' => [MarketingChannelObjective::Awareness->value],
                'posting_frequency' => PostingFrequency::Quarterly->value,
            ],
            MarketingChannelType::Other => [
                'importance' => MarketingChannelImportance::Secondary->value,
                'objective' => [MarketingChannelObjective::Awareness->value],
                'posting_frequency' => PostingFrequency::Unknown->value,
            ],
        };
    }

    /**
     * Soft duplicate check per specs/core/marketing-presence.md §2: warn,
     * never block. Declaring two Instagram accounts or two Print placements
     * is legitimate; this only flags an identical (company, type,
     * handle_or_url) combination for a caller to warn the user about.
     *
     * @param  string|null  $excludingId  pass the channel's own id when checking during update()
     */
    public function wouldDuplicate(
        Company $company,
        MarketingChannelType|string $type,
        ?string $handleOrUrl,
        ?string $excludingId = null,
    ): bool {
        if ($handleOrUrl === null || $handleOrUrl === '') {
            return false;
        }

        $typeValue = $type instanceof MarketingChannelType ? $type->value : $type;

        return MarketingChannel::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('type', $typeValue)
            ->where('handle_or_url', $handleOrUrl)
            ->when($excludingId !== null, fn ($query) => $query->whereKeyNot($excludingId))
            ->exists();
    }

    /**
     * Read-only candidates the company could declare, based on what Atlas
     * already knows — a connected website Integration, and any existing
     * Channel rows. Never persists anything (per the Phase 2 plan: "Do not
     * automatically create suggestions yet"). Future extensibility: a later
     * phase could add suggestions derived from Facts/Knowledge (e.g. a
     * detected social handle mentioned on the crawled website) without
     * changing this method's signature or return shape.
     *
     * @return Collection<int, MarketingChannelSuggestion>
     */
    public function suggestChannels(Company $company): Collection
    {
        return $this->suggestionsFromWebsiteIntegration($company)
            ->merge($this->suggestionsFromExistingChannels($company))
            ->values();
    }

    /** @return Collection<int, MarketingChannelSuggestion> */
    private function suggestionsFromWebsiteIntegration(Company $company): Collection
    {
        if ($this->alreadyDeclared($company, MarketingChannelType::Website)) {
            return collect();
        }

        $integration = Integration::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('type', 'website_crawl')
            ->first();

        if ($integration === null) {
            return collect();
        }

        return collect([
            new MarketingChannelSuggestion(
                type: MarketingChannelType::Website,
                displayName: $company->name.' Website',
                handleOrUrl: $integration->config['url'] ?? null,
                reason: 'A website integration is already connected for observation.',
                channelId: null,
            ),
        ]);
    }

    /** @return Collection<int, MarketingChannelSuggestion> */
    private function suggestionsFromExistingChannels(Company $company): Collection
    {
        return Channel::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('is_active', true)
            ->get()
            ->reject(fn (Channel $channel): bool => MarketingChannelType::tryFrom($channel->type) === null)
            ->reject(fn (Channel $channel): bool => $this->alreadyLinkedTo($company, $channel))
            ->map(fn (Channel $channel): MarketingChannelSuggestion => new MarketingChannelSuggestion(
                type: MarketingChannelType::from($channel->type),
                displayName: $channel->name,
                handleOrUrl: null,
                reason: "Atlas already has a connected {$channel->type} channel for publishing.",
                channelId: $channel->id,
            ))
            ->values();
    }

    private function alreadyDeclared(Company $company, MarketingChannelType $type): bool
    {
        return MarketingChannel::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('type', $type->value)
            ->exists();
    }

    private function alreadyLinkedTo(Company $company, Channel $channel): bool
    {
        return MarketingChannel::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('channel_id', $channel->id)
            ->exists();
    }

    /**
     * Structural validation gate — throws Illuminate\Validation\ValidationException
     * on failure. Does not return the validated subset (Laravel's validate()
     * drops any key with no rule, which would silently lose company_id,
     * channel_id, metadata, etc.) — callers pass the full attribute array
     * to create()/fill() themselves once this gate passes.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function validate(array $attributes): void
    {
        Validator::make($attributes, MarketingChannel::rules())->validate();
    }
}
