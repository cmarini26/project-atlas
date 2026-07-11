import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import MarketingNav from './MarketingNav.vue'

describe('MarketingNav', () => {
  it('has a skip-to-content link as the first focusable element', () => {
    const wrapper = mount(MarketingNav, { attachTo: document.body })

    const skipLink = wrapper.find('a[href="#main-content"]')
    expect(skipLink.exists()).toBe(true)
    expect(skipLink.text()).toContain('Skip to content')

    wrapper.unmount()
  })

  it('opens the mobile menu and moves focus to the first link', async () => {
    const wrapper = mount(MarketingNav, { attachTo: document.body })

    const toggle = wrapper.find('button[aria-haspopup="true"]')
    expect(toggle.attributes('aria-expanded')).toBe('false')

    await toggle.trigger('click')
    await new Promise((resolve) => setTimeout(resolve, 0))

    expect(toggle.attributes('aria-expanded')).toBe('true')
    expect(wrapper.find('#mobile-menu').exists()).toBe(true)

    wrapper.unmount()
  })

  it('closes the mobile menu when the close button is clicked', async () => {
    const wrapper = mount(MarketingNav, { attachTo: document.body })

    await wrapper.find('button[aria-haspopup="true"]').trigger('click')
    expect(wrapper.find('#mobile-menu').exists()).toBe(true)

    const closeButton = wrapper.findAll('button').find((button) => button.text().includes('Close menu'))
    await closeButton?.trigger('click')

    expect(wrapper.find('#mobile-menu').exists()).toBe(false)

    wrapper.unmount()
  })
})
