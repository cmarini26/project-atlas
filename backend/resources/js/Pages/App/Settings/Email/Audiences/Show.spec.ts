import { afterEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { reactive } from 'vue'
import Show from './Show.vue'

const postMock = vi.fn((url: string, options?: { onSuccess?: () => void }) => {
  options?.onSuccess?.()
})
const routerDeleteMock = vi.fn()

let nextFormErrors: Record<string, string> = {}

vi.mock('@inertiajs/vue3', () => ({
  Head: { template: '<head><slot /></head>' },
  Link: { template: '<a><slot /></a>', props: ['href'] },
  router: {
    delete: (...args: unknown[]) => routerDeleteMock(...args),
  },
  useForm: (initial: Record<string, unknown>) =>
    reactive({
      ...initial,
      processing: false,
      errors: { ...nextFormErrors },
      post: (url: string, options?: { onSuccess?: () => void }) => postMock(url, options),
      reset: vi.fn(),
    }),
}))

const baseProps = {
  audience: { id: 'aud-1', name: 'Newsletter', status: 'active' },
  members: [],
}

describe('Settings/Email/Audiences/Show', () => {
  afterEach(() => {
    postMock.mockClear()
    routerDeleteMock.mockClear()
    nextFormErrors = {}
  })

  it('shows an empty state with no members', () => {
    const wrapper = mount(Show, { props: baseProps })

    expect(wrapper.text()).toContain('No contacts yet.')
  })

  it('lists members', () => {
    const wrapper = mount(Show, {
      props: {
        ...baseProps,
        members: [
          { id: 'c-1', email: 'alice@example.com', display_name: 'Alice', status: 'active' },
          { id: 'c-2', email: 'bob@example.com', display_name: null, status: 'active' },
        ],
      },
    })

    expect(wrapper.text()).toContain('Alice')
    expect(wrapper.text()).toContain('alice@example.com')
    expect(wrapper.text()).toContain('bob@example.com')
  })

  it('submits the add-contact form', async () => {
    const wrapper = mount(Show, { props: baseProps })

    await wrapper.find('#member-email').setValue('alice@example.com')
    await wrapper.find('form').trigger('submit.prevent')

    expect(postMock).toHaveBeenCalledWith('/app/settings/email/audiences/aud-1/members', expect.anything())
  })

  it('shows a validation error for an invalid email', () => {
    nextFormErrors = { email: 'The email field must be a valid email address.' }

    const wrapper = mount(Show, { props: baseProps })

    expect(wrapper.text()).toContain('must be a valid email address')
  })

  it('removes a member via the router', async () => {
    const wrapper = mount(Show, {
      props: {
        ...baseProps,
        members: [{ id: 'c-1', email: 'alice@example.com', display_name: 'Alice', status: 'active' }],
      },
    })

    const removeButton = wrapper.findAll('button').find((b) => b.text() === 'Remove')
    await removeButton?.trigger('click')

    expect(routerDeleteMock).toHaveBeenCalledWith(
      '/app/settings/email/audiences/aud-1/members/c-1',
      expect.anything(),
    )
  })
})
