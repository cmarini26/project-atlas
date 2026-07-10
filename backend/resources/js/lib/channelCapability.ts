/**
 * What each channel type can actually do today, versus what the UI used to
 * imply ("Publish", "Published", raw enum values like "instagram"). See
 * docs/reviews/Channel-Publishing-Reality-Audit.md for the full audit this
 * was built from.
 *
 * No channel type currently sends to a real external platform — every
 * "publish" is logged internally (App\Services\Publishing\LogChannelPublisher
 * / LogEmailProvider). 'connected' and 'not_configured' are included so a
 * future real integration (most likely email) slots in by changing one
 * entry in CHANNEL_CAPABILITY, without further UI work.
 */

export type ChannelCapability = 'connected' | 'draft_only' | 'coming_later' | 'not_configured'

export const CHANNEL_TYPE_LABELS: Record<string, string> = {
  blog: 'Blog',
  email: 'Email',
  facebook: 'Facebook',
  instagram: 'Instagram',
  linkedin: 'LinkedIn',
  x: 'X',
  sms: 'SMS',
  landing_page: 'Landing Page',
}

/**
 * - draft_only: content is drafted and the internal pipeline runs to
 *   completion, but delivery is simulated/logged, not sent to a live channel.
 * - coming_later: no code path lets a company create a channel of this type
 *   yet, even though content-drafting support already exists for it.
 */
export const CHANNEL_CAPABILITY: Record<string, ChannelCapability> = {
  blog: 'draft_only',
  email: 'draft_only',
  facebook: 'coming_later',
  instagram: 'coming_later',
  linkedin: 'coming_later',
  x: 'coming_later',
  sms: 'coming_later',
  landing_page: 'coming_later',
}

export const CAPABILITY_LABELS: Record<ChannelCapability, string> = {
  connected: 'Connected',
  draft_only: 'Draft only',
  coming_later: 'Coming later',
  not_configured: 'Not configured',
}

export const CAPABILITY_DESCRIPTIONS: Record<ChannelCapability, string> = {
  connected: 'Live — content is sent to a real external channel.',
  draft_only: "Atlas drafts and queues content, but doesn't yet send it to a live external channel.",
  coming_later: "Not yet available — Atlas can't create or publish to this channel type yet.",
  not_configured: 'Supported, but not yet connected for this company.',
}

export function channelLabel(channelType: string): string {
  return CHANNEL_TYPE_LABELS[channelType] ?? channelType
}

export function channelCapability(channelType: string): ChannelCapability {
  return CHANNEL_CAPABILITY[channelType] ?? 'coming_later'
}

/**
 * A MarketingChannel linked to the real Channel being displayed, if one
 * exists — see specs/core/marketing-presence.md §11. Only the two facts
 * this table needs.
 */
export interface LinkedMarketingChannel {
  supportsPublishing: boolean
}

/**
 * Resolves capability for a real, technical Channel — spec §11's first two
 * table rows. A linked MarketingChannel's `supports_publishing` flag always
 * wins over the global type-only guess, since it is company-specific truth;
 * absent a link, the existing global lookup remains the correct fallback
 * (Milestone 11 Phase 7 — this is a refinement, not a replacement).
 */
export function resolveChannelCapability(
  channelType: string,
  linkedMarketingChannel?: LinkedMarketingChannel | null,
): ChannelCapability {
  if (linkedMarketingChannel) {
    return linkedMarketingChannel.supportsPublishing ? 'connected' : 'draft_only'
  }

  return channelCapability(channelType)
}

/**
 * MarketingChannelType values with a corresponding Channel type today —
 * mirrors App\Enums\MarketingChannelType::hasChannelEquivalent() (email,
 * instagram, facebook, linkedin, x). Kept in sync manually, the same way
 * lib/marketingChannelTypes.ts already mirrors that enum's full value set.
 */
const MARKETING_TYPES_WITH_CHANNEL_EQUIVALENT = ['email', 'instagram', 'facebook', 'linkedin', 'x']

/**
 * Resolves capability for a declared MarketingChannel with no linked Channel
 * at all — spec §11's last two table rows ("Not configured" vs. "Coming
 * later"), which turn on whether the declared type could ever map to a real
 * Channel, not on any company-specific data.
 */
export function resolveDeclaredChannelCapability(marketingChannelType: string): ChannelCapability {
  return MARKETING_TYPES_WITH_CHANNEL_EQUIVALENT.includes(marketingChannelType) ? 'not_configured' : 'coming_later'
}
