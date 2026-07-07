<script setup lang="ts">
import { useToasts } from '@/composables/useToasts'

const { toasts, dismissToast } = useToasts()
</script>

<template>
  <div
    class="fixed bottom-4 right-4 z-[60] flex flex-col gap-2 w-80 max-w-[calc(100vw-2rem)]"
    aria-live="polite"
  >
    <TransitionGroup
      enter-active-class="transition duration-[var(--duration-smooth)] ease-[var(--ease-out)]"
      enter-from-class="opacity-0 translate-y-2"
      enter-to-class="opacity-100 translate-y-0"
      leave-active-class="transition duration-[var(--duration-base)]"
      leave-from-class="opacity-100"
      leave-to-class="opacity-0"
    >
      <div
        v-for="toast in toasts"
        :key="toast.id"
        :class="[
          'flex items-start gap-3 p-3.5 rounded-lg border shadow-sm text-sm',
          toast.type === 'success'
            ? 'bg-[var(--color-success-surface)] border-[var(--color-success-border)] text-[var(--color-success-text)]'
            : 'bg-[var(--color-danger-surface)] border-[var(--color-danger-border)] text-[var(--color-danger-text)]',
        ]"
        :role="toast.type === 'error' ? 'alert' : 'status'"
      >
        <svg v-if="toast.type === 'success'" class="size-4 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
        <svg v-else class="size-4 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" /></svg>

        <p class="flex-1 min-w-0">{{ toast.message }}</p>

        <button
          type="button"
          class="shrink-0 opacity-60 hover:opacity-100 transition-opacity duration-[var(--duration-fast)]"
          aria-label="Dismiss"
          @click="dismissToast(toast.id)"
        >
          <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
        </button>
      </div>
    </TransitionGroup>
  </div>
</template>
