import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import MarketingHealth from './MarketingHealth.vue'

vi.mock('@inertiajs/vue3', () => ({
  Head: { template: '<head><slot /></head>' },
  Link: { template: '<a><slot /></a>' },
}))

describe('App/MarketingHealth', () => {
  it('shows the empty state when there is no composite score yet', () => {
    const wrapper = mount(MarketingHealth, {
      props: { composite: null, dimensions: [] },
    })

    expect(wrapper.text()).toContain('Not enough data yet')
  })

  it('renders the overall score and its confidence', () => {
    const wrapper = mount(MarketingHealth, {
      props: { composite: { score: 82, confidence: 90 }, dimensions: [] },
    })

    expect(wrapper.text()).toContain('82')
    expect(wrapper.text()).toContain('Confidence: 90%')
    expect(wrapper.text()).toContain('Strong')
  })

  it('renders a scored dimension with its evidence', () => {
    const wrapper = mount(MarketingHealth, {
      props: {
        composite: { score: 60, confidence: 70 },
        dimensions: [
          {
            dimension: 'website',
            score: 60,
            confidence: 70,
            evidence: [{ label: 'Last crawled 2 day(s) ago', source_type: 'observation', source_id: null, value: null }],
            computed_at: '2026-07-12T00:00:00Z',
          },
        ],
      },
    })

    expect(wrapper.text()).toContain('Website Health')
    expect(wrapper.text()).toContain('1 supporting evidence item(s)')
  })

  it('renders an N/A state for dimensions with no score yet', () => {
    const wrapper = mount(MarketingHealth, {
      props: { composite: null, dimensions: [] },
    })

    expect(wrapper.text()).toContain('Not enough data yet for this dimension')
    expect(wrapper.text()).toContain('N/A')
  })
})
