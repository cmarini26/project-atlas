<script setup lang="ts">
import { ref, watch } from 'vue'
import { useMediaQuery } from '@vueuse/core'

const props = withDefaults(
  defineProps<{
    label: string
    value: number
    reveal?: boolean
    fillClass?: string
  }>(),
  {
    reveal: true,
    fillClass: 'bg-[var(--color-accent-500)]',
  },
)

const prefersReducedMotion = useMediaQuery('(prefers-reduced-motion: reduce)')
const width = ref(prefersReducedMotion.value || props.reveal ? props.value : 0)

watch(
  () => props.reveal,
  (isRevealed) => {
    if (isRevealed) {
      width.value = props.value
    }
  },
  { immediate: true },
)
</script>

<template>
  <div
    role="progressbar"
    :aria-label="label"
    :aria-valuenow="value"
    aria-valuemin="0"
    aria-valuemax="100"
    class="flex items-center gap-3"
  >
    <div class="h-1.5 flex-1 rounded-full bg-[var(--color-border)] overflow-hidden">
      <div
        class="h-full rounded-full transition-[width] duration-[800ms] ease-[var(--ease-out)]"
        :class="fillClass"
        :style="{ width: `${width}%` }"
      />
    </div>
    <span class="text-body-sm font-medium text-[var(--color-text-primary)] tabular-nums">{{ value }}</span>
  </div>
</template>
