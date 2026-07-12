<script setup lang="ts">
import { ref, watch } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { useFeedback } from '@/composables/useFeedback'
import type { SharedProps } from '@/types'

const page = usePage<SharedProps>()
const { state, open, close, submit } = useFeedback()

watch(
  () => page.props.show_feedback_prompt,
  (shouldShow) => {
    if (shouldShow) open()
  },
  { immediate: true },
)

const score = ref<number | null>(null)
const comment = ref('')

function handleSubmit(): void {
  if (score.value === null) return
  submit(score.value, comment.value)
}
</script>

<template>
  <Teleport to="body">
    <div
      v-if="state.isOpen"
      class="fixed inset-x-0 bottom-0 z-40 flex justify-center p-4 sm:justify-end"
      role="dialog"
      aria-modal="true"
      aria-label="Share feedback"
    >
      <div class="w-full max-w-sm bg-[var(--color-surface-elevated)] border border-[var(--color-border)] rounded-xl shadow-lg p-5">
        <div class="flex items-start justify-between gap-3 mb-3">
          <h2 class="text-sm font-semibold text-[var(--color-text-primary)]">How's Atlas working for you?</h2>
          <button
            type="button"
            class="shrink-0 text-[var(--color-text-muted)] hover:text-[var(--color-text-secondary)]"
            aria-label="Dismiss"
            @click="close"
          >
            <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
          </button>
        </div>

        <p class="text-xs text-[var(--color-text-muted)] mb-3">How likely are you to recommend Atlas to another business owner? (1-10)</p>

        <div class="flex flex-wrap gap-1.5 mb-3">
          <button
            v-for="n in 10"
            :key="n"
            type="button"
            :class="[
              'size-8 rounded-lg text-xs font-medium border transition-colors duration-[var(--duration-fast)]',
              score === n
                ? 'bg-[var(--color-accent-500)] border-[var(--color-accent-500)] text-white'
                : 'border-[var(--color-border)] text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-subtle)]',
            ]"
            @click="score = n"
          >
            {{ n }}
          </button>
        </div>

        <textarea
          v-model="comment"
          rows="2"
          maxlength="500"
          placeholder="Anything you'd like us to know? (optional)"
          class="w-full px-3 py-2 text-sm rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] text-[var(--color-text-primary)] resize-none mb-3 focus:outline-none focus:ring-1 focus:ring-[var(--color-border-focus)]"
        />

        <button
          type="button"
          :disabled="score === null || state.submitting"
          class="w-full py-2 px-4 text-sm font-medium rounded-lg bg-[var(--color-accent-500)] text-white hover:bg-[var(--color-accent-600)] disabled:opacity-60 disabled:cursor-not-allowed transition-colors duration-[var(--duration-fast)]"
          @click="handleSubmit"
        >
          {{ state.submitting ? 'Sending…' : 'Send feedback' }}
        </button>
      </div>
    </div>
  </Teleport>
</template>
