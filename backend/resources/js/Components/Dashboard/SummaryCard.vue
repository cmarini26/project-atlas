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
  indigo: 'bg-[var(--color-accent-50)] text-[var(--color-accent-600)]',
  amber: 'bg-amber-50 text-amber-600',
  teal: 'bg-teal-50 text-teal-600',
  rose: 'bg-rose-50 text-rose-600',
}
</script>

<template>
  <Card :href="href" padding="sm" :clickable="!!href">
    <div v-if="icon" :class="['size-9 rounded-[var(--radius-sm)] flex items-center justify-center mb-3', badgeClasses[accent]]">
      <component :is="icon" class="size-5" aria-hidden="true" />
    </div>
    <p class="text-xs font-medium text-[var(--color-text-muted)] uppercase tracking-wide mb-2">{{ label }}</p>
    <p class="text-2xl font-semibold text-[var(--color-text-primary)] tabular-nums">{{ value }}</p>
  </Card>
</template>
