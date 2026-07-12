import { afterEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { reactive } from 'vue'
import Index from './Index.vue'

const postMock = vi.fn()
const patchMock = vi.fn()
const deleteMock = vi.fn()

vi.mock('@inertiajs/vue3', () => ({
  Head: { template: '<head><slot /></head>' },
  Link: { template: '<a><slot /></a>' },
  router: {
    patch: (...args: unknown[]) => patchMock(...args),
    delete: (...args: unknown[]) => deleteMock(...args),
  },
  useForm: (initial: Record<string, unknown>) =>
    reactive({
      ...initial,
      processing: false,
      errors: {},
      post: postMock,
      reset: vi.fn(),
    }),
}))

function makeChannel(overrides: Record<string, unknown> = {}) {
  return {
    id: 'channel-1',
    type: 'instagram',
    display_name: 'CBB Auctions Instagram',
    handle_or_url: '@cbb_auctions',
    status: 'active',
    importance: 'primary',
    objective: ['awareness'],
    capability: 'declared',
    ...overrides,
  }
}

const baseProps = {
  statuses: ['active', 'occasional', 'planned', 'inactive'],
  importances: ['primary', 'secondary', 'experimental'],
  objectives: ['awareness', 'leads', 'sales', 'retention', 'trust', 'seo', 'community'],
}

describe('MarketingPresence/Index', () => {
  afterEach(() => {
    postMock.mockClear()
    patchMock.mockClear()
    deleteMock.mockClear()
  })

  it('renders existing channels without error', () => {
    const wrapper = mount(Index, {
      props: { ...baseProps, channels: [makeChannel()] },
    })

    expect(wrapper.text()).toContain('CBB Auctions Instagram')
  })

  // Regression test: adding a channel triggers an Inertia reload that
  // brings back an updated `channels` prop containing a row that didn't
  // exist when the component mounted. Before the fix, the per-row edit
  // state (`rowState`) was only ever populated once at mount time, so the
  // new row's <select v-model="rowState[channel.id].status"> threw
  // "Cannot read properties of undefined (reading 'status')" and crashed
  // the render — which is exactly what left the "Add channel" button
  // stuck showing "Adding..." forever in production.
  it('renders a newly-added channel after props update without crashing', async () => {
    const wrapper = mount(Index, {
      props: { ...baseProps, channels: [makeChannel()] },
    })

    const newChannel = makeChannel({
      id: 'channel-2',
      display_name: 'Euro Classix Cars',
      handle_or_url: '@euroclassixcars',
      importance: 'secondary',
    })

    await wrapper.setProps({ channels: [makeChannel(), newChannel] })

    expect(wrapper.text()).toContain('Euro Classix Cars')

    // The new row's status <select> must be bound to a real rowState
    // entry, not throw on an undefined one.
    const selects = wrapper.findAll('select')
    expect(selects.length).toBeGreaterThan(0)
  })

  it('does not clobber unsaved edits to existing rows when a new channel is added', async () => {
    const wrapper = mount(Index, {
      props: { ...baseProps, channels: [makeChannel()] },
    })

    // Simulate the user having changed the first row's status select
    // in-memory before the second channel's add-channel reload arrives.
    // findAll('select')[0] is the "Add a channel" type dropdown; [1] is
    // the first declared channel's status select.
    const firstRowStatusSelect = wrapper.findAll('select')[1]
    await firstRowStatusSelect.setValue('inactive')

    await wrapper.setProps({
      channels: [makeChannel(), makeChannel({ id: 'channel-2', display_name: 'Second Channel' })],
    })

    // The first row's <select> should still reflect the unsaved edit —
    // a naive fix that re-derives rowState from props on every change
    // would have reset it back to 'active'.
    const firstRowSelectAfter = wrapper.findAll('select')[1]
    expect((firstRowSelectAfter.element as HTMLSelectElement).value).toBe('inactive')
  })

  // Milestone 12 Phase 2 — Instagram Insights (read-only)
  describe('instagram_insights', () => {
    it('renders nothing when instagram_insights is null', () => {
      const wrapper = mount(Index, {
        props: { ...baseProps, channels: [], instagram_insights: null },
      })

      expect(wrapper.text()).not.toContain('Instagram Insights')
    })

    it('renders posting cadence, media mix, and top hashtags', () => {
      const wrapper = mount(Index, {
        props: {
          ...baseProps,
          channels: [],
          instagram_insights: {
            username: 'cbb_auctions',
            last_synced_at: '2026-07-12T00:00:00Z',
            posting_cadence: 2.5,
            media_mix: { IMAGE: 3, VIDEO: 1 },
            hashtag_usage: { avg_per_post: 1.5, top: [{ tag: 'comics', count: 4 }] },
            cta_usage: 25,
            content_distribution: { Monday: 1, Tuesday: 0, Wednesday: 0, Thursday: 0, Friday: 0, Saturday: 0, Sunday: 0 },
            engagement_trend: { avg_likes: 100, avg_comments: 10, trend: 'increasing' },
          },
        },
      })

      expect(wrapper.text()).toContain('Instagram Insights')
      expect(wrapper.text()).toContain('2.5 posts/week')
      expect(wrapper.text()).toContain('IMAGE: 3')
      expect(wrapper.text()).toContain('#comics (4)')
      expect(wrapper.text()).toContain('increasing')
    })

    it('shows a fallback message when posting cadence has not been computed yet', () => {
      const wrapper = mount(Index, {
        props: {
          ...baseProps,
          channels: [],
          instagram_insights: {
            username: 'cbb_auctions',
            last_synced_at: null,
            posting_cadence: null,
            media_mix: null,
            hashtag_usage: null,
            cta_usage: null,
            content_distribution: null,
            engagement_trend: null,
          },
        },
      })

      expect(wrapper.text()).toContain('Not enough posts yet')
    })
  })
})
