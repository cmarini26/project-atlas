/**
 * What each channel type can actually do today, versus what the UI used to
 * imply ("Publish", "Published", raw enum values like "instagram"). See
 * docs/reviews/Channel-Publishing-Reality-Audit.md for the full audit this
 * was built from, and its 2026-07-15 addendum for what changed since.
 *
 * `blog` (WordPress) and `facebook`/`instagram` (Meta) each have a real
 * connect flow (SettingsController::connectWordPress(), MetaOAuthController)
 * and a real publisher (WordPressPublisher, MetaChannelPublisher) — these
 * are genuinely live once a company connects them, not simulated. `email`
 * has a real PostmarkEmailProvider implemented, but no product UX exists
 * yet to connect it for a real company (only DemoSeeder sets provider_type
 * 'postmark'), so it stays 'draft_only' below.
 *
 * This map is a *global fallback* used only when no company-specific link
 * data is available (see resolveChannelCapability()). `facebook`/`instagram`
 * are per-company-overridable — a linked MarketingChannel's
 * supports_publishing flag (kept in sync with real connect/health state by
 * MetaOAuthController and CheckChannelHealth) always wins over this default.
 * `blog` is NOT overridable this way today: WordPress has no
 * MarketingChannelType equivalent (App\Enums\MarketingChannelType has no
 * Blog/WordPress case), so there is no per-company link path for it, and
 * `blog` here is deliberately left at the conservative 'draft_only' default
 * even though a specific company's WordPress connection may in fact be
 * live — see Settings.vue's own `wordpress_channel.status`, which is the
 * accurate per-company source of truth Publishing.vue/Dashboard.vue/
 * Campaigns/Show.vue don't yet surface (follow-up, not fixed here).
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
 * - not_configured: a real connect flow and publisher exist for this type,
 *   but this fallback path has no company-specific data to say whether
 *   *this* company has actually connected it (see resolveChannelCapability).
 * - coming_later: no code path lets a company create a channel of this type
 *   yet, even though content-drafting support already exists for it.
 */
export const CHANNEL_CAPABILITY: Record<string, ChannelCapability> = {
  blog: 'draft_only',
  email: 'draft_only',
  facebook: 'not_configured',
  instagram: 'not_configured',
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
