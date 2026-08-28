/**
 * The 10 Marketing Assets cards shown in onboarding's Marketing Assets step,
 * and the identifying-detail fields collected for each in the following
 * Asset Details step. Deliberately a curated subset of the full 12
 * MarketingChannelType values (excludes TikTok, Other) — mirrors
 * App\Domain\Onboarding\OnboardingAssetTypes on the PHP side (a parity test
 * guards drift). See docs/specs/Business-Discovery-Onboarding.md §2.4-2.5.
 *
 * Only Website requires details up front (2026-07-14, Workstream C.1) —
 * that's the only type Discovery can currently auto-discover from a URL
 * alone; every other type is declared now and detailed later from
 * /app/settings/marketing-presence, so onboarding doesn't front-load
 * fields Discovery won't use yet.
 */

/**
 * What setup a channel still needs *after* onboarding — orthogonal to
 * `requiresDetails` (which means "collect a URL inline now"):
 *   - none    nothing further (Website — done inline)
 *   - handle  enter a username / profile URL in Marketing Presence
 *   - oauth   connect an account in Marketing Presence
 *   - manual  offline channel — tracked, never integrated
 * Mirrors App\Domain\Onboarding\OnboardingAssetTypes; a parity test guards drift.
 */
export type IntegrationRequirementKind = 'none' | 'handle' | 'oauth' | 'manual'

export interface IntegrationRequirement {
  kind: IntegrationRequirementKind
  /** One line describing what is still needed. */
  summary: string
}

export interface OnboardingAssetType {
  type: string
  label: string
  /** Types with no identifying field require nothing further in step 5. */
  requiresDetails: boolean
  integrationRequirement: IntegrationRequirement
}

/** Whether the user still has to connect this channel from Settings. */
export function needsChannelConnection(asset: OnboardingAssetType): boolean {
  return asset.integrationRequirement.kind === 'handle' || asset.integrationRequirement.kind === 'oauth'
}

export const ONBOARDING_ASSET_TYPES: OnboardingAssetType[] = [
  { type: 'website', label: 'Website', requiresDetails: true, integrationRequirement: { kind: 'none', summary: 'Set up during onboarding — nothing more to do.' } },
  { type: 'google_business_profile', label: 'Google Business Profile', requiresDetails: false, integrationRequirement: { kind: 'handle', summary: 'Add your Google Business Profile URL in Marketing Presence.' } },
  { type: 'instagram', label: 'Instagram', requiresDetails: false, integrationRequirement: { kind: 'oauth', summary: 'Connect your Instagram account in Marketing Presence.' } },
  { type: 'facebook', label: 'Facebook', requiresDetails: false, integrationRequirement: { kind: 'oauth', summary: 'Connect your Facebook Page in Marketing Presence.' } },
  { type: 'linkedin', label: 'LinkedIn', requiresDetails: false, integrationRequirement: { kind: 'handle', summary: 'Add your LinkedIn page URL in Marketing Presence.' } },
  { type: 'x', label: 'X', requiresDetails: false, integrationRequirement: { kind: 'handle', summary: 'Add your X handle in Marketing Presence.' } },
  { type: 'youtube', label: 'YouTube', requiresDetails: false, integrationRequirement: { kind: 'handle', summary: 'Add your YouTube channel URL in Marketing Presence.' } },
  { type: 'email', label: 'Email Newsletter', requiresDetails: false, integrationRequirement: { kind: 'oauth', summary: 'Connect your email sending provider in Marketing Presence.' } },
  { type: 'events', label: 'Events', requiresDetails: false, integrationRequirement: { kind: 'manual', summary: 'Tracked as an offline channel — no connection needed.' } },
  { type: 'print', label: 'Print', requiresDetails: false, integrationRequirement: { kind: 'manual', summary: 'Tracked as an offline channel — no connection needed.' } },
]

export const WEBSITE_PLATFORM_OPTIONS: { value: string; label: string }[] = [
  { value: 'wordpress', label: 'WordPress' },
  { value: 'squarespace', label: 'Squarespace' },
  { value: 'shopify', label: 'Shopify' },
  { value: 'wix', label: 'Wix' },
  { value: 'webflow', label: 'Webflow' },
  { value: 'custom', label: 'Custom' },
  { value: 'other', label: 'Other' },
  { value: 'unknown', label: "I don't know" },
]

export const BUSINESS_GOAL_OPTIONS: { value: string; label: string }[] = [
  { value: 'generate_leads', label: 'Generate leads' },
  { value: 'increase_sales', label: 'Increase sales' },
  { value: 'promote_events', label: 'Promote events' },
  { value: 'increase_awareness', label: 'Increase awareness' },
  { value: 'increase_website_traffic', label: 'Increase website traffic' },
  { value: 'improve_seo', label: 'Improve SEO' },
  { value: 'grow_social_media', label: 'Grow social media' },
  { value: 'other', label: 'Other' },
]

export const MARKETING_FREQUENCY_OPTIONS: { value: string; label: string }[] = [
  { value: 'daily', label: 'Daily' },
  { value: 'weekly', label: 'Weekly' },
  { value: 'monthly', label: 'Monthly' },
  { value: 'promotions_only', label: 'Promotions only' },
  { value: 'rarely', label: 'Rarely' },
]

export const MARKETING_OWNER_OPTIONS: { value: string; label: string }[] = [
  { value: 'me', label: 'Me' },
  { value: 'team', label: 'Someone on my team' },
  { value: 'agency', label: 'Marketing agency' },
  { value: 'freelancer', label: 'Freelancer' },
  { value: 'nobody', label: 'Nobody consistently' },
]

export const PRIMARY_CTA_OPTIONS: { value: string; label: string }[] = [
  { value: 'call', label: 'Call' },
  { value: 'fill_out_form', label: 'Fill out a form' },
  { value: 'book', label: 'Book' },
  { value: 'visit_location', label: 'Visit our location' },
  { value: 'buy_online', label: 'Buy online' },
  { value: 'attend_event', label: 'Attend an event' },
  { value: 'request_quote', label: 'Request a quote' },
]

export const MONTH_OPTIONS: { value: number; label: string }[] = [
  { value: 1, label: 'January' },
  { value: 2, label: 'February' },
  { value: 3, label: 'March' },
  { value: 4, label: 'April' },
  { value: 5, label: 'May' },
  { value: 6, label: 'June' },
  { value: 7, label: 'July' },
  { value: 8, label: 'August' },
  { value: 9, label: 'September' },
  { value: 10, label: 'October' },
  { value: 11, label: 'November' },
  { value: 12, label: 'December' },
]
