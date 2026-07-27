<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useProductTour } from '@/composables/useProductTour'

const { state, steps, nextStep, prevStep, completeTour } = useProductTour()

const targetRect = ref<DOMRect | null>(null)
const card = ref<HTMLDivElement | null>(null)
let resizeTimer: ReturnType<typeof setTimeout> | null = null

const currentStep = computed(() => steps[state.currentStepIndex] ?? null)
const isFirstStep = computed(() => state.currentStepIndex === 0)
const isLastStep = computed(() => state.currentStepIndex === steps.length - 1)

function measureTarget(): void {
  if (!currentStep.value) {
    targetRect.value = null
    return
  }

  const el = document.querySelector(currentStep.value.target)
  targetRect.value = el ? el.getBoundingClientRect() : null
}

function onReposition(): void {
  if (resizeTimer) clearTimeout(resizeTimer)
  resizeTimer = setTimeout(measureTarget, 100)
}

watch(
  () => [state.isActive, state.currentStepIndex],
  async () => {
    if (!state.isActive) return
    await nextTick()
    measureTarget()
  },
  { immediate: true },
)

onMounted(() => {
  measureTarget()
  window.addEventListener('resize', onReposition)
  window.addEventListener('scroll', onReposition, true)
})

onBeforeUnmount(() => {
  window.removeEventListener('resize', onReposition)
  window.removeEventListener('scroll', onReposition, true)
  if (resizeTimer) clearTimeout(resizeTimer)
})

const cardStyle = computed(() => {
  const viewportMargin = 16
  const targetGap = 12
  const viewportWidth = window.innerWidth
  const viewportHeight = window.innerHeight
  const cardWidth = Math.min(320, viewportWidth - viewportMargin * 2)
  const cardHeight = Math.min(
    card.value?.getBoundingClientRect().height || 208,
    viewportHeight - viewportMargin * 2,
  )

  if (!targetRect.value) {
    return { top: `${viewportMargin}px`, left: `${viewportMargin}px` }
  }

  const availableBelow = viewportHeight - targetRect.value.bottom - targetGap - viewportMargin
  const availableAbove = targetRect.value.top - targetGap - viewportMargin
  const preferredTop = availableBelow >= cardHeight || availableBelow >= availableAbove
    ? targetRect.value.bottom + targetGap
    : targetRect.value.top - targetGap - cardHeight
  const top = Math.min(
    Math.max(viewportMargin, preferredTop),
    Math.max(viewportMargin, viewportHeight - viewportMargin - cardHeight),
  )
  const left = Math.min(
    Math.max(viewportMargin, targetRect.value.left),
    Math.max(viewportMargin, viewportWidth - viewportMargin - cardWidth),
  )

  return { top: `${top}px`, left: `${left}px` }
})
</script>

<template>
  <Teleport to="body">
    <div v-if="state.isActive && currentStep" class="fixed inset-0 z-50" role="dialog" aria-modal="true" :aria-label="currentStep.title">
      <div class="absolute inset-0 bg-[var(--color-surface-overlay)] opacity-40" aria-hidden="true" />

      <div
        ref="card"
        class="absolute w-[calc(100%-2rem)] max-w-xs max-h-[calc(100dvh-2rem)] overflow-y-auto overscroll-contain bg-[var(--color-surface-elevated)] border border-[var(--color-border)] rounded-xl shadow-lg p-5"
        :style="cardStyle"
      >
        <p class="text-xs text-[var(--color-text-muted)] mb-2">{{ state.currentStepIndex + 1 }} of {{ steps.length }}</p>
        <h2 class="text-sm font-semibold text-[var(--color-text-primary)] mb-2">{{ currentStep.title }}</h2>
        <p class="text-sm text-[var(--color-text-secondary)] mb-5">{{ currentStep.body }}</p>

        <div class="flex items-center justify-between gap-2">
          <button
            type="button"
            class="text-xs text-[var(--color-text-muted)] hover:text-[var(--color-text-secondary)]"
            @click="completeTour"
          >
            Skip
          </button>

          <div class="flex gap-2">
            <button
              v-if="!isFirstStep"
              type="button"
              class="py-1.5 px-3 text-xs font-medium rounded-lg border border-[var(--color-border)] text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-subtle)] transition-colors duration-[var(--duration-fast)]"
              @click="prevStep"
            >
              Back
            </button>
            <button
              type="button"
              class="py-1.5 px-3 text-xs font-medium rounded-lg bg-[var(--color-accent-500)] text-white hover:bg-[var(--color-accent-600)] transition-colors duration-[var(--duration-fast)]"
              @click="nextStep"
            >
              {{ isLastStep ? 'Done' : 'Next' }}
            </button>
          </div>
        </div>
      </div>
    </div>
  </Teleport>
</template>
