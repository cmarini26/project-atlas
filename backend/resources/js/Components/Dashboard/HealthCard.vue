<script setup lang="ts">
import { computed } from 'vue'

interface Health {
  twin_status: string
  twin_health_score?: number | null
  fact_count: number
  knowledge_count: number
  integration_count: number
}

const props = defineProps<{ health: Health }>()

const statusLabels: Record<string, string> = {
  initializing: 'Setting up',
  active: 'Active',
  error: 'Needs attention',
}

const statusColors: Record<string, string> = {
  active: 'text-emerald-600',
  initializing: 'text-[var(--color-text-muted)]',
  error: 'text-rose-600',
}

const score = computed(() => props.health.twin_health_score ?? null)

const healthLabel = computed(() => {
  if (score.value === null) return null
  if (score.value >= 80) return 'Healthy'
  if (score.value >= 50) return 'Building'
  return 'Learning'
})

const healthLabelColor = computed(() => {
  if (score.value === null) return ''
  if (score.value >= 80) return 'text-emerald-600'
  if (score.value >= 50) return 'text-amber-600'
  return 'text-[var(--color-text-muted)]'
})
</script>

<template>
  <div class="bg-[var(--color-surface-elevated)] border border-[var(--color-border)] rounded-xl p-5">
    <div class="flex items-center justify-between mb-4">
      <h2 class="text-sm font-semibold text-[var(--color-text-primary)]">Business Brain</h2>
      <a href="/app/brain" class="text-xs text-[var(--color-text-link)] hover:underline">View all</a>
    </div>

    <div class="flex items-center justify-between mb-4">
      <span :class="['text-sm font-medium', statusColors[health.twin_status] ?? 'text-[var(--color-text-muted)]']">
        {{ statusLabels[health.twin_status] ?? health.twin_status }}
      </span>
      <div v-if="score !== null" class="flex items-center gap-1.5">
        <span class="text-sm font-semibold text-[var(--color-text-primary)] tabular-nums">{{ score }}</span>
        <span :class="['text-xs font-medium', healthLabelColor]">{{ healthLabel }}</span>
      </div>
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
