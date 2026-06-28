<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue'
import EmptyState from '@/Components/UI/EmptyState.vue'
import type { Campaign, CampaignKpiSnapshot, ExecutionMetric } from '@/types'

interface DecisionSummary {
  expected_impact: Record<string, string | number> | null
  confidence_score: number
}

interface ShowProps {
  campaign: Campaign
  decision: DecisionSummary | null
  snapshot: CampaignKpiSnapshot | null
  metrics: ExecutionMetric[]
}

defineProps<ShowProps>()

function formatDate(date: string | null): string {
  if (!date) return '—'
  return new Date(date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
}
</script>

<template>
  <AppLayout>
    <div class="max-w-3xl">
      <!-- Header -->
      <div class="flex items-start gap-3 mb-6">
        <a href="/app/analytics" class="mt-1 text-[var(--color-text-muted)] hover:text-[var(--color-text-secondary)] transition-colors duration-[var(--duration-fast)]" aria-label="Back">
          <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" /></svg>
        </a>
        <div>
          <h1 class="text-xl font-semibold text-[var(--color-text-primary)]">{{ campaign.title }}</h1>
          <p class="text-sm text-[var(--color-text-muted)] mt-0.5">Campaign analytics</p>
        </div>
      </div>

      <!-- Expected vs actual -->
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-6">
        <div class="bg-[var(--color-surface-elevated)] border border-[var(--color-border)] rounded-xl p-4">
          <h2 class="text-xs font-semibold text-[var(--color-text-muted)] uppercase tracking-wide mb-3">Expected</h2>
          <div v-if="decision?.expected_impact && Object.keys(decision.expected_impact).length > 0" class="space-y-2">
            <div v-for="(value, key) in decision.expected_impact" :key="key">
              <p class="text-xs text-[var(--color-text-muted)] capitalize">{{ String(key).replace(/_/g, ' ') }}</p>
              <p class="text-sm font-medium text-[var(--color-text-primary)]">{{ value }}</p>
            </div>
          </div>
          <p v-else class="text-sm text-[var(--color-text-muted)]">No projections recorded</p>
        </div>

        <div class="bg-[var(--color-surface-elevated)] border border-[var(--color-border)] rounded-xl p-4">
          <h2 class="text-xs font-semibold text-[var(--color-text-muted)] uppercase tracking-wide mb-3">Actual</h2>
          <dl v-if="snapshot" class="space-y-2">
            <div v-for="(value, key) in snapshot.actual_kpis" :key="key">
              <dt class="text-xs text-[var(--color-text-muted)] capitalize">{{ String(key).replace(/_/g, ' ') }}</dt>
              <dd class="text-sm font-semibold text-[var(--color-text-primary)] tabular-nums">{{ value }}</dd>
            </div>
          </dl>
          <p v-else class="text-sm text-[var(--color-text-muted)]">No results yet</p>
        </div>
      </div>

      <!-- Channel breakdown -->
      <section>
        <h2 class="text-xs font-semibold text-[var(--color-text-muted)] uppercase tracking-wide mb-3">Channel Breakdown</h2>

        <EmptyState
          v-if="metrics.length === 0"
          title="No channel data yet"
          description="Metrics appear here as content is published and measured."
        />

        <div v-else class="space-y-3">
          <div
            v-for="metric in metrics"
            :key="metric.id"
            class="bg-[var(--color-surface-elevated)] border border-[var(--color-border)] rounded-xl p-4"
          >
            <div class="flex items-center justify-between mb-3">
              <p class="text-sm font-medium text-[var(--color-text-primary)]">{{ metric.channel_type }}</p>
              <p class="text-xs text-[var(--color-text-muted)]">{{ formatDate(metric.retrieved_at) }}</p>
            </div>
            <dl class="grid grid-cols-2 sm:grid-cols-3 gap-3">
              <div v-for="(value, key) in metric.metrics" :key="key">
                <dt class="text-xs text-[var(--color-text-muted)] capitalize mb-0.5">{{ String(key).replace(/_/g, ' ') }}</dt>
                <dd class="text-sm font-semibold text-[var(--color-text-primary)] tabular-nums">{{ value }}</dd>
              </div>
              <div v-if="metric.normalised_reach !== null && metric.normalised_reach !== undefined">
                <dt class="text-xs text-[var(--color-text-muted)] mb-0.5">Reach</dt>
                <dd class="text-sm font-semibold text-[var(--color-text-primary)] tabular-nums">{{ metric.normalised_reach }}</dd>
              </div>
              <div v-if="metric.normalised_engagement_rate !== null && metric.normalised_engagement_rate !== undefined">
                <dt class="text-xs text-[var(--color-text-muted)] mb-0.5">Eng. rate</dt>
                <dd class="text-sm font-semibold text-[var(--color-text-primary)] tabular-nums">{{ metric.normalised_engagement_rate }}</dd>
              </div>
            </dl>
          </div>
        </div>
      </section>
    </div>
  </AppLayout>
</template>
