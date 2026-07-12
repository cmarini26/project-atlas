<script setup lang="ts">
import { nextTick, ref, watch } from 'vue'

const props = defineProps<{
  open: boolean
  title: string
  confirmLabel: string
  cancelLabel?: string
  processing?: boolean
}>()

const emit = defineEmits<{
  confirm: []
  cancel: []
}>()

const confirmButton = ref<HTMLButtonElement | null>(null)

watch(
  () => props.open,
  async (open) => {
    if (open) {
      await nextTick()
      confirmButton.value?.focus()
    }
  },
)

function onKeydown(event: KeyboardEvent): void {
  if (event.key === 'Escape' && !props.processing) {
    emit('cancel')
  }
}
</script>

<template>
  <Teleport to="body">
    <div
      v-if="open"
      class="fixed inset-0 z-50 flex items-end sm:items-center justify-center p-4"
      role="dialog"
      aria-modal="true"
      :aria-label="title"
      @keydown="onKeydown"
    >
      <!-- Overlay -->
      <div
        class="absolute inset-0 bg-[var(--color-surface-overlay)] opacity-50"
        aria-hidden="true"
        @click="!processing && emit('cancel')"
      />

      <!-- Panel -->
      <div class="relative w-full max-w-md bg-[var(--color-surface-elevated)] border border-[var(--color-border)] rounded-xl shadow-lg p-5">
        <h2 class="text-sm font-semibold text-[var(--color-text-primary)] mb-2">{{ title }}</h2>

        <div class="text-sm text-[var(--color-text-secondary)] mb-5">
          <slot />
        </div>

        <div class="flex flex-col-reverse sm:flex-row sm:justify-end gap-2">
          <button
            type="button"
            :disabled="processing"
            class="py-2 px-4 text-sm font-medium rounded-lg border border-[var(--color-border)] text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-subtle)] disabled:opacity-60 disabled:cursor-not-allowed transition-colors duration-[var(--duration-fast)]"
            @click="emit('cancel')"
          >
            {{ cancelLabel ?? 'Cancel' }}
          </button>
          <button
            ref="confirmButton"
            type="button"
            :disabled="processing"
            class="py-2 px-4 text-sm font-medium rounded-lg bg-[var(--color-accent-500)] text-white hover:bg-[var(--color-accent-600)] disabled:opacity-60 disabled:cursor-not-allowed transition-colors duration-[var(--duration-fast)]"
            @click="emit('confirm')"
          >
            {{ confirmLabel }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>
