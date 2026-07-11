import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import FaqSection from './FaqSection.vue'

describe('FaqSection', () => {
  it('renders every question collapsed by default', () => {
    const wrapper = mount(FaqSection)

    const buttons = wrapper.findAll('button[aria-expanded]')
    expect(buttons.length).toBeGreaterThanOrEqual(8)
    buttons.forEach((button) => {
      expect(button.attributes('aria-expanded')).toBe('false')
    })
  })

  it('expands a question on click and marks its panel visible', async () => {
    const wrapper = mount(FaqSection)

    const firstButton = wrapper.find('button[aria-expanded]')
    await firstButton.trigger('click')

    expect(firstButton.attributes('aria-expanded')).toBe('true')

    const panelId = firstButton.attributes('aria-controls')
    const panel = wrapper.find(`#${panelId}`)
    expect(panel.attributes('aria-hidden')).toBe('false')
  })

  it('collapses an expanded question on a second click', async () => {
    const wrapper = mount(FaqSection)

    const firstButton = wrapper.find('button[aria-expanded]')
    await firstButton.trigger('click')
    await firstButton.trigger('click')

    expect(firstButton.attributes('aria-expanded')).toBe('false')
  })

  it('only one question is expanded at a time', async () => {
    const wrapper = mount(FaqSection)

    const buttons = wrapper.findAll('button[aria-expanded]')
    await buttons[0]?.trigger('click')
    await buttons[1]?.trigger('click')

    expect(buttons[0]?.attributes('aria-expanded')).toBe('false')
    expect(buttons[1]?.attributes('aria-expanded')).toBe('true')
  })
})
