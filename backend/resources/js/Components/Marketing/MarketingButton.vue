<script setup lang="ts">
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'

const props = withDefaults(
  defineProps<{
    href: string
    variant?: 'primary' | 'secondary' | 'ghost'
    size?: 'md' | 'lg'
  }>(),
  {
    variant: 'primary',
    size: 'md',
  },
)

// In-page anchors (#how-it-works) scroll smoothly via a plain <a>; every
// other href is a real route, navigated via Inertia's Link.
const isAnchor = computed(() => props.href.startsWith('#'))

const sizeClasses = computed(() =>
  props.size === 'lg' ? 'h-12 px-6 text-[15px]' : 'h-10 px-4 text-body',
)

const variantClasses = computed(() => {
  switch (props.variant) {
    case 'secondary':
      return 'bg-white text-[var(--color-accent-600)] border-[1.5px] border-[var(--color-accent-500)] hover:bg-[var(--color-accent-50)] active:bg-[var(--color-accent-100)]'
    case 'ghost':
      return 'bg-transparent text-[var(--color-text-muted)] hover:bg-[var(--color-surface-subtle)] hover:text-[var(--color-text-secondary)]'
    default:
      return 'bg-[image:var(--gradient-accent)] text-white shadow-[0_8px_20px_-6px_rgba(99,102,241,0.5)] hover:brightness-105 active:brightness-95'
  }
})
</script>

<template>
  <component
    :is="isAnchor ? 'a' : Link"
    :href="href"
    :class="[
      'inline-flex items-center justify-center gap-2 rounded-lg font-medium transition-colors duration-[var(--duration-fast)] ease-[var(--ease-standard)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-accent-500)] focus-visible:ring-offset-2',
      sizeClasses,
      variantClasses,
    ]"
  >
    <slot />
  </component>
</template>
