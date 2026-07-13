/**
 * The 10 Marketing Assets cards shown in onboarding's Marketing Assets step
 * (Milestone 15 Phase 1), and the identifying-detail fields collected for
 * each in the following Asset Details step. Deliberately a curated subset
 * of the full 12 MarketingChannelType values (excludes TikTok, Other) —
 * mirrors OnboardingController::ASSET_CARD_TYPES on the PHP side. See
 * docs/specs/Business-Discovery-Onboarding.md §2.4-2.5.
 */

export interface OnboardingAssetType {
  type: string
  label: string
  /** Types with no identifying field require nothing further in step 5. */
  requiresDetails: boolean
}

export const ONBOARDING_ASSET_TYPES: OnboardingAssetType[] = [
  { type: 'website', label: 'Website', requiresDetails: true },
  { type: 'google_business_profile', label: 'Google Business Profile', requiresDetails: true },
  { type: 'instagram', label: 'Instagram', requiresDetails: true },
  { type: 'facebook', label: 'Facebook', requiresDetails: true },
  { type: 'linkedin', label: 'LinkedIn', requiresDetails: true },
  { type: 'x', label: 'X', requiresDetails: true },
  { type: 'youtube', label: 'YouTube', requiresDetails: true },
  { type: 'email', label: 'Email Newsletter', requiresDetails: false },
  { type: 'events', label: 'Events', requiresDetails: false },
  { type: 'print', label: 'Print', requiresDetails: false },
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
