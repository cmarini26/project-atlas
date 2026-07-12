import { afterEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import OnboardingChecklist from './OnboardingChecklist.vue'

const postMock = vi.fn()

vi.mock('@inertiajs/vue3', () => ({
  Link: { template: '<a><slot /></a>' },
  router: {
    post: (...args: unknown[]) => postMock(...args),
  },
}))

describe('OnboardingChecklist', () => {
  afterEach(() => {
    postMock.mockClear()
  })

  it('renders all 3 checklist items', () => {
    const wrapper = mount(OnboardingChecklist)

    expect(wrapper.text()).toContain('Review your first recommendation')
    expect(wrapper.text()).toContain('Explore your Business Brain')
    expect(wrapper.text()).toContain('Review your marketing presence')
  })

  it('posts to the dismiss endpoint when the dismiss button is clicked', async () => {
    const wrapper = mount(OnboardingChecklist)

    await wrapper.find('button[aria-label="Dismiss checklist"]').trigger('click')

    expect(postMock).toHaveBeenCalledWith('/checklist/dismiss', {}, expect.any(Object))
  })
})
