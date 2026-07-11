<script setup lang="ts">
import { useScrollReveal } from '@/composables/useScrollReveal'

withDefaults(
  defineProps<{
    eyebrow: string
    level?: 'h1' | 'h2'
    align?: 'left' | 'center'
  }>(),
  {
    level: 'h2',
    align: 'left',
  },
)

const { target, isVisible } = useScrollReveal()
</script>

<template>
  <div
    ref="target"
    :class="[
      'transition-all duration-300 ease-[var(--ease-out)]',
      isVisible ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-3',
      align === 'center' ? 'text-center' : 'text-left',
    ]"
  >
    <p class="text-label uppercase tracking-[0.06em] text-[var(--color-text-muted)] mb-3">
      {{ eyebrow }}
    </p>
    <component
      :is="level"
      class="text-heading-1 sm:text-display text-[var(--color-text-primary)] text-balance"
    >
      <slot />
    </component>
  </div>
</template>
