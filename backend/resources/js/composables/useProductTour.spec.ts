import { afterEach, describe, expect, it, vi } from 'vitest'
import { useProductTour } from './useProductTour'

const postMock = vi.fn()

vi.mock('@inertiajs/vue3', () => ({
  router: {
    post: (...args: unknown[]) => postMock(...args),
  },
}))

describe('useProductTour', () => {
  afterEach(() => {
    postMock.mockClear()
    useProductTour().stopTour()
  })

  it('starts at step 0 and marks the tour active', () => {
    const { state, startTour } = useProductTour()

    startTour()

    expect(state.isActive).toBe(true)
    expect(state.currentStepIndex).toBe(0)
  })

  it('advances through steps with nextStep and stays within bounds going back', () => {
    const { state, startTour, nextStep, prevStep } = useProductTour()

    startTour()
    nextStep()
    expect(state.currentStepIndex).toBe(1)

    prevStep()
    prevStep()
    expect(state.currentStepIndex).toBe(0)
  })

  it('completes the tour when nextStep is called on the last step', () => {
    const { state, steps, startTour, nextStep } = useProductTour()

    startTour()
    for (let i = 0; i < steps.length - 1; i++) nextStep()
    expect(state.isActive).toBe(true)

    nextStep()
    expect(state.isActive).toBe(false)
  })

  it('completeTour posts to the completion endpoint and deactivates immediately', () => {
    const { state, startTour, completeTour } = useProductTour()

    startTour()
    completeTour()

    expect(state.isActive).toBe(false)
    expect(postMock).toHaveBeenCalledWith('/app/tour/complete', {}, expect.any(Object))
  })

  it('requestTourStart sets pendingStart, and startTour clears it', () => {
    const { state, requestTourStart, startTour } = useProductTour()

    requestTourStart()
    expect(state.pendingStart).toBe(true)

    startTour()
    expect(state.pendingStart).toBe(false)
  })
})
