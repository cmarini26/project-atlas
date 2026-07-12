<script setup lang="ts">
withDefaults(defineProps<{
  title: string
  description?: string
  variant?: 'default' | 'accent' | 'success' | 'warning' | 'info'
}>(), {
  variant: 'default',
})

const variantClasses: Record<string, string> = {
  default: 'bg-[var(--color-surface-subtle)] text-[var(--color-text-placeholder)]',
  accent: 'bg-[var(--color-accent-50)] text-[var(--color-accent-600)]',
  success: 'bg-[var(--color-success-surface)] text-[var(--color-success-text)]',
  warning: 'bg-[var(--color-warning-surface)] text-[var(--color-warning-text)]',
  info: 'bg-[var(--color-info-surface)] text-[var(--color-info-text)]',
}
</script>

<template>
  <div class="flex flex-col items-center justify-center py-16 px-4 text-center">
    <div :class="['size-12 rounded-full flex items-center justify-center mb-4', variantClasses[variant]]">
      <slot name="icon">
        <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 0 0-2.456 2.456Z" /></svg>
      </slot>
    </div>
    <p class="text-sm font-medium text-[var(--color-text-secondary)]">{{ title }}</p>
    <p v-if="description" class="mt-1 text-sm text-[var(--color-text-muted)] max-w-xs">{{ description }}</p>
    <div v-if="$slots.action" class="mt-4">
      <slot name="action" />
    </div>
  </div>
</template>
