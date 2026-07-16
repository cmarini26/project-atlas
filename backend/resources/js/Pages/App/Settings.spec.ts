import { afterEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { reactive } from 'vue'
import Settings from './Settings.vue'

const postMock = vi.fn((url: string, options?: { onSuccess?: () => void }) => {
  options?.onSuccess?.()
})
const routerPostMock = vi.fn()

let nextFormErrors: Record<string, string> = {}

vi.mock('@inertiajs/vue3', () => ({
  Head: { template: '<head><slot /></head>' },
  Link: { template: '<a><slot /></a>' },
  router: {
    post: (...args: unknown[]) => routerPostMock(...args),
  },
  useForm: (initial: Record<string, unknown>) => {
    // Key error injection off which form is being created — Settings.vue
    // creates several independent useForm() instances, and only the form
    // under test in a given case should carry the injected errors.
    const errors = 'api_token' in initial ? { ...nextFormErrors } : {}

    return reactive({
      ...initial,
      processing: false,
      recentlySuccessful: false,
      isDirty: false,
      errors,
      post: (url: string, options?: { onSuccess?: () => void }) => postMock(url, options),
      patch: (url: string, options?: { onSuccess?: () => void }) => postMock(url, options),
      reset: vi.fn(),
    })
  },
}))

vi.mock('@/composables/useProductTour', () => ({
  useProductTour: () => ({ requestTourStart: vi.fn() }),
}))

const baseProps = {
  company: { id: 'company-1', name: 'CBB Auctions', industry: 'Collectibles', website_url: null },
  integrations: [],
  membership_role: 'owner',
  instagram_account: null,
  meta_channels: [],
  wordpress_channel: null,
  email_channel: null,
}

describe('Settings — Email (Postmark)', () => {
  afterEach(() => {
    postMock.mockClear()
    routerPostMock.mockClear()
    nextFormErrors = {}
  })

  it('shows the connect form when no email channel is connected', () => {
    const wrapper = mount(Settings, { props: baseProps })

    expect(wrapper.text()).toContain('Connect Postmark')
    expect(wrapper.find('#email-api-token').exists()).toBe(true)
    expect(wrapper.find('#email-from-email').exists()).toBe(true)
    // Disconnected state: no status/disconnect/test-send UI yet.
    expect(wrapper.text()).not.toContain('Send test')
  })

  it('shows connected status, sender identity, and the test-send control once connected', () => {
    const wrapper = mount(Settings, {
      props: {
        ...baseProps,
        email_channel: {
          provider_type: 'postmark',
          from_email: 'hello@cbb-auctions.example',
          from_name: 'CBB Auctions',
          status: 'active',
          last_used_at: '2026-07-15T00:00:00Z',
        },
      },
    })

    expect(wrapper.text()).toContain('Postmark')
    expect(wrapper.text()).toContain('CBB Auctions <hello@cbb-auctions.example>')
    expect(wrapper.text()).toContain('Status: active')
    expect(wrapper.text()).toContain('Send test')
    expect(wrapper.text()).toContain('Disconnect')
    // The connect form must not still be shown once connected.
    expect(wrapper.find('#email-api-token').exists()).toBe(false)
  })

  it('shows an error state distinctly from active', () => {
    const wrapper = mount(Settings, {
      props: {
        ...baseProps,
        email_channel: {
          provider_type: 'postmark',
          from_email: 'hello@cbb-auctions.example',
          from_name: '',
          status: 'error',
          last_used_at: null,
        },
      },
    })

    expect(wrapper.text()).toContain('Status: error')
  })

  it('displays a validation error message without ever showing the submitted token', async () => {
    nextFormErrors = { api_token: "Couldn't connect to Postmark with that token: Invalid server API token" }

    const wrapper = mount(Settings, { props: baseProps })

    expect(wrapper.text()).toContain("Couldn't connect to Postmark with that token: Invalid server API token")
  })

  it('submits the connect form to the email connect endpoint', async () => {
    const wrapper = mount(Settings, { props: baseProps })

    await wrapper.find('#email-api-token').setValue('server-token-abc')
    await wrapper.find('#email-from-email').setValue('hello@cbb-auctions.example')

    const forms = wrapper.findAll('form')
    const connectForm = forms.find((f) => f.find('#email-api-token').exists())
    await connectForm?.trigger('submit.prevent')

    expect(postMock).toHaveBeenCalledWith('/app/settings/email/connect', expect.anything())
  })

  it('submits a test send with the entered recipient', async () => {
    const wrapper = mount(Settings, {
      props: {
        ...baseProps,
        email_channel: {
          provider_type: 'postmark',
          from_email: 'hello@cbb-auctions.example',
          from_name: 'CBB Auctions',
          status: 'active',
          last_used_at: null,
        },
      },
    })

    await wrapper.find('#email-test-to').setValue('owner@example.com')

    const forms = wrapper.findAll('form')
    const testForm = forms.find((f) => f.find('#email-test-to').exists())
    await testForm?.trigger('submit.prevent')

    expect(postMock).toHaveBeenCalledWith('/app/settings/email/test', expect.anything())
  })

  it('disconnects via the router, not a form post', async () => {
    const wrapper = mount(Settings, {
      props: {
        ...baseProps,
        email_channel: {
          provider_type: 'postmark',
          from_email: 'hello@cbb-auctions.example',
          from_name: '',
          status: 'active',
          last_used_at: null,
        },
      },
    })

    // Scope to the Email card specifically — Meta/WordPress each render
    // their own "Disconnect" button too.
    const emailHeading = wrapper.findAll('h2').find((h) => h.text() === 'Email')
    const emailCard = emailHeading?.element.closest('div.rounded-xl')
    expect(emailCard).toBeTruthy()

    const disconnectButton = Array.from(emailCard?.querySelectorAll('button') ?? []).find(
      (b) => b.textContent?.trim() === 'Disconnect',
    )
    expect(disconnectButton).toBeTruthy()

    await disconnectButton?.dispatchEvent(new Event('click'))

    expect(routerPostMock).toHaveBeenCalledWith('/app/settings/email/revoke', {}, expect.anything())
  })
})

describe('Settings — WordPress and Meta remain unchanged', () => {
  afterEach(() => {
    postMock.mockClear()
    routerPostMock.mockClear()
    nextFormErrors = {}
  })

  it('still shows the WordPress connect form when disconnected', () => {
    const wrapper = mount(Settings, { props: baseProps })

    expect(wrapper.text()).toContain('Connect WordPress')
    expect(wrapper.find('#wordpress-site-url').exists()).toBe(true)
  })

  it('still shows the connected WordPress channel', () => {
    const wrapper = mount(Settings, {
      props: {
        ...baseProps,
        wordpress_channel: { name: 'blog.example.com', site_url: 'https://blog.example.com', status: 'active' },
      },
    })

    expect(wrapper.text()).toContain('blog.example.com')
    expect(wrapper.text()).toContain('Status: active')
  })

  it('still shows the Meta connect link when disconnected', () => {
    const wrapper = mount(Settings, { props: baseProps })

    expect(wrapper.text()).toContain('Connect Instagram & Facebook')
  })

  it('still shows connected Meta channels', () => {
    const wrapper = mount(Settings, {
      props: {
        ...baseProps,
        meta_channels: [{ type: 'facebook', name: 'CBB Auctions', status: 'active' }],
      },
    })

    expect(wrapper.text()).toContain('CBB Auctions')
    expect(wrapper.text()).toContain('Facebook')
  })
})
