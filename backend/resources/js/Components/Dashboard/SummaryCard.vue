<script setup lang="ts">
import type { FunctionalComponent } from 'vue'
import Card from '@/Components/UI/Card.vue'

withDefaults(
  defineProps<{
    label: string
    value: number | string
    href?: string
    icon?: FunctionalComponent
    accent?: 'indigo' | 'amber' | 'teal' | 'rose'
  }>(),
  { accent: 'indigo' },
)

const badgeClasses: Record<string, string> = {
  indigo: 'bg-[var(--color-accent-50)] text-[var(--color-accent-700)] ring-[var(--color-accent-200)]',
  amber: 'bg-amber-50 text-amber-700 ring-amber-200',
  teal: 'bg-teal-50 text-teal-700 ring-teal-200',
  rose: 'bg-rose-50 text-rose-700 ring-rose-200',
}

const topBorderClasses: Record<string, string> = {
  indigo: 'before:bg-[var(--color-accent-500)]',
  amber: 'before:bg-[var(--color-amber-500)]',
  teal: 'before:bg-[var(--color-teal-500)]',
  rose: 'before:bg-[var(--color-coral-500)]',
}
</script>

<template>
  <Card
    :href="href"
    padding="sm"
    :clickable="!!href"
    :class="[
      'overflow-hidden before:absolute before:left-0 before:right-0 before:top-0 before:h-1',
      topBorderClasses[accent],
    ]"
  >
    <div class="flex items-start justify-between gap-3 pt-1">
      <div>
        <p class="text-xs font-semibold text-[var(--color-text-muted)] uppercase tracking-[0.12em] mb-2">{{ label }}</p>
        <p class="text-3xl font-semibold text-[var(--color-text-primary)] tabular-nums leading-none">{{ value }}</p>
      </div>
      <div v-if="icon" :class="['size-10 rounded-[var(--radius-sm)] flex items-center justify-center ring-1', badgeClasses[accent]]">
        <component :is="icon" class="size-5" aria-hidden="true" />
      </div>
    </div>
  </Card>
</template>
