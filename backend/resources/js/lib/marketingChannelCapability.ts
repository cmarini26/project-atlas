/**
 * Presentation-only label/description lookup for the domain-level capability
 * result App\Services\MarketingPresence\MarketingChannelCapabilityResolver
 * already computed server-side (see specs/core/marketing-presence.md §5).
 * This file never derives a capability from raw flags — it only maps the
 * resolver's output value to copy, mirroring how lib/channelCapability.ts
 * maps the (separate) Channel Publishing Reality Audit vocabulary.
 */

export type MarketingChannelCapability = 'declared' | 'connected' | 'publishing_enabled' | 'analytics_enabled'

export const MARKETING_CHANNEL_CAPABILITY_LABELS: Record<MarketingChannelCapability, string> = {
  declared: 'Declared',
  connected: 'Connected',
  publishing_enabled: 'Publishing enabled',
  analytics_enabled: 'Analytics enabled',
}

export const MARKETING_CHANNEL_CAPABILITY_DESCRIPTIONS: Record<MarketingChannelCapability, string> = {
  declared: "Atlas knows this channel exists. It isn't connected, and Atlas can't publish or read analytics here yet.",
  connected: "Linked to a real channel record, but Atlas still can't publish or read analytics here yet.",
  publishing_enabled: 'Atlas can publish here today.',
  analytics_enabled: 'Atlas can publish here and read real performance data.',
}
