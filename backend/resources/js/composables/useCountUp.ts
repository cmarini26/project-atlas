import { ref, watch, onBeforeUnmount, type Ref } from 'vue'
import { useMediaQuery } from '@vueuse/core'

/**
 * Counts a displayed number up from 0 to `target` once `trigger` becomes
 * true (used for the Learning Over Time stat comparison — see
 * docs/marketing/Landing-Page.md §21). Jumps straight to the final value
 * when the visitor prefers reduced motion.
 */
export function useCountUp(target: number, trigger: Ref<boolean>, durationMs = 800): Ref<number> {
  const prefersReducedMotion = useMediaQuery('(prefers-reduced-motion: reduce)')
  const value = ref(0)
  let frame: number | null = null

  function animate(): void {
    if (prefersReducedMotion.value) {
      value.value = target
      return
    }

    const start = performance.now()

    function step(now: number): void {
      const elapsed = now - start
      const progress = Math.min(elapsed / durationMs, 1)
      value.value = Math.round(target * progress)

      if (progress < 1) {
        frame = requestAnimationFrame(step)
      }
    }

    frame = requestAnimationFrame(step)
  }

  watch(
    trigger,
    (isTriggered) => {
      if (isTriggered) {
        animate()
      }
    },
    { immediate: true },
  )

  onBeforeUnmount(() => {
    if (frame !== null) {
      cancelAnimationFrame(frame)
    }
  })

  return value
}
