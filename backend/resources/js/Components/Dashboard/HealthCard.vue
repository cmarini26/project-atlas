<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import { computed } from 'vue'
import Card from '@/Components/UI/Card.vue'

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
  <Card padding="none" class="overflow-hidden">
    <div class="bg-[var(--color-surface-panel)] px-5 py-4 border-b border-[var(--color-border)]">
      <div class="flex items-start justify-between gap-3">
        <div>
          <h2 class="text-sm font-semibold text-[var(--color-text-primary)]">Business Brain</h2>
          <p class="mt-1 text-xs text-[var(--color-text-muted)]">Current intelligence coverage</p>
        </div>
        <Link href="/app/brain" class="text-xs font-semibold text-[var(--color-text-link)] hover:underline">View</Link>
      </div>
    </div>

    <div class="p-5">
      <div class="flex items-center justify-between gap-4 mb-5">
        <div>
          <span :class="['text-sm font-semibold', statusColors[health.twin_status] ?? 'text-[var(--color-text-muted)]']">
            {{ statusLabels[health.twin_status] ?? health.twin_status }}
          </span>
          <p class="mt-1 text-xs text-[var(--color-text-muted)]">Digital Twin status</p>
        </div>
        <div v-if="score !== null" class="text-right">
          <div class="flex items-baseline justify-end gap-1.5">
            <span class="text-3xl font-semibold text-[var(--color-text-primary)] tabular-nums leading-none">{{ score }}</span>
            <span class="text-xs font-semibold text-[var(--color-text-muted)]">/100</span>
          </div>
          <span :class="['text-xs font-semibold', healthLabelColor]">{{ healthLabel }}</span>
        </div>
      </div>

      <dl class="grid grid-cols-3 gap-3">
        <div class="rounded-[var(--radius-sm)] bg-[var(--color-surface)] p-3 ring-1 ring-[var(--color-border)]">
          <dt class="text-xs text-[var(--color-text-muted)] mb-1">Facts</dt>
          <dd class="text-xl font-semibold text-[var(--color-text-primary)] tabular-nums">{{ health.fact_count }}</dd>
        </div>
        <div class="rounded-[var(--radius-sm)] bg-[var(--color-surface)] p-3 ring-1 ring-[var(--color-border)]">
          <dt class="text-xs text-[var(--color-text-muted)] mb-1">Knowledge</dt>
          <dd class="text-xl font-semibold text-[var(--color-text-primary)] tabular-nums">{{ health.knowledge_count }}</dd>
        </div>
        <div class="rounded-[var(--radius-sm)] bg-[var(--color-surface)] p-3 ring-1 ring-[var(--color-border)]">
          <dt class="text-xs text-[var(--color-text-muted)] mb-1">Sources</dt>
          <dd class="text-xl font-semibold text-[var(--color-text-primary)] tabular-nums">{{ health.integration_count }}</dd>
        </div>
      </dl>
    </div>
  </Card>
</template>
