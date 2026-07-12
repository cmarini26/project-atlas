import { reactive, readonly } from 'vue'
import { router } from '@inertiajs/vue3'
import { PRODUCT_TOUR_STEPS } from '@/lib/productTourSteps'

interface ProductTourState {
  isActive: boolean
  currentStepIndex: number
  pendingStart: boolean
}

const state = reactive<ProductTourState>({
  isActive: false,
  currentStepIndex: 0,
  pendingStart: false,
})

function startTour(): void {
  state.isActive = true
  state.currentStepIndex = 0
  state.pendingStart = false
}

function stopTour(): void {
  state.isActive = false
}

function nextStep(): void {
  if (state.currentStepIndex >= PRODUCT_TOUR_STEPS.length - 1) {
    completeTour()
    return
  }
  state.currentStepIndex++
}

function prevStep(): void {
  if (state.currentStepIndex > 0) state.currentStepIndex--
}

function completeTour(): void {
  state.isActive = false
  router.post('/app/tour/complete', {}, { preserveScroll: true, preserveState: true })
}

/** Sets a flag AppLayout checks on the next Dashboard mount, so relaunching
 * the tour from Settings doesn't depend on a post-navigation callback race. */
function requestTourStart(): void {
  state.pendingStart = true
}

/**
 * Module-scoped tour store: one shared instance per browser tab, following
 * the same pattern as useToasts.ts, so the tour's active/step state
 * survives Inertia navigations under the persistent AppLayout.
 */
export function useProductTour() {
  return {
    state: readonly(state),
    steps: PRODUCT_TOUR_STEPS,
    startTour,
    stopTour,
    nextStep,
    prevStep,
    completeTour,
    requestTourStart,
  }
}
