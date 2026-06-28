<script setup lang="ts">
defineProps<{
  variant?: 'default' | 'accent' | 'success' | 'warning' | 'neutral' | 'muted'
}>()
</script>

<template>
  <span
    :class="[
      'inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium',
      !$props.variant || $props.variant === 'default' ? 'bg-[--color-surface-subtle] text-[--color-text-secondary] border border-[--color-border]' : '',
      $props.variant === 'accent' ? 'bg-[--color-accent-50] text-[--color-accent-700] border border-[--color-accent-200]' : '',
      $props.variant === 'success' ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : '',
      $props.variant === 'warning' ? 'bg-amber-50 text-amber-700 border border-amber-200' : '',
      $props.variant === 'neutral' ? 'bg-stone-100 text-stone-600 border border-stone-200' : '',
      $props.variant === 'muted' ? 'bg-[--color-surface-subtle] text-[--color-text-muted] border border-[--color-border]' : '',
    ]"
  >
    <slot />
  </span>
</template>
