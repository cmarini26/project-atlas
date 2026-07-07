import { afterEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import CompanySwitcher from './CompanySwitcher.vue'
import type { Company, CompanyOption } from '@/types'

const postMock = vi.fn()

vi.mock('@inertiajs/vue3', () => ({
  router: { post: (...args: unknown[]) => postMock(...args) },
}))

const company: Company = { id: 'co-1', name: 'CBB Auctions' }

const companies: CompanyOption[] = [
  { id: 'co-1', name: 'CBB Auctions' },
  { id: 'co-2', name: 'Euro Classix' },
]

describe('CompanySwitcher', () => {
  afterEach(() => {
    postMock.mockClear()
    document.body.innerHTML = ''
  })

  it('lists only the other companies, not the current one', async () => {
    const wrapper = mount(CompanySwitcher, {
      props: { company, companies },
      attachTo: document.body,
    })

    await wrapper.find('button[aria-haspopup="menu"]').trigger('click')

    const menuText = document.body.textContent ?? ''
    expect(menuText).toContain('Euro Classix')
    expect(menuText.match(/CBB Auctions/g)?.length).toBe(1) // only the trigger label

    wrapper.unmount()
  })

  it('posts to /company/select with the chosen company id', async () => {
    const wrapper = mount(CompanySwitcher, {
      props: { company, companies },
      attachTo: document.body,
    })

    await wrapper.find('button[aria-haspopup="menu"]').trigger('click')

    const option = Array.from(document.querySelectorAll('[role="menuitem"]')).find(
      (el) => el.textContent?.trim() === 'Euro Classix',
    )
    option?.dispatchEvent(new Event('click', { bubbles: true }))
    await wrapper.vm.$nextTick()

    expect(postMock).toHaveBeenCalledWith(
      '/company/select',
      { company_id: 'co-2' },
      expect.objectContaining({ onFinish: expect.any(Function) }),
    )

    wrapper.unmount()
  })

  it('does not render a switcher menu item for the current company', async () => {
    const wrapper = mount(CompanySwitcher, {
      props: { company, companies: [{ id: 'co-1', name: 'CBB Auctions' }] },
      attachTo: document.body,
    })

    await wrapper.find('button[aria-haspopup="menu"]').trigger('click')

    expect(document.querySelectorAll('[role="menuitem"]')).toHaveLength(0)

    wrapper.unmount()
  })
})
