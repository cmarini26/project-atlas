import { reactive, readonly } from 'vue'
import { router } from '@inertiajs/vue3'

const SESSION_SUPPRESS_KEY = 'atlas.feedback.suppressed'

interface FeedbackState {
  isOpen: boolean
  submitting: boolean
}

const state = reactive<FeedbackState>({
  isOpen: false,
  submitting: false,
})

function wasSuppressedThisSession(): boolean {
  return sessionStorage.getItem(SESSION_SUPPRESS_KEY) === '1'
}

function open(): void {
  if (wasSuppressedThisSession()) return
  state.isOpen = true
}

// A bare close (no score submitted) only suppresses the prompt for the rest
// of this browser tab's session — the roadmap's 90-day non-repeat window is
// tied to having *submitted* feedback, not to every dismissal, so persisting
// a long suppression here would be wrong.
function close(): void {
  state.isOpen = false
  sessionStorage.setItem(SESSION_SUPPRESS_KEY, '1')
}

function submit(score: number, comment: string): void {
  if (state.submitting) return
  state.submitting = true

  router.post(
    '/app/feedback',
    { score, comment: comment || null },
    {
      preserveScroll: true,
      preserveState: true,
      onFinish: () => {
        state.submitting = false
        state.isOpen = false
      },
    },
  )
}

/**
 * Module-scoped feedback-prompt store: one shared instance per browser tab,
 * following the same pattern as useToasts.ts/useProductTour.ts, so state
 * survives Inertia navigations under the persistent AppLayout.
 */
export function useFeedback() {
  return {
    state: readonly(state),
    open,
    close,
    submit,
  }
}
