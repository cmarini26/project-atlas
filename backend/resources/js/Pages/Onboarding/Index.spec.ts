import { afterEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { reactive } from 'vue'
import Index from './Index.vue'

const postMock = vi.fn((url: string, options?: { onSuccess?: () => void }) => {
  options?.onSuccess?.()
})

vi.mock('@inertiajs/vue3', () => ({
  Head: { template: '<head><slot /></head>' },
  useForm: (initial: Record<string, unknown>) =>
    reactive({
      ...initial,
      processing: false,
      errors: {},
      post: (url: string, options?: { onSuccess?: () => void }) => postMock(url, options),
    }),
}))

const baseProps = { enabled_assets: [] }

describe('Onboarding/Index', () => {
  afterEach(() => {
    postMock.mockClear()
  })

  it('renders the Welcome step with the exact copy', () => {
    const wrapper = mount(Index, { props: { ...baseProps, initial_step: 1 } })

    expect(wrapper.text()).toContain('Welcome to Atlas')
    expect(wrapper.text()).toContain("Let's teach Atlas about your business")
    expect(wrapper.find('button').text()).toBe("Let's Begin")
  })

  it('advances from Welcome to Company without a server request', async () => {
    const wrapper = mount(Index, { props: { ...baseProps, initial_step: 1 } })

    await wrapper.find('button').trigger('click')

    expect(postMock).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('Tell us about your business')
  })

  it('renders the Company step fields and submits to /onboarding/company', async () => {
    const wrapper = mount(Index, { props: { ...baseProps, initial_step: 2 } })

    expect(wrapper.find('#company-name').exists()).toBe(true)
    expect(wrapper.find('#industry').exists()).toBe(true)
    expect(wrapper.find('#description').exists()).toBe(true)

    await wrapper.find('form').trigger('submit.prevent')

    expect(postMock).toHaveBeenCalledWith('/onboarding/company', expect.anything())
  })

  it('renders every Business Goals option and toggles selection', async () => {
    const wrapper = mount(Index, { props: { ...baseProps, initial_step: 3 } })

    expect(wrapper.text()).toContain('What would you like Atlas to help you accomplish?')
    expect(wrapper.text()).toContain('Generate leads')
    expect(wrapper.text()).toContain('Other')

    const checkboxes = wrapper.findAll('input[type="checkbox"]')
    expect(checkboxes.length).toBe(8)

    await wrapper.find('form').trigger('submit.prevent')
    expect(postMock).toHaveBeenCalledWith('/onboarding/goals', expect.anything())
  })

  it('renders all ten Marketing Assets cards', () => {
    const wrapper = mount(Index, { props: { ...baseProps, initial_step: 4 } })

    expect(wrapper.text()).toContain('Where can customers find your business?')
    for (const label of ['Website', 'Google Business Profile', 'Instagram', 'Facebook', 'LinkedIn', 'X', 'YouTube', 'Email Newsletter', 'Events', 'Print']) {
      expect(wrapper.text()).toContain(label)
    }
  })

  it('does not ask the user to pick primary assets — it is auto-defaulted', async () => {
    // Milestone: UI rethink Workstream C.2 — picking "primary" assets was
    // removed from onboarding entirely; it's auto-defaulted server-side
    // from a fixed priority order and editable later from Settings.
    const wrapper = mount(Index, { props: { ...baseProps, initial_step: 4 } })

    expect(wrapper.text()).not.toContain('Primary')
    expect(wrapper.text()).not.toContain('mark up to three as primary')

    const enableCheckboxes = wrapper.findAll('input[type="checkbox"]')
    for (let i = 0; i < 4; i++) {
      await enableCheckboxes[i].setValue(true)
    }

    await wrapper.find('form').trigger('submit.prevent')
    expect(postMock).toHaveBeenCalledWith('/onboarding/assets', expect.anything())
  })

  it('only renders a detail form for Website — every other asset is deferred to Settings', () => {
    const wrapper = mount(Index, {
      props: {
        ...baseProps,
        initial_step: 5,
        enabled_assets: [
          { type: 'website', label: 'Website', importance: 'primary', handle_or_url: null, metadata: {} },
          { type: 'instagram', label: 'Instagram', importance: 'secondary', handle_or_url: null, metadata: {} },
        ],
      },
    })

    expect(wrapper.text()).toContain('Tell us a bit more about your website')
    expect(wrapper.find('#detail-website-url').exists()).toBe(true)
    expect(wrapper.find('#detail-website-platform').exists()).toBe(true)
    expect(wrapper.find('#detail-instagram-url').exists()).toBe(false)
    expect(wrapper.find('#detail-facebook-url').exists()).toBe(false)
  })

  it('seeds asset detail fields from previously-saved values', () => {
    const wrapper = mount(Index, {
      props: {
        ...baseProps,
        initial_step: 5,
        enabled_assets: [
          { type: 'website', label: 'Website', importance: 'primary', handle_or_url: 'https://acme.com', metadata: { platform: 'shopify' } },
        ],
      },
    })

    const urlInput = wrapper.find('#detail-website-url').element as HTMLInputElement
    expect(urlInput.value).toBe('https://acme.com')
  })

  it('submits asset details to /onboarding/asset-details', async () => {
    const wrapper = mount(Index, {
      props: {
        ...baseProps,
        initial_step: 5,
        enabled_assets: [
          { type: 'email', label: 'Email Newsletter', importance: 'secondary', handle_or_url: null, metadata: {} },
        ],
      },
    })

    await wrapper.find('form').trigger('submit.prevent')

    expect(postMock).toHaveBeenCalledWith('/onboarding/asset-details', expect.anything())
  })

  it('shows the seasonal month picker only when seasonal is Yes', async () => {
    const wrapper = mount(Index, { props: { ...baseProps, initial_step: 6 } })

    expect(wrapper.text()).not.toContain('Which months?')

    const yesButton = wrapper.findAll('button').find((b) => b.text() === 'Yes')
    await yesButton?.trigger('click')

    expect(wrapper.text()).toContain('Which months?')
    expect(wrapper.text()).toContain('January')
  })

  it('renders the Discovery Placeholder step with the exact copy', () => {
    const wrapper = mount(Index, { props: { ...baseProps, initial_step: 7 } })

    expect(wrapper.text()).toContain('Atlas is ready to learn about your business.')
    expect(wrapper.text()).toContain('The next phase will begin discovering your marketing assets')
    expect(wrapper.find('button').text()).toBe('Start Discovery')
  })

  it('submits to /onboarding/finish when Start Discovery is clicked', async () => {
    const wrapper = mount(Index, { props: { ...baseProps, initial_step: 7 } })

    await wrapper.find('button').trigger('click')

    expect(postMock.mock.calls[0][0]).toBe('/onboarding/finish')
  })

  it('shows a Back button on every step after Welcome', () => {
    for (const step of [3, 4, 5, 6] as const) {
      const wrapper = mount(Index, { props: { ...baseProps, initial_step: step } })
      expect(wrapper.findAll('button').some((b) => b.text() === 'Back')).toBe(true)
    }
  })
})
