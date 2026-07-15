<script setup lang="ts">
withDefaults(
  defineProps<{
    as?: 'div' | 'li'
    clickable?: boolean
  }>(),
  { as: 'div', clickable: false },
)
</script>

<template>
  <component
    :is="as"
    :class="[
      'flex items-center justify-between gap-3 px-4 py-3',
      clickable ? 'hover:bg-[var(--color-surface-subtle)] transition-colors duration-[var(--duration-fast)] cursor-pointer' : '',
    ]"
  >
    <div class="min-w-0 flex-1">
      <slot />
    </div>
    <div v-if="$slots.trailing" class="shrink-0">
      <slot name="trailing" />
    </div>
  </component>
</template>
