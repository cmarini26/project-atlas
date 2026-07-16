import { describe, expect, it } from 'vitest'
import { channelCapability, resolveChannelCapability, resolveDeclaredChannelCapability } from './channelCapability'

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
