import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import Card from './Card.vue'

vi.mock('@inertiajs/vue3', () => ({
  Link: { template: '<a><slot /></a>', props: ['href'] },
}))

describe('Card', () => {
  it('renders as a plain div by default', () => {
    const wrapper = mount(Card, { slots: { default: 'Body content' } })

    expect(wrapper.element.tagName).toBe('DIV')
    expect(wrapper.text()).toContain('Body content')
  })

  it('renders as a Link when href is provided', () => {
    const wrapper = mount(Card, { props: { href: '/somewhere' } })

    expect(wrapper.element.tagName).toBe('A')
  })

  it('renders the header slot only when provided', () => {
    const withHeader = mount(Card, { slots: { header: '<h2>Title</h2>' } })
    expect(withHeader.find('h2').exists()).toBe(true)

    const withoutHeader = mount(Card)
    expect(withoutHeader.find('h2').exists()).toBe(false)
  })

  it('applies the divided class when divided is true', () => {
    const wrapper = mount(Card, { props: { divided: true } })

    expect(wrapper.classes()).toContain('divide-y')
  })

  it('applies an accent left border and shadow when accent is set', () => {
    const wrapper = mount(Card, { props: { accent: 'indigo' } })

    expect(wrapper.classes()).toContain('border-l-4')
    expect(wrapper.classes().some((c) => c.includes('shadow-[var(--shadow-accent)]'))).toBe(true)
  })

  it('applies clickable hover styles when clickable is true', () => {
    const wrapper = mount(Card, { props: { clickable: true } })

    expect(wrapper.classes()).toContain('cursor-pointer')
  })
})
