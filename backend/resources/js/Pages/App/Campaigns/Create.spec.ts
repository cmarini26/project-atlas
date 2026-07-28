import { afterEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { reactive } from 'vue'
import Create from './Create.vue'

const postMock = vi.fn()

vi.mock('@inertiajs/vue3', () => ({
  Head: { template: '<head><slot /></head>' },
  Link: { template: '<a><slot /></a>', props: ['href'] },
  useForm: (initial: Record<string, unknown>) =>
    reactive({
      ...initial,
      processing: false,
      errors: {},
      post: (url: string, options?: unknown) => postMock(url, options),
    }),
}))

const assets = [
  {
    id: 'asset-1',
    type: 'product_service',
    title: 'Strategy intensive',
    description: 'A focused strategy service.',
    media_url: null,
    media_mime_type: null,
  },
  {
    id: 'asset-2',
    type: 'document_case_study',
    title: 'Customer growth story',
    description: 'Proof from a customer.',
    media_url: null,
    media_mime_type: null,
  },
]

describe('Campaigns/Create', () => {
  afterEach(() => {
    postMock.mockClear()
  })

  it('preselects an asset, accepts campaign direction, and prepares drafts', async () => {
    const wrapper = mount(Create, {
      props: {
        assets,
        channels: [{ id: 'channel-1', type: 'email', name: 'Customer email' }],
        initial_asset_ids: ['asset-1'],
      },
      global: {
        stubs: {
          AppLayout: { template: '<main><slot /></main>' },
          AssetMediaPreview: true,
        },
      },
    })

    const assetCheckboxes = wrapper.findAll('input[type="checkbox"]')
    expect((assetCheckboxes[0].element as HTMLInputElement).checked).toBe(true)

    await assetCheckboxes[1].setValue(true)
    await assetCheckboxes[2].setValue(true)
    await wrapper.find('input[placeholder="Fall customer appreciation campaign"]').setValue('Customer appreciation')
    await wrapper.find('textarea[placeholder^="What should this campaign accomplish"]').setValue(
      'Invite current customers to book the strategy intensive this fall.',
    )
    await wrapper.find('form').trigger('submit.prevent')

    expect(postMock).toHaveBeenCalledWith('/app/campaigns', expect.anything())
    expect(wrapper.text()).toContain('Nothing publishes until you approve it.')
    expect(wrapper.text()).toContain('You will review and approve before anything is queued.')
  })

  it('explains what is required when no ready assets exist', () => {
    const wrapper = mount(Create, {
      props: {
        assets: [],
        channels: [],
        initial_asset_ids: [],
      },
      global: {
        stubs: { AppLayout: { template: '<main><slot /></main>' } },
      },
    })

    expect(wrapper.text()).toContain('Add a ready asset first')
    expect(wrapper.find('form').exists()).toBe(false)
  })
})
