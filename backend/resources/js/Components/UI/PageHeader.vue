<script setup lang="ts">
import type { Component } from 'vue'

defineProps<{
  title: string
  description?: string
  icon?: Component
}>()
</script>

<template>
  <div class="flex items-start justify-between gap-4 mb-6">
    <div class="flex items-start gap-3">
      <div
        v-if="icon"
        class="size-9 shrink-0 rounded-full bg-[var(--color-accent-50)] text-[var(--color-accent-600)] flex items-center justify-center"
      >
        <component :is="icon" class="size-5" />
      </div>
      <div>
        <h1 class="text-xl font-semibold text-[var(--color-text-primary)] mb-1">{{ title }}</h1>
        <p v-if="description" class="text-sm text-[var(--color-text-muted)]">{{ description }}</p>
      </div>
    </div>
    <div v-if="$slots.actions" class="shrink-0">
      <slot name="actions" />
    </div>
  </div>
</template>
