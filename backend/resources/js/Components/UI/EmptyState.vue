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
        <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 12a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0ZM12.75 12a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0ZM18.75 12a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Z" /></svg>
      </slot>
    </div>
    <p class="text-sm font-medium text-[var(--color-text-secondary)]">{{ title }}</p>
    <p v-if="description" class="mt-1 text-sm text-[var(--color-text-muted)] max-w-xs">{{ description }}</p>
    <div v-if="$slots.action" class="mt-4">
      <slot name="action" />
    </div>
  </div>
</template>
