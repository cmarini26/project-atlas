import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import MarketingChannelCapabilityBadge from './MarketingChannelCapabilityBadge.vue'

describe('MarketingChannelCapabilityBadge', () => {
  it.each([
    ['declared', 'Declared'],
    ['connected', 'Connected'],
    ['publishing_enabled', 'Publishing enabled'],
    ['analytics_enabled', 'Analytics enabled'],
  ] as const)('renders the "%s" capability as "%s"', (capability, label) => {
    const wrapper = mount(MarketingChannelCapabilityBadge, { props: { capability } })

    expect(wrapper.text()).toBe(label)
  })

  it('never claims Atlas can publish for a merely declared channel', () => {
    const wrapper = mount(MarketingChannelCapabilityBadge, { props: { capability: 'declared' } })

    expect(wrapper.text().toLowerCase()).not.toContain('publish')
  })
})
