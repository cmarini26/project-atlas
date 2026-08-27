import { afterEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { reactive } from 'vue'
import Create from './Create.vue'

const postMock = vi.fn()
// eslint-disable-next-line @typescript-eslint/no-explicit-any
let lastForm: any = null

vi.mock('@inertiajs/vue3', () => ({
  Head: { template: '<head><slot /></head>' },
  Link: { template: '<a><slot /></a>', props: ['href'] },
  useForm: (initial: Record<string, unknown>) => {
    lastForm = reactive({
      ...initial,
      processing: false,
      errors: {},
      post: (url: string, options?: unknown) => postMock(url, options),
    })
    return lastForm
  },
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

const channels = [{ id: 'channel-1', type: 'email', name: 'Customer email' }]

type CreateProps = {
  assets: typeof assets
  channels: typeof channels
  initial_asset_ids: string[]
}

function mountCreate(props: Partial<CreateProps> = {}) {
  return mount(Create, {
    props: {
      assets,
      channels,
      initial_asset_ids: [],
      ...props,
    } as CreateProps,
    global: {
      stubs: {
        AppLayout: { template: '<main><slot /></main>' },
        AssetMediaPreview: true,
      },
    },
  })
}

const objective = 'Invite current customers to book the strategy intensive this fall.'

describe('Campaigns/Create', () => {
  afterEach(() => {
    postMock.mockClear()
  })

  it('submits on a valid prompt alone, with no assets required', async () => {
    const wrapper = mountCreate({ assets: [], channels })

    expect(wrapper.find('form').exists()).toBe(true)

    const submit = wrapper.find('button[type="submit"]')
    expect((submit.element as HTMLButtonElement).disabled).toBe(true)

    await wrapper.find('textarea').setValue(objective)
    expect((submit.element as HTMLButtonElement).disabled).toBe(false)

    await wrapper.find('form').trigger('submit.prevent')
    expect(postMock).toHaveBeenCalledWith('/app/campaigns', expect.anything())
  })

  it('keeps the page usable and shows an inline hint when the asset library is empty', () => {
    const wrapper = mountCreate({ assets: [], channels: [] })

    expect(wrapper.text()).toContain('No ready assets in your library yet')
    expect(wrapper.text()).not.toContain('Add a ready asset first')
    expect(wrapper.find('form').exists()).toBe(true)
    // Submit still blocked here only because there is no channel, not the library.
    expect(wrapper.text()).toContain('Use my own assets')
  })

  it('accepts a prompt plus selected assets', async () => {
    const wrapper = mountCreate({ initial_asset_ids: ['asset-1'] })

    // Pre-selected asset expands the section and is checked.
    const details = wrapper.find('details')
    expect((details.element as HTMLDetailsElement).open).toBe(true)

    const assetCheckbox = wrapper.find('input[type="checkbox"][value="asset-2"]')
    await assetCheckbox.setValue(true)
    expect((assetCheckbox.element as HTMLInputElement).checked).toBe(true)

    await wrapper.find('textarea').setValue(objective)
    await wrapper.find('form').trigger('submit.prevent')

    expect(postMock).toHaveBeenCalledWith('/app/campaigns', expect.anything())
    expect(wrapper.text()).toContain('You will review and approve before anything is queued.')
  })

  it('collapses the asset section by default', () => {
    const wrapper = mountCreate()

    expect((wrapper.find('details').element as HTMLDetailsElement).open).toBe(false)
  })

  it('communicates that composition takes time, including imagery', async () => {
    const wrapper = mountCreate({ assets: [], channels })

    expect(wrapper.find('button[type="submit"]').text()).toBe('Prepare campaign')

    lastForm.processing = true
    await wrapper.vm.$nextTick()

    expect(wrapper.find('button[type="submit"]').text()).toContain('generating imagery')
  })
})
