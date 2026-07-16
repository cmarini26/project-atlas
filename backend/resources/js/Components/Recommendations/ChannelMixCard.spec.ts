import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import ChannelMixCard from './ChannelMixCard.vue'
import type { ChannelMix } from '@/types'

const emptyMix: ChannelMix = { primary: [], supporting: [], draft_only: [], unavailable: [] }

describe('ChannelMixCard', () => {
  it('renders nothing when the channel mix is entirely empty', () => {
    const wrapper = mount(ChannelMixCard, { props: { channelMix: emptyMix } })

    expect(wrapper.text()).toBe('')
  })

  it('renders a primary channel labeled "Draft only" when it is linked but not publishing-enabled', () => {
    const mix: ChannelMix = {
      ...emptyMix,
      primary: [{ type: 'instagram', name: 'Acme Instagram', marketing_channel: { supports_publishing: false } }],
    }

    const wrapper = mount(ChannelMixCard, { props: { channelMix: mix } })

    expect(wrapper.text()).toContain('Acme Instagram')
    expect(wrapper.text()).toContain('Primary')
    expect(wrapper.text()).toContain('Draft only')
    expect(wrapper.text()).not.toContain('Can publish')
  })

  it('renders "Connected" only when the linked marketing channel supports publishing', () => {
    const mix: ChannelMix = {
      ...emptyMix,
      supporting: [{ type: 'email', name: 'Acme Newsletter', marketing_channel: { supports_publishing: true } }],
    }

    const wrapper = mount(ChannelMixCard, { props: { channelMix: mix } })

    expect(wrapper.text()).toContain('Connected')
  })

  it('falls back to the global type lookup for an executable channel with no marketing channel link', () => {
    const mix: ChannelMix = {
      ...emptyMix,
      supporting: [{ type: 'blog', name: 'Blog', marketing_channel: null }],
    }

    const wrapper = mount(ChannelMixCard, { props: { channelMix: mix } })

    expect(wrapper.text()).toContain('Draft only')
  })

  it('renders draft-only channels with a Manual action required or Not configured badge, never implying publishing', () => {
    const mix: ChannelMix = {
      ...emptyMix,
      draft_only: [
        { type: 'instagram', name: 'Declared Instagram' },
        { type: 'print', name: 'Local Paper Ad' },
      ],
    }

    const wrapper = mount(ChannelMixCard, { props: { channelMix: mix } })

    expect(wrapper.text()).toContain('Declared Instagram')
    // instagram has a real Channel equivalent — this company just hasn't
    // connected it, so it's actionable ("Manual action required"), unlike...
    expect(wrapper.text()).toContain('Manual action required')
    expect(wrapper.text()).toContain('Local Paper Ad')
    // ...print, which has no Channel equivalent at all — nothing to
    // configure yet, for any company ("Not configured").
    expect(wrapper.text()).toContain('Not configured')
    expect(wrapper.text()).not.toContain('Can publish')
  })

  it('renders unavailable channels with their reason and no capability badge', () => {
    const mix: ChannelMix = {
      ...emptyMix,
      unavailable: [
        { type: 'x', name: 'Old X Account', reason: 'inactive' },
        { type: 'youtube', name: 'Future YouTube', reason: 'planned' },
      ],
    }

    const wrapper = mount(ChannelMixCard, { props: { channelMix: mix } })

    expect(wrapper.text()).toContain('Old X Account')
    expect(wrapper.text()).toContain('no longer active')
    expect(wrapper.text()).toContain('Future YouTube')
    expect(wrapper.text()).toContain('planned, not started yet')
  })
})
