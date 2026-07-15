import { afterEach, describe, expect, it, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import Status from './Status.vue'

const visitMock = vi.fn()
const postMock = vi.fn()

vi.mock('@inertiajs/vue3', () => ({
  Head: { template: '<head><slot /></head>' },
  router: {
    visit: (...args: unknown[]) => visitMock(...args),
    post: (...args: unknown[]) => postMock(...args),
  },
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
  retry_available: false,
}

describe('Onboarding/Status', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
    visitMock.mockClear()
    postMock.mockClear()
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

  it('collapses per-asset connector detail behind a disclosure by default', async () => {
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

    // Not shown until expanded — implementation detail, not needed by default.
    expect(wrapper.text()).not.toContain('Facebook')

    const toggle = wrapper.findAll('button').find((b) => b.text().includes('Show details'))
    await toggle?.trigger('click')

    expect(wrapper.text()).toContain('Website')
    expect(wrapper.text()).toContain('Facebook')
    expect(wrapper.text()).toContain('not connected')

    wrapper.unmount()
  })

  it('redirects to the first pending recommendation when one exists', async () => {
    mockFetchOnce({
      ...emptyProgress,
      stage: 'completed',
      completed_at: '2026-07-14T00:05:00Z',
      connectors: [{ type: 'website', label: 'Website', status: 'succeeded', error_message: null }],
      recommendation_count: 1,
      first_recommendation_id: 'rec_123',
    })
    const wrapper = mount(Status)
    await flushPromises()

    expect(visitMock).toHaveBeenCalledWith('/app/recommendations/rec_123')

    wrapper.unmount()
  })

  it('shows a non-dead-end message when every connector failed, with a retry option', async () => {
    mockFetchOnce({
      ...emptyProgress,
      stage: 'completed_with_errors',
      completed_at: '2026-07-14T00:05:00Z',
      connectors: [
        { type: 'website', label: 'Website', status: 'failed', error_message: 'Connection refused' },
      ],
      retry_available: true,
    })
    const wrapper = mount(Status)
    await flushPromises()

    expect(wrapper.text()).toContain("Atlas couldn't discover anything yet")
    expect(wrapper.text()).toContain('Connection refused')
    expect(wrapper.find('a[href="/onboarding"]').exists()).toBe(true)

    const retryButton = wrapper.findAll('button').find((b) => b.text().includes('Try again'))
    expect(retryButton).toBeTruthy()
    await retryButton?.trigger('click')
    expect(postMock).toHaveBeenCalledWith('/onboarding/discovery/retry', {}, expect.anything())

    wrapper.unmount()
  })

  it('shows the truthful no-opportunities terminal state, never an indefinite spinner', async () => {
    mockFetchOnce({
      ...emptyProgress,
      stage: 'completed_no_opportunities',
      completed_at: '2026-07-14T00:05:00Z',
      connectors: [{ type: 'website', label: 'Website', status: 'succeeded', error_message: null }],
      facts_created: 3,
    })
    const wrapper = mount(Status)
    await flushPromises()

    expect(wrapper.text()).toContain('no campaign opportunity yet')
    expect(wrapper.text()).toContain('3 facts')
    expect(wrapper.find('a[href="/app"]').exists()).toBe(true)

    wrapper.unmount()
  })

  it('does not redirect and does not show a retry button when nothing failed and nothing is ready yet', async () => {
    mockFetchOnce({ ...emptyProgress, stage: 'analyzing', retry_available: false })
    const wrapper = mount(Status)
    await flushPromises()

    expect(visitMock).not.toHaveBeenCalled()
    expect(wrapper.findAll('button').some((b) => b.text().includes('Retry'))).toBe(false)

    wrapper.unmount()
  })
})
