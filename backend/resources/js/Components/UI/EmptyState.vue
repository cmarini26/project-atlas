<script setup lang="ts">
defineProps<{
  title: string
  description?: string
}>()
</script>

<template>
  <div class="flex flex-col items-center justify-center py-16 px-4 text-center">
    <div class="size-12 rounded-full bg-[var(--color-surface-subtle)] flex items-center justify-center mb-4">
      <slot name="icon">
        <svg class="size-6 text-[var(--color-text-placeholder)]" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 12a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0ZM12.75 12a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0ZM18.75 12a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Z" /></svg>
      </slot>
    </div>
    <p class="text-sm font-medium text-[var(--color-text-secondary)]">{{ title }}</p>
    <p v-if="description" class="mt-1 text-sm text-[var(--color-text-muted)] max-w-xs">{{ description }}</p>
    <div v-if="$slots.action" class="mt-4">
      <slot name="action" />
    </div>
  </div>
</template>
