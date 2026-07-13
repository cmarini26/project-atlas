/**
 * The 12 declarable MarketingChannelType values and their display labels —
 * used by the Settings Marketing Presence page. Mirrors
 * MarketingChannelType::label() (backend/app/Enums/MarketingChannelType.php)
 * on the PHP side. The redesigned onboarding wizard's Marketing Assets step
 * uses its own curated subset — see lib/onboardingAssets.ts.
 */

export interface MarketingChannelTypeOption {
  type: string
  label: string
}

export const MARKETING_CHANNEL_TYPES: MarketingChannelTypeOption[] = [
  { type: 'website', label: 'Website' },
  { type: 'email', label: 'Email Newsletter' },
  { type: 'instagram', label: 'Instagram' },
  { type: 'facebook', label: 'Facebook' },
  { type: 'linkedin', label: 'LinkedIn' },
  { type: 'x', label: 'X' },
  { type: 'youtube', label: 'YouTube' },
  { type: 'tiktok', label: 'TikTok' },
  { type: 'google_business_profile', label: 'Google Business Profile' },
  { type: 'events', label: 'Events' },
  { type: 'print', label: 'Print' },
  { type: 'other', label: 'Other' },
]

const LABELS_BY_TYPE: Record<string, string> = Object.fromEntries(
  MARKETING_CHANNEL_TYPES.map((c) => [c.type, c.label]),
)

export function marketingChannelTypeLabel(type: string): string {
  return LABELS_BY_TYPE[type] ?? type
}
