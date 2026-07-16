import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import ChannelCapabilityBadge from './ChannelCapabilityBadge.vue'

describe('ChannelCapabilityBadge', () => {
  it('renders "Connected" for a channel type with a publishing-verified linked marketing channel', () => {
    const wrapper = mount(ChannelCapabilityBadge, {
      props: { channelType: 'email', linkedMarketingChannel: { supportsPublishing: true } },
    })

    expect(wrapper.text()).toBe('Connected')
    expect(wrapper.find('span').classes().join(' ')).toContain('emerald')
  })

  it('renders "Draft only" for a global-fallback channel type with no company-specific link', () => {
    const wrapper = mount(ChannelCapabilityBadge, { props: { channelType: 'blog' } })

    expect(wrapper.text()).toBe('Draft only')
    expect(wrapper.find('span').classes().join(' ')).toContain('amber')
  })

  it('renders "Manual action required" for a real-connect-flow type this company has not linked', () => {
    const wrapper = mount(ChannelCapabilityBadge, { props: { channelType: 'instagram' } })

    expect(wrapper.text()).toBe('Manual action required')
  })

  it('gives "Manual action required" a distinct color from "Not configured", not the same muted gray', () => {
    const manualAction = mount(ChannelCapabilityBadge, { props: { channelType: 'instagram' } })
    const notConfigured = mount(ChannelCapabilityBadge, { props: { channelType: 'sms' } })

    const manualActionClasses = manualAction.find('span').classes().join(' ')
    const notConfiguredClasses = notConfigured.find('span').classes().join(' ')

    expect(manualActionClasses).not.toBe(notConfiguredClasses)
  })

  it('renders "Not configured" for a channel type with no connect flow for any company', () => {
    const wrapper = mount(ChannelCapabilityBadge, { props: { channelType: 'sms' } })

    expect(wrapper.text()).toBe('Not configured')
  })

  it('exposes the full description as a title attribute for every state', () => {
    const wrapper = mount(ChannelCapabilityBadge, {
      props: { channelType: 'email', linkedMarketingChannel: { supportsPublishing: true } },
    })

    expect(wrapper.find('span').attributes('title')).toContain('Automatic live delivery')
  })
})
