import { afterEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { reactive } from 'vue'
import Show from './Show.vue'

const patchMock = vi.fn()

vi.mock('@inertiajs/vue3', () => ({
  Head: { template: '<head><slot /></head>' },
  Link: { template: '<a><slot /></a>', props: ['href'] },
  useForm: (initial: Record<string, unknown>) =>
    reactive({
      ...initial,
      processing: false,
      errors: {},
      patch: (url: string, options?: unknown) => patchMock(url, options),
    }),
}))

const baseProps = {
  campaign: {
    id: 'camp-1',
    title: 'Spring Sale',
    campaign_type: 'featured_item',
    status: 'approved' as const,
    created_at: '2026-07-01T00:00:00Z',
    completed_at: null,
  },
  content_assets: [],
  executions: [],
  kpi_snapshot: null,
  decision: null,
}

describe('Campaigns/Show — Email audience targeting', () => {
  afterEach(() => {
    patchMock.mockClear()
  })

  it('shows "Not configured" and no audience selected when Email is disconnected', () => {
    const wrapper = mount(Show, {
      props: {
        ...baseProps,
        email_audience_selector: { audiences: [], selected: null, linked_marketing_channel: null },
      },
    })

    expect(wrapper.text()).toContain('No audience selected yet.')
  })

  it('shows "Connected" once a linked marketing channel supports publishing', () => {
    const wrapper = mount(Show, {
      props: {
        ...baseProps,
        email_audience_selector: {
          audiences: [],
          selected: null,
          linked_marketing_channel: { supports_publishing: true },
        },
      },
    })

    expect(wrapper.text()).toContain('Connected')
  })

  it('lists selectable audiences with their member counts', () => {
    const wrapper = mount(Show, {
      props: {
        ...baseProps,
        email_audience_selector: {
          audiences: [
            { id: 'aud-1', name: 'Newsletter', member_count: 120 },
            { id: 'aud-2', name: 'VIP', member_count: 5 },
          ],
          selected: null,
          linked_marketing_channel: null,
        },
      },
    })

    const options = wrapper.findAll('option')
    expect(options.map((o) => o.text())).toEqual(
      expect.arrayContaining(['Newsletter (120)', 'VIP (5)']),
    )
  })

  it('warns when the selected audience is empty', () => {
    const wrapper = mount(Show, {
      props: {
        ...baseProps,
        email_audience_selector: {
          audiences: [{ id: 'aud-1', name: 'Empty List', member_count: 0 }],
          selected: { id: 'aud-1', name: 'Empty List', member_count: 0 },
          linked_marketing_channel: null,
        },
      },
    })

    expect(wrapper.text()).toContain('this audience is empty, nothing would be sent')
  })

  it('shows the recipient count for a non-empty selected audience without a warning', () => {
    const wrapper = mount(Show, {
      props: {
        ...baseProps,
        email_audience_selector: {
          audiences: [{ id: 'aud-1', name: 'Newsletter', member_count: 42 }],
          selected: { id: 'aud-1', name: 'Newsletter', member_count: 42 },
          linked_marketing_channel: null,
        },
      },
    })

    expect(wrapper.text()).toContain('42 recipients')
    expect(wrapper.text()).not.toContain('nothing would be sent')
  })

  it('submits the audience selection', async () => {
    const wrapper = mount(Show, {
      props: {
        ...baseProps,
        email_audience_selector: {
          audiences: [{ id: 'aud-1', name: 'Newsletter', member_count: 42 }],
          selected: null,
          linked_marketing_channel: null,
        },
      },
    })

    const forms = wrapper.findAll('form')
    const audienceForm = forms.find((f) => f.find('select').exists())
    await audienceForm?.find('select').setValue('aud-1')
    await audienceForm?.trigger('submit.prevent')

    expect(patchMock).toHaveBeenCalledWith('/app/campaigns/camp-1/email-audience', expect.anything())
  })
})
