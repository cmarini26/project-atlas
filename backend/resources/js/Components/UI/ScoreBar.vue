<script setup lang="ts">
import { computed } from 'vue'

const props = defineProps<{
  score: number
  max?: number
}>()

const fillPercent = computed(() => Math.min(100, (props.score / (props.max ?? 100)) * 100))

const fillColor = computed(() => {
  const s = props.score
  if (s >= 90) return 'bg-emerald-400'
  if (s >= 75) return 'bg-green-400'
  if (s >= 60) return 'bg-yellow-400'
  if (s >= 40) return 'bg-orange-400'
  return 'bg-red-400'
})
</script>

<template>
  <div
    class="flex items-center gap-2"
    role="progressbar"
    :aria-valuenow="Math.round(score)"
    aria-valuemin="0"
    :aria-valuemax="max ?? 100"
  >
    <div class="flex-1 h-1.5 bg-[var(--color-surface-subtle)] rounded-full overflow-hidden">
      <div
        :class="['h-full rounded-full transition-all duration-[var(--duration-smooth)]', fillColor]"
        :style="{ width: `${fillPercent}%` }"
      />
    </div>
    <span class="text-xs tabular-nums text-[var(--color-text-muted)] shrink-0 w-8 text-right" aria-hidden="true">{{ Math.round(score) }}</span>
    <span class="sr-only">{{ Math.round(score) }} out of {{ max ?? 100 }}</span>
  </div>
</template>
