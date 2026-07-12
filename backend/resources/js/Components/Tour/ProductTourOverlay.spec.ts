import { afterEach, describe, expect, it, vi } from 'vitest'
import { mount, type VueWrapper } from '@vue/test-utils'
import ProductTourOverlay from './ProductTourOverlay.vue'
import { useProductTour } from '@/composables/useProductTour'

vi.mock('@inertiajs/vue3', () => ({
  router: { post: vi.fn() },
}))

let wrapper: VueWrapper | undefined

describe('ProductTourOverlay', () => {
  // Teleports to the real document.body regardless of the wrapper's mount
  // target — unmount before clearing the body so Vue doesn't try to patch
  // DOM nodes that were already wiped out from under it.
  afterEach(() => {
    wrapper?.unmount()
    wrapper = undefined
    useProductTour().stopTour()
    document.body.innerHTML = ''
  })

  it('renders nothing when the tour is inactive', () => {
    wrapper = mount(ProductTourOverlay, { attachTo: document.body })

    expect(document.body.querySelector('[role="dialog"]')).toBeNull()
  })

  it('renders the first step once the tour is started', () => {
    const { startTour } = useProductTour()
    startTour()

    wrapper = mount(ProductTourOverlay, { attachTo: document.body })

    expect(document.body.querySelector('[role="dialog"]')).not.toBeNull()
    const text = document.body.textContent ?? ''
    expect(text).toContain('Your next recommendation')
    expect(text).toContain('1 of 4')
  })

  it('does not show a Back button on the first step', () => {
    const { startTour } = useProductTour()
    startTour()

    wrapper = mount(ProductTourOverlay, { attachTo: document.body })

    expect(document.body.textContent ?? '').not.toContain('Back')
  })

  it('advances to the next step and shows Back once past the first step', async () => {
    const { startTour } = useProductTour()
    startTour()

    wrapper = mount(ProductTourOverlay, { attachTo: document.body })
    const nextButton = Array.from(document.body.querySelectorAll('button')).find((b) => b.textContent?.trim() === 'Next')
    nextButton?.dispatchEvent(new Event('click'))
    await new Promise((resolve) => setTimeout(resolve, 0))

    const text = document.body.textContent ?? ''
    expect(text).toContain('2 of 4')
    expect(text).toContain('Back')
  })

  it('shows "Done" instead of "Next" on the last step', () => {
    const { startTour, nextStep } = useProductTour()
    startTour()
    nextStep()
    nextStep()
    nextStep()

    wrapper = mount(ProductTourOverlay, { attachTo: document.body })

    const text = document.body.textContent ?? ''
    expect(text).toContain('4 of 4')
    expect(text).toContain('Done')
  })

  it('closes the overlay when Skip is clicked', async () => {
    const { state, startTour } = useProductTour()
    startTour()

    wrapper = mount(ProductTourOverlay, { attachTo: document.body })
    const skipButton = Array.from(document.body.querySelectorAll('button')).find((b) => b.textContent?.trim() === 'Skip')
    skipButton?.dispatchEvent(new Event('click'))
    await new Promise((resolve) => setTimeout(resolve, 0))

    expect(state.isActive).toBe(false)
  })
})
