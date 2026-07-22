<script setup lang="ts">
defineProps<{
  impact: Record<string, string | number>
}>()
</script>

<template>
  <div class="bg-[var(--color-surface-elevated)] border border-[var(--color-border)] rounded-[var(--radius-md)] shadow-[var(--shadow-card)] overflow-hidden">
    <div class="bg-[var(--color-surface-panel)] px-5 py-4 border-b border-[var(--color-border)]">
      <h3 class="text-sm font-semibold text-[var(--color-text-primary)]">Expected impact</h3>
      <p class="mt-1 text-xs text-[var(--color-text-muted)]">The result Atlas is aiming for.</p>
    </div>
    <dl class="grid grid-cols-1 divide-y divide-[var(--color-border)]">
      <div v-for="(value, key) in impact" :key="key" class="px-5 py-3">
        <dt class="text-xs text-[var(--color-text-muted)] mb-1 capitalize">{{ String(key).replace(/_/g, ' ') }}</dt>
        <dd class="text-sm font-semibold text-[var(--color-text-primary)]">{{ value }}</dd>
      </div>
    </dl>
  </div>
</template>
