import { describe, expect, it } from 'vitest'
import {
  CAPABILITY_DESCRIPTIONS,
  CAPABILITY_LABELS,
  channelCapability,
  resolveChannelCapability,
  resolveDeclaredChannelCapability,
} from './channelCapability'

describe('channelCapability', () => {
  it('reports facebook and instagram as not_configured, not coming_later, since Meta OAuth is a real connect path', () => {
    expect(channelCapability('facebook')).toBe('not_configured')
    expect(channelCapability('instagram')).toBe('not_configured')
  })

  it('still reports channel types with no creation path at all as coming_later', () => {
    expect(channelCapability('linkedin')).toBe('coming_later')
    expect(channelCapability('x')).toBe('coming_later')
    expect(channelCapability('sms')).toBe('coming_later')
    expect(channelCapability('landing_page')).toBe('coming_later')
  })

  it('keeps blog and email at the conservative draft_only default', () => {
    expect(channelCapability('blog')).toBe('draft_only')
    expect(channelCapability('email')).toBe('draft_only')
  })
})

describe('resolveChannelCapability', () => {
  it('promotes facebook/instagram to connected once the linked marketing channel is publishing-verified', () => {
    expect(resolveChannelCapability('facebook', { supportsPublishing: true })).toBe('connected')
    expect(resolveChannelCapability('instagram', { supportsPublishing: true })).toBe('connected')
  })

  it('falls back to not_configured for facebook/instagram with no link at all', () => {
    expect(resolveChannelCapability('facebook', null)).toBe('not_configured')
    expect(resolveChannelCapability('instagram', undefined)).toBe('not_configured')
  })

  it('reports draft_only for a linked channel that is not yet publishing-verified', () => {
    expect(resolveChannelCapability('facebook', { supportsPublishing: false })).toBe('draft_only')
  })
})

describe('resolveDeclaredChannelCapability', () => {
  it('reports not_configured for declared types with a real Channel equivalent', () => {
    expect(resolveDeclaredChannelCapability('facebook')).toBe('not_configured')
    expect(resolveDeclaredChannelCapability('instagram')).toBe('not_configured')
    expect(resolveDeclaredChannelCapability('email')).toBe('not_configured')
  })

  it('reports coming_later for declared types with no Channel equivalent', () => {
    expect(resolveDeclaredChannelCapability('print')).toBe('coming_later')
  })
})

describe('CAPABILITY_LABELS and CAPABILITY_DESCRIPTIONS', () => {
  // Task N5 (production-readiness gap plan): four states a customer can
  // tell apart — automatic live delivery, simulated/internal processing,
  // manual action required, and not configured. Locks in the exact wording
  // so a future edit can't silently reintroduce the pre-2026-07-16 mix-up
  // where `not_configured` said "Not configured" and `coming_later` said
  // "Coming later" — backwards from which one the user can actually act on.
  it('labels connected as automatic live delivery', () => {
    expect(CAPABILITY_LABELS.connected).toBe('Connected')
    expect(CAPABILITY_DESCRIPTIONS.connected).toContain('Automatic live delivery')
  })

  it('labels draft_only as simulated/internal processing', () => {
    expect(CAPABILITY_LABELS.draft_only).toBe('Draft only')
    expect(CAPABILITY_DESCRIPTIONS.draft_only).toContain('Simulated')
  })

  it('labels not_configured (a real connect flow this company has not used) as manual action required', () => {
    expect(CAPABILITY_LABELS.not_configured).toBe('Manual action required')
    expect(CAPABILITY_DESCRIPTIONS.not_configured).toContain('connect it in Settings')
  })

  it('labels coming_later (no connect flow for anyone) as not configured', () => {
    expect(CAPABILITY_LABELS.coming_later).toBe('Not configured')
    expect(CAPABILITY_DESCRIPTIONS.coming_later).toContain("no way to create or publish")
  })
})
