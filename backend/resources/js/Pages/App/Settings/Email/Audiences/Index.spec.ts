import { afterEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { reactive } from 'vue'
import Index from './Index.vue'

const postMock = vi.fn((url: string, options?: { onSuccess?: () => void }) => {
  options?.onSuccess?.()
})
const routerPatchMock = vi.fn()

vi.mock('@inertiajs/vue3', () => ({
  Head: { template: '<head><slot /></head>' },
  Link: { template: '<a><slot /></a>', props: ['href'] },
  router: {
    patch: (...args: unknown[]) => routerPatchMock(...args),
  },
  useForm: (initial: Record<string, unknown>) =>
    reactive({
      ...initial,
      processing: false,
      errors: {},
      post: (url: string, options?: { onSuccess?: () => void }) => postMock(url, options),
      reset: vi.fn(),
    }),
}))

describe('Settings/Email/Audiences/Index', () => {
  afterEach(() => {
    postMock.mockClear()
    routerPatchMock.mockClear()
  })

  it('shows an empty state when there are no audiences', () => {
    const wrapper = mount(Index, { props: { audiences: [] } })

    expect(wrapper.text()).toContain('No audiences yet.')
  })

  it('lists audiences with their member counts', () => {
    const wrapper = mount(Index, {
      props: {
        audiences: [
          { id: 'aud-1', name: 'Newsletter', status: 'active', member_count: 3 },
          { id: 'aud-2', name: 'VIP Buyers', status: 'archived', member_count: 0 },
        ],
      },
    })

    expect(wrapper.text()).toContain('Newsletter')
    expect(wrapper.text()).toContain('3 contacts')
    expect(wrapper.text()).toContain('VIP Buyers')
    expect(wrapper.text()).toContain('empty audience')
  })

  it('does not show an archive button for an already-archived audience', () => {
    const wrapper = mount(Index, {
      props: {
        audiences: [{ id: 'aud-1', name: 'Old List', status: 'archived', member_count: 0 }],
      },
    })

    expect(wrapper.findAll('button').filter((b) => b.text() === 'Archive')).toHaveLength(0)
  })

  it('submits the create-audience form', async () => {
    const wrapper = mount(Index, { props: { audiences: [] } })

    await wrapper.find('#audience-name').setValue('Newsletter')
    await wrapper.find('form').trigger('submit.prevent')

    expect(postMock).toHaveBeenCalledWith('/app/settings/email/audiences', expect.anything())
  })

  it('archives an audience via the router', async () => {
    const wrapper = mount(Index, {
      props: {
        audiences: [{ id: 'aud-1', name: 'Newsletter', status: 'active', member_count: 2 }],
      },
    })

    await wrapper.find('button').element // no-op to ensure render settled
    const archiveButton = wrapper.findAll('button').find((b) => b.text() === 'Archive')
    await archiveButton?.trigger('click')

    expect(routerPatchMock).toHaveBeenCalledWith(
      '/app/settings/email/audiences/aud-1',
      { archived: true },
      expect.anything(),
    )
  })
})
