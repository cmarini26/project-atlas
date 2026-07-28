import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import RecommendationPrompt from './RecommendationPrompt.vue'

vi.mock('@inertiajs/vue3', () => ({
  Link: {
    props: ['href'],
    template: '<a :href="href"><slot /></a>',
  },
}))

describe('RecommendationPrompt', () => {
  it('uses readable text and a high-contrast action on the light card surface', () => {
    const wrapper = mount(RecommendationPrompt, {
      props: {
        recommendation: {
          id: 'rec-1',
          campaign_type: 'email',
          rationale_display: {
            why_now: 'Customers are ready to hear about the new offer.',
          },
        } as never,
      },
    })

    expect(wrapper.text()).toContain('Decision ready')
    expect(wrapper.text()).toContain('Customers are ready')
    expect(wrapper.html()).toContain('text-[var(--color-text-primary)]')
    expect(wrapper.html()).toContain('text-[var(--color-text-secondary)]')
    expect(wrapper.html()).toContain('bg-[var(--color-accent-500)]')
    expect(wrapper.html()).not.toContain('text-white/58')
    expect(wrapper.html()).not.toContain('text-white/74')
  })
})
