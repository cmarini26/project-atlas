<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue'
import Badge from '@/Components/UI/Badge.vue'
import EmptyState from '@/Components/UI/EmptyState.vue'
import type { Campaign, ContentAsset, Execution, CampaignKpiSnapshot } from '@/types'

interface ShowProps {
  campaign: Campaign
  content_assets: ContentAsset[]
  executions: Execution[]
  kpi_snapshot: CampaignKpiSnapshot | null
  decision: { rationale: Record<string, string> | null; expected_impact: Record<string, string | number> | null; confidence_score: number } | null
}

defineProps<ShowProps>()

const statusVariants: Record<string, 'accent' | 'success' | 'muted' | 'default'> = {
  active: 'accent',
  published: 'accent',
  completed: 'success',
  draft: 'muted',
  approved: 'default',
  cancelled: 'muted',
}

const executionStatusVariants: Record<string, 'success' | 'warning' | 'muted' | 'default'> = {
  published: 'success',
  completed: 'success',
  failed: 'warning',
  pending: 'muted',
  scheduled: 'default',
}

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
        <a href="/app/campaigns" class="mt-1 text-[var(--color-text-muted)] hover:text-[var(--color-text-secondary)] transition-colors duration-[var(--duration-fast)]" aria-label="Back">
          <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" /></svg>
        </a>
        <div class="flex-1 min-w-0">
          <div class="flex items-center gap-2 mb-1">
            <Badge :variant="statusVariants[campaign.status] ?? 'muted'">{{ campaign.status }}</Badge>
          </div>
          <h1 class="text-xl font-semibold text-[var(--color-text-primary)]">{{ campaign.title }}</h1>
          <p class="text-sm text-[var(--color-text-muted)] mt-1">Started {{ formatDate(campaign.created_at) }}</p>
        </div>
      </div>

      <!-- KPI snapshot -->
      <div v-if="kpi_snapshot" class="bg-[var(--color-surface-elevated)] border border-[var(--color-border)] rounded-xl p-5 mb-6">
        <h2 class="text-xs font-semibold text-[var(--color-text-muted)] uppercase tracking-wide mb-3">Results</h2>
        <dl class="grid grid-cols-2 sm:grid-cols-3 gap-4">
          <div v-for="(value, key) in kpi_snapshot.actual_kpis" :key="key">
            <dt class="text-xs text-[var(--color-text-muted)] mb-0.5 capitalize">{{ String(key).replace(/_/g, ' ') }}</dt>
            <dd class="text-lg font-semibold text-[var(--color-text-primary)] tabular-nums">{{ value }}</dd>
          </div>
        </dl>
      </div>

      <!-- Content assets -->
      <section v-if="content_assets.length > 0" class="mb-6">
        <h2 class="text-xs font-semibold text-[var(--color-text-muted)] uppercase tracking-wide mb-3">Content</h2>
        <div class="space-y-3">
          <div
            v-for="asset in content_assets"
            :key="asset.id"
            class="bg-[var(--color-surface-elevated)] border border-[var(--color-border)] rounded-xl p-4"
          >
            <div class="flex items-center gap-2 mb-2">
              <Badge variant="muted">{{ asset.type }}</Badge>
              <span v-if="asset.channel?.type" class="text-xs text-[var(--color-text-muted)]">{{ asset.channel.type }}</span>
            </div>
            <h3 v-if="asset.title" class="text-sm font-semibold text-[var(--color-text-primary)] mb-1">{{ asset.title }}</h3>
            <p class="text-sm text-[var(--color-text-secondary)] whitespace-pre-line">{{ asset.body }}</p>
          </div>
        </div>
      </section>

      <!-- Executions -->
      <section>
        <h2 class="text-xs font-semibold text-[var(--color-text-muted)] uppercase tracking-wide mb-3">Publishing</h2>

        <EmptyState
          v-if="executions.length === 0"
          title="No publishing activity"
          description="Executions appear here as content is scheduled and published."
        />

        <div v-else class="bg-[var(--color-surface-elevated)] border border-[var(--color-border)] rounded-xl divide-y divide-[var(--color-border)]">
          <div
            v-for="execution in executions"
            :key="execution.id"
            class="flex items-center gap-3 px-4 py-3"
          >
            <div class="flex-1 min-w-0">
              <p class="text-sm font-medium text-[var(--color-text-primary)] truncate">{{ execution.channel?.type ?? 'Unknown' }}</p>
              <p class="text-xs text-[var(--color-text-muted)]">{{ formatDate(execution.scheduled_at) }}</p>
            </div>
            <Badge :variant="executionStatusVariants[execution.status] ?? 'muted'">
              {{ execution.status }}
            </Badge>
          </div>
        </div>
      </section>
    </div>
  </AppLayout>
</template>
