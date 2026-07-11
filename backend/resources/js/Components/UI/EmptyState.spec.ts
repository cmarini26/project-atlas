import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import EmptyState from './EmptyState.vue'

describe('EmptyState', () => {
  it('renders the title', () => {
    const wrapper = mount(EmptyState, { props: { title: 'No campaigns yet' } })

    expect(wrapper.text()).toContain('No campaigns yet')
  })

  it('renders the description only when provided', () => {
    const withDescription = mount(EmptyState, {
      props: { title: 'No campaigns yet', description: 'Campaigns appear here after you approve a recommendation.' },
    })
    expect(withDescription.text()).toContain('Campaigns appear here after you approve a recommendation.')

    const withoutDescription = mount(EmptyState, { props: { title: 'No campaigns yet' } })
    expect(withoutDescription.findAll('p')).toHaveLength(1)
  })

  it('defaults to the default variant, preserving the existing muted look', () => {
    const wrapper = mount(EmptyState, { props: { title: 'No campaigns yet' } })

    const circle = wrapper.find('.rounded-full')
    expect(circle.classes().join(' ')).toContain('bg-[var(--color-surface-subtle)]')
  })

  it.each([
    ['accent', 'bg-[var(--color-accent-50)]'],
    ['success', 'bg-[var(--color-success-surface)]'],
    ['warning', 'bg-[var(--color-warning-surface)]'],
    ['info', 'bg-[var(--color-info-surface)]'],
  ] as const)('applies the %s variant background to the icon circle', (variant, expectedClass) => {
    const wrapper = mount(EmptyState, { props: { title: 'Title', variant } })

    const circle = wrapper.find('.rounded-full')
    expect(circle.classes().join(' ')).toContain(expectedClass)
  })

  it('renders a custom icon slot in place of the default ellipsis', () => {
    const wrapper = mount(EmptyState, {
      props: { title: 'No opportunities' },
      slots: { icon: '<svg data-testid="custom-icon" />' },
    })

    expect(wrapper.find('[data-testid="custom-icon"]').exists()).toBe(true)
  })

  it('renders the action slot only when provided', () => {
    const wrapper = mount(EmptyState, {
      props: { title: 'No campaigns yet' },
      slots: { action: '<button>New Campaign</button>' },
    })

    expect(wrapper.text()).toContain('New Campaign')
  })
})
