<script setup lang="ts">
defineProps<{
  label: string
  value: number | string
  href?: string
}>()
</script>

<template>
  <component
    :is="href ? 'a' : 'div'"
    :href="href"
    :class="[
      'block bg-[--color-surface-elevated] border border-[--color-border] rounded-xl p-5',
      href ? 'hover:border-[--color-border-strong] transition-colors duration-[--duration-fast] cursor-pointer' : '',
    ]"
  >
    <p class="text-xs font-medium text-[--color-text-muted] uppercase tracking-wide mb-2">{{ label }}</p>
    <p class="text-2xl font-semibold text-[--color-text-primary] tabular-nums">{{ value }}</p>
  </component>
</template>
