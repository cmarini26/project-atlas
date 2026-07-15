import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import Button from './Button.vue'

describe('Button', () => {
  it('renders as a native button by default', () => {
    const wrapper = mount(Button, { slots: { default: 'Continue' } })

    expect(wrapper.element.tagName).toBe('BUTTON')
    expect(wrapper.text()).toBe('Continue')
  })

  it('renders as an anchor when as="a"', () => {
    const wrapper = mount(Button, { props: { as: 'a', href: '/app' }, slots: { default: 'Go to dashboard' } })

    expect(wrapper.element.tagName).toBe('A')
    expect(wrapper.attributes('href')).toBe('/app')
  })

  it('applies variant classes', () => {
    const secondary = mount(Button, { props: { variant: 'secondary' } })
    expect(secondary.classes().some((c) => c.includes('border'))).toBe(true)

    const ghost = mount(Button, { props: { variant: 'ghost' } })
    expect(ghost.classes().some((c) => c.includes('underline'))).toBe(true)
  })

  it('disables the button and blocks clicks when loading', async () => {
    const wrapper = mount(Button, { props: { loading: true } })

    expect(wrapper.attributes('disabled')).toBeDefined()

    await wrapper.trigger('click')
    expect(wrapper.emitted('click')).toBeUndefined()
  })

  it('emits click when enabled', async () => {
    const wrapper = mount(Button)

    await wrapper.trigger('click')
    expect(wrapper.emitted('click')).toHaveLength(1)
  })

  it('applies fullWidth as w-full', () => {
    const wrapper = mount(Button, { props: { fullWidth: true } })

    expect(wrapper.classes()).toContain('w-full')
  })
})
