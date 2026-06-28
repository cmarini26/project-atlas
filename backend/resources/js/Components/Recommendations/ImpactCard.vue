<script setup lang="ts">
defineProps<{
  impact: Record<string, string | number>
}>()
</script>

<template>
  <div class="bg-[var(--color-surface-elevated)] border border-[var(--color-border)] rounded-xl p-4">
    <h3 class="text-xs font-semibold text-[var(--color-text-muted)] uppercase tracking-wide mb-3">Expected impact</h3>
    <dl class="grid grid-cols-2 gap-3">
      <div v-for="(value, key) in impact" :key="key">
        <dt class="text-xs text-[var(--color-text-muted)] mb-0.5 capitalize">{{ String(key).replace(/_/g, ' ') }}</dt>
        <dd class="text-sm font-medium text-[var(--color-text-primary)]">{{ value }}</dd>
      </div>
    </dl>
  </div>
</template>
