<script setup lang="ts">
defineProps<{
  score: number
  max?: number
}>()
</script>

<template>
  <div class="flex items-center gap-2">
    <div class="flex-1 h-1.5 bg-[--color-surface-subtle] rounded-full overflow-hidden">
      <div
        class="h-full bg-[--color-accent-500] rounded-full transition-all duration-[--duration-smooth]"
        :style="{ width: `${Math.min(100, (score / (max ?? 100)) * 100)}%` }"
      />
    </div>
    <span class="text-xs tabular-nums text-[--color-text-muted] shrink-0 w-8 text-right">{{ Math.round(score) }}</span>
  </div>
</template>
