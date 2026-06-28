<script setup lang="ts">
interface Health {
  twin_status: string
  fact_count: number
  knowledge_count: number
  integration_count: number
}

defineProps<{ health: Health }>()

const statusLabels: Record<string, string> = {
  initializing: 'Getting started',
  crawling: 'Learning',
  analyzing: 'Analyzing',
  ready: 'Ready',
  error: 'Needs attention',
}

const statusColors: Record<string, string> = {
  ready: 'text-emerald-600',
  analyzing: 'text-[var(--color-accent-600)]',
  crawling: 'text-[var(--color-accent-600)]',
  initializing: 'text-[var(--color-text-muted)]',
  error: 'text-rose-600',
}
</script>

<template>
  <div class="bg-[var(--color-surface-elevated)] border border-[var(--color-border)] rounded-xl p-5">
    <div class="flex items-center justify-between mb-4">
      <h2 class="text-sm font-semibold text-[var(--color-text-primary)]">Business Brain</h2>
      <a href="/app/brain" class="text-xs text-[var(--color-text-link)] hover:underline">View all</a>
    </div>

    <div class="flex items-center gap-2 mb-4">
      <span :class="['text-sm font-medium', statusColors[health.twin_status] ?? 'text-[var(--color-text-muted)]']">
        {{ statusLabels[health.twin_status] ?? health.twin_status }}
      </span>
    </div>

    <dl class="grid grid-cols-3 gap-3">
      <div>
        <dt class="text-xs text-[var(--color-text-muted)] mb-0.5">Facts</dt>
        <dd class="text-lg font-semibold text-[var(--color-text-primary)] tabular-nums">{{ health.fact_count }}</dd>
      </div>
      <div>
        <dt class="text-xs text-[var(--color-text-muted)] mb-0.5">Knowledge</dt>
        <dd class="text-lg font-semibold text-[var(--color-text-primary)] tabular-nums">{{ health.knowledge_count }}</dd>
      </div>
      <div>
        <dt class="text-xs text-[var(--color-text-muted)] mb-0.5">Integrations</dt>
        <dd class="text-lg font-semibold text-[var(--color-text-primary)] tabular-nums">{{ health.integration_count }}</dd>
      </div>
    </dl>
  </div>
</template>
