<script setup lang="ts">
import { Link } from '@inertiajs/vue3'

withDefaults(
  defineProps<{
    as?: 'div' | 'section' | 'article'
    href?: string
    padding?: 'none' | 'sm' | 'md' | 'lg'
    clickable?: boolean
    accent?: 'none' | 'indigo' | 'amber' | 'teal' | 'rose'
    divided?: boolean
    /**
     * `default` — elevated surface, shadow only, no border (System.md §8).
     * `inset` — nested content inside another card: border only, no shadow,
     * subtle fill. Never gets an accent bar or hover elevation.
     */
    variant?: 'default' | 'inset'
  }>(),
  {
    as: 'div',
    href: undefined,
    padding: 'md',
    clickable: false,
    accent: 'none',
    divided: false,
    variant: 'default',
  },
)

const paddingClasses: Record<string, string> = {
  none: 'p-0',
  sm: 'p-4',
  md: 'p-6',
  lg: 'p-8',
}

const accentBorderClasses: Record<string, string> = {
  indigo: 'border-l-[var(--color-accent-500)]',
  amber: 'border-l-amber-500',
  teal: 'border-l-teal-500',
  rose: 'border-l-rose-500',
}
</script>

<template>
  <component
    :is="href ? Link : as"
    :href="href"
    :class="[
      'relative block rounded-[var(--radius-md)]',
      paddingClasses[padding],
      divided ? 'divide-y divide-[var(--color-border)]' : '',
      variant === 'inset'
        ? 'bg-[var(--color-surface-subtle)] border border-[var(--color-border)]'
        : 'bg-[var(--color-surface-elevated)]',
      variant === 'default' && accent === 'none' ? 'shadow-[var(--shadow-card)]' : '',
      variant === 'default' && accent !== 'none'
        ? ['border-l-4 shadow-[var(--shadow-accent)]', accentBorderClasses[accent]]
        : '',
      (href || clickable) && variant === 'default'
        ? 'hover:shadow-[var(--shadow-raised)] transition-[box-shadow,transform] duration-[var(--duration-fast)] cursor-pointer hover:-translate-y-0.5'
        : '',
    ]"
  >
    <div v-if="$slots.header" :class="['flex items-center justify-between gap-3', padding === 'none' ? 'px-4 py-3' : 'mb-4']">
      <slot name="header" />
    </div>
    <slot />
  </component>
</template>
