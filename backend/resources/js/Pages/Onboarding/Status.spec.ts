import { afterEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { flushPromises } from '@vue/test-utils'
import Status from './Status.vue'

vi.mock('@inertiajs/vue3', () => ({
  Head: { template: '<head><slot /></head>' },
}))

function mockFetchOnce(body: Record<string, unknown>): void {
  vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
    ok: true,
    json: () => Promise.resolve(body),
  }))
}

const emptyProgress = {
  stage: 'discovering',
  started_at: '2026-07-14T00:00:00Z',
  completed_at: null,
  connectors: [],
  facts_created: 0,
  opportunities_found: 0,
  recommendations_generated: 0,
  recommendation_count: 0,
  first_recommendation_id: null,
}

describe('Onboarding/Status', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('shows the four discovery stages', async () => {
    mockFetchOnce(emptyProgress)
    const wrapper = mount(Status)
    await flushPromises()

    expect(wrapper.text()).toContain('Discover')
    expect(wrapper.text()).toContain('Analyze')
    expect(wrapper.text()).toContain('Understand')
    expect(wrapper.text()).toContain('Recommend')

    wrapper.unmount()
  })

  it('renders per-asset connector status underneath Discover', async () => {
    mockFetchOnce({
      ...emptyProgress,
      stage: 'analyzing',
      connectors: [
        { type: 'website', label: 'Website', status: 'succeeded', error_message: null },
        { type: 'facebook', label: 'Facebook', status: 'not_attempted', error_message: null },
      ],
    })
    const wrapper = mount(Status)
    await flushPromises()

    expect(wrapper.text()).toContain('Website')
    expect(wrapper.text()).toContain('Facebook')
    expect(wrapper.text()).toContain('not connected')

    wrapper.unmount()
  })

  it('shows the completion summary once discovery has completed', async () => {
    mockFetchOnce({
      ...emptyProgress,
      stage: 'completed',
      completed_at: '2026-07-14T00:05:00Z',
      connectors: [
        { type: 'website', label: 'Website', status: 'succeeded', error_message: null },
      ],
      facts_created: 4,
      opportunities_found: 1,
      recommendations_generated: 1,
      recommendation_count: 1,
      first_recommendation_id: 'rec_123',
    })
    const wrapper = mount(Status)
    await flushPromises()

    expect(wrapper.text()).toContain('Atlas discovered your business')
    expect(wrapper.text()).toContain('Website')
    expect(wrapper.text()).toContain('Business Brain updated')
    expect(wrapper.text()).toContain('4 facts created')
    expect(wrapper.text()).toContain('1 opportunity found')
    expect(wrapper.text()).toContain('1 recommendation generated')

    const link = wrapper.find('a[href="/app/recommendations/rec_123"]')
    expect(link.exists()).toBe(true)

    wrapper.unmount()
  })

  it('shows a non-dead-end message when every connector failed', async () => {
    mockFetchOnce({
      ...emptyProgress,
      stage: 'completed_with_errors',
      completed_at: '2026-07-14T00:05:00Z',
      connectors: [
        { type: 'website', label: 'Website', status: 'failed', error_message: 'Connection refused' },
      ],
    })
    const wrapper = mount(Status)
    await flushPromises()

    expect(wrapper.text()).toContain("Atlas couldn't discover anything yet")
    expect(wrapper.text()).toContain('Connection refused')
    expect(wrapper.find('a[href="/onboarding"]').exists()).toBe(true)

    wrapper.unmount()
  })
})
