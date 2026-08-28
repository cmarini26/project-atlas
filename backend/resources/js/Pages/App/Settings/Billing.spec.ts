import { afterEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import Billing from './Billing.vue'

const postMock = vi.fn()

vi.mock('@inertiajs/vue3', () => ({
  Head: { template: '<head><slot /></head>' },
  Link: { template: '<a><slot /></a>', props: ['href'] },
  router: { post: (url: string, data?: unknown, opts?: unknown) => postMock(url, data, opts) },
}))

const baseBilling = {
  has_customer: false,
  has_subscription: false,
  status: null as string | null,
  price_id: null as string | null,
  current_period_ends_at: null as string | null,
  cancel_at_period_end: false,
  beta_access_override: false,
  grants_access: false,
}

function mountBilling(overrides: Partial<Record<string, unknown>> = {}) {
  return mount(Billing, {
    props: {
      billing: { ...baseBilling, ...(overrides.billing as object ?? {}) },
      checkout_available: overrides.checkout_available ?? true,
      can_manage: overrides.can_manage ?? true,
      checkout_result: overrides.checkout_result ?? null,
    } as never,
    global: { stubs: { AppLayout: { template: '<main><slot /></main>' } } },
  })
}

describe('Settings/Billing', () => {
  afterEach(() => postMock.mockClear())

  it('offers Subscribe (only) when there is no subscription and the manager can act', () => {
    const wrapper = mountBilling()
    const buttons = wrapper.findAll('button').map((b) => b.text())

    expect(buttons).toContain('Subscribe')
    expect(buttons).not.toContain('Manage billing')
    expect(wrapper.text()).toContain('No subscription')
  })

  it('posts to the checkout route when Subscribe is clicked', async () => {
    const wrapper = mountBilling()
    await wrapper.findAll('button').find((b) => b.text() === 'Subscribe')!.trigger('click')

    expect(postMock).toHaveBeenCalledWith('/app/settings/billing/checkout', {}, expect.anything())
  })

  it('shows Manage billing and an active status once subscribed', () => {
    const wrapper = mountBilling({
      billing: { has_customer: true, has_subscription: true, status: 'active', grants_access: true, current_period_ends_at: '2027-06-01T00:00:00Z' },
    })

    expect(wrapper.text()).toContain('Active')
    expect(wrapper.text()).toContain('Renews')
    expect(wrapper.findAll('button').map((b) => b.text())).toContain('Manage billing')
  })

  it('warns on past_due and notes a pending cancellation', () => {
    expect(mountBilling({ billing: { has_subscription: true, has_customer: true, status: 'past_due' } }).text())
      .toContain("last payment didn't go through")

    expect(mountBilling({ billing: { has_subscription: true, has_customer: true, status: 'active', cancel_at_period_end: true } }).text())
      .toContain('set to cancel at the end')
  })

  it('tells non-managers they cannot change billing and hides the buttons', () => {
    const wrapper = mountBilling({ can_manage: false })

    expect(wrapper.text()).toContain('Only company owners and admins can change billing.')
    expect(wrapper.findAll('button').length).toBe(0)
  })

  it('surfaces the post-checkout success banner', () => {
    expect(mountBilling({ checkout_result: 'success' }).text()).toContain('your subscription is being set up')
  })
})
