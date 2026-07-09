/**
 * The 12 declarable MarketingChannelType values and their display labels —
 * shared between the onboarding "Where do your customers find you?" step
 * and the Settings Marketing Presence page, so both surfaces present the
 * same channel list without duplicating the label set. Mirrors
 * OnboardingController::CHANNEL_LABELS (backend/app/Http/Controllers/OnboardingController.php)
 * on the PHP side.
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
