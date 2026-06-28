<script setup lang="ts">
defineProps<{
  size?: 'sm' | 'md' | 'lg'
}>()
</script>

<template>
  <div
    :class="[
      'rounded-full border-2 border-[var(--color-border)] border-t-[var(--color-accent-500)] animate-spin',
      !$props.size || $props.size === 'md' ? 'size-8' : '',
      $props.size === 'sm' ? 'size-5' : '',
      $props.size === 'lg' ? 'size-12' : '',
    ]"
    role="status"
    aria-label="Loading"
  />
</template>
