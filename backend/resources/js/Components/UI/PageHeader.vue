<script setup lang="ts">
import type { Component } from 'vue'

defineProps<{
  title: string
  description?: string
  icon?: Component
}>()
</script>

<template>
  <div class="flex flex-col gap-4 border-b border-[var(--color-border)] pb-5 mb-6 sm:flex-row sm:items-end sm:justify-between">
    <div class="flex items-start gap-4 min-w-0">
      <div
        v-if="icon"
        class="size-11 shrink-0 rounded-[var(--radius-md)] border border-[var(--color-accent-200)] bg-[var(--color-accent-50)] text-[var(--color-accent-700)] flex items-center justify-center shadow-[var(--shadow-xs)]"
      >
        <component :is="icon" class="size-5" aria-hidden="true" />
      </div>
      <div class="min-w-0">
        <h1 class="text-2xl font-semibold text-[var(--color-text-primary)] tracking-[-0.01em] leading-tight">{{ title }}</h1>
        <p v-if="description" class="mt-1.5 max-w-2xl text-sm text-[var(--color-text-muted)] leading-6">{{ description }}</p>
      </div>
    </div>
    <div v-if="$slots.actions" class="shrink-0 flex items-center gap-2">
      <slot name="actions" />
    </div>
  </div>
</template>
