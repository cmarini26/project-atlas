<script setup lang="ts">
defineProps<{
  rationaleDisplay: Record<string, string>
}>()

const labels: Record<string, string> = {
  why_now: 'Why now?',
  why_this: 'Why this?',
  why_channel: 'Why this channel?',
  why_works: 'Why do we expect this to work?',
  why_this_channel: 'Why this channel?',
  why_it_will_work: 'Why do we expect this to work?',
}
</script>

<template>
  <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
    <div
      v-for="(value, key) in rationaleDisplay"
      :key="key"
      class="bg-[var(--color-surface-elevated)] border border-[var(--color-border)] rounded-xl p-4"
    >
      <h3 class="text-xs font-semibold text-[var(--color-text-muted)] uppercase tracking-wide mb-2">
        {{ labels[key] ?? String(key).replace(/_/g, ' ') }}
      </h3>
      <p class="text-sm text-[var(--color-text-secondary)]">{{ value }}</p>
    </div>
  </div>
</template>
