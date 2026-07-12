<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import type { FunctionalComponent } from 'vue'

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

const stripClasses: Record<string, string> = {
  indigo: 'from-[var(--color-accent-500)] to-[var(--color-accent-700)]',
  amber: 'from-amber-400 to-amber-600',
  teal: 'from-teal-400 to-teal-600',
  rose: 'from-rose-400 to-rose-600',
}

const badgeClasses: Record<string, string> = {
  indigo: 'bg-[var(--color-accent-50)] text-[var(--color-accent-600)]',
  amber: 'bg-amber-50 text-amber-600',
  teal: 'bg-teal-50 text-teal-600',
  rose: 'bg-rose-50 text-rose-600',
}
</script>

<template>
  <component
    :is="href ? Link : 'div'"
    :href="href"
    :class="[
      'relative block overflow-hidden bg-[var(--color-surface-elevated)] border border-[var(--color-border)] rounded-xl p-5',
      href ? 'hover:border-[var(--color-border-strong)] transition-colors duration-[var(--duration-fast)] cursor-pointer' : '',
    ]"
  >
    <span :class="['absolute inset-x-0 top-0 h-1 bg-gradient-to-r', stripClasses[accent]]" aria-hidden="true" />
    <div v-if="icon" :class="['size-9 rounded-lg flex items-center justify-center mb-3', badgeClasses[accent]]">
      <component :is="icon" class="size-5" aria-hidden="true" />
    </div>
    <p class="text-xs font-medium text-[var(--color-text-muted)] uppercase tracking-wide mb-2">{{ label }}</p>
    <p class="text-2xl font-semibold text-[var(--color-text-primary)] tabular-nums">{{ value }}</p>
  </component>
</template>
