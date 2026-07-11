import { onBeforeUnmount, ref, type Ref } from 'vue'
import { useIntersectionObserver, useMediaQuery } from '@vueuse/core'

/**
 * Scroll-triggered reveal used across the marketing landing page (see
 * docs/marketing/Landing-Page.md §21). Fires once, at 15% visibility, and
 * resolves immediately (no motion) when the visitor has asked for reduced
 * motion — per docs/design/System.md §20, "Motion preferences."
 */
export function useScrollReveal(threshold = 0.15): { target: Ref<HTMLElement | null>; isVisible: Ref<boolean> } {
  const prefersReducedMotion = useMediaQuery('(prefers-reduced-motion: reduce)')
  const target = ref<HTMLElement | null>(null)
  const isVisible = ref(prefersReducedMotion.value)

  if (!prefersReducedMotion.value) {
    const { stop } = useIntersectionObserver(
      target,
      ([entry]) => {
        if (entry?.isIntersecting) {
          isVisible.value = true
          stop()
        }
      },
      { threshold },
    )

    onBeforeUnmount(stop)
  }

  return { target, isVisible }
}
