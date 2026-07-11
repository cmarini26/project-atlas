import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import Badge from './Badge.vue'

describe('Badge', () => {
  it('renders slot content', () => {
    const wrapper = mount(Badge, { slots: { default: 'Published' } })

    expect(wrapper.text()).toBe('Published')
  })

  it('applies the info variant using the info tokens', () => {
    const wrapper = mount(Badge, { props: { variant: 'info' }, slots: { default: 'Info' } })

    expect(wrapper.classes().join(' ')).toContain('bg-[var(--color-info-surface)]')
  })

  it('falls back to the default variant styling when none is passed', () => {
    const wrapper = mount(Badge, { slots: { default: 'Default' } })

    expect(wrapper.classes().join(' ')).toContain('bg-[var(--color-surface-subtle)]')
  })
})
