<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import SummaryCard from '@/Components/Dashboard/SummaryCard.vue'
import HealthCard from '@/Components/Dashboard/HealthCard.vue'
import RecommendationPrompt from '@/Components/Dashboard/RecommendationPrompt.vue'
import EmptyState from '@/Components/UI/EmptyState.vue'
import Badge from '@/Components/UI/Badge.vue'
import type { Recommendation, Campaign } from '@/types'

interface Health {
  twin_status: string
  twin_health_score: number
  twin_last_enriched_at: string | null
  fact_count: number
  knowledge_count: number
  integration_count: number
}

interface RecentExecution {
  id: string
  status: string
  scheduled_at: string | null
  completed_at: string | null
  channel: { type: string } | null
}

interface DashboardProps {
  counts: {
    pending_recommendations: number
    open_opportunities: number
    active_campaigns: number
    unapplied_learnings: number
  }
  health: Health
  pending_recommendation: Recommendation | null
  recent_campaigns: Campaign[]
  recent_executions: RecentExecution[]
}

defineProps<DashboardProps>()

const campaignStatusLabels: Record<string, string> = {
  draft: 'Draft',
  approved: 'Approved',
  active: 'Active',
  published: 'Published',
  completed: 'Completed',
  cancelled: 'Cancelled',
}

const campaignStatusVariants: Record<string, 'default' | 'accent' | 'success' | 'muted'> = {
  active: 'accent',
  published: 'accent',
  completed: 'success',
  draft: 'muted',
  approved: 'default',
  cancelled: 'muted',
}

const executionStatusLabels: Record<string, string> = {
  pending: 'Pending',
  scheduled: 'Scheduled',
  published: 'Published',
  completed: 'Completed',
  failed: 'Failed',
}

function formatDate(date: string | null): string {
  if (!date) return '—'
  return new Date(date).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })
}
</script>

<template>
  <Head><title>Overview — Atlas</title></Head>
  <AppLayout>
    <div class="max-w-4xl">
      <h1 class="text-xl font-semibold text-[var(--color-text-primary)] mb-6">Overview</h1>

      <!-- Pending recommendation prompt -->
      <RecommendationPrompt
        v-if="pending_recommendation"
        :recommendation="pending_recommendation"
        class="mb-6"
      />

      <!-- Summary counts -->
      <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
        <SummaryCard
          label="Pending"
          :value="counts.pending_recommendations"
          href="/app/recommendations"
        />
        <SummaryCard
          label="Opportunities"
          :value="counts.open_opportunities"
          href="/app/opportunities"
        />
        <SummaryCard
          label="Campaigns"
          :value="counts.active_campaigns"
          href="/app/campaigns"
        />
        <SummaryCard
          label="Learnings"
          :value="counts.unapplied_learnings"
          href="/app/learning"
        />
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-6">
        <!-- Business Brain health -->
        <HealthCard
          :health="{
            twin_status: health.twin_status,
            twin_health_score: health.twin_health_score,
            fact_count: health.fact_count,
            knowledge_count: health.knowledge_count,
            integration_count: health.integration_count,
          }"
        />

        <!-- Recent campaigns -->
        <div class="bg-[var(--color-surface-elevated)] border border-[var(--color-border)] rounded-xl p-5">
          <div class="flex items-center justify-between mb-4">
            <h2 class="text-sm font-semibold text-[var(--color-text-primary)]">Recent Campaigns</h2>
            <a href="/app/campaigns" class="text-xs text-[var(--color-text-link)] hover:underline">View all</a>
          </div>

          <div v-if="recent_campaigns.length > 0" class="space-y-3">
            <div
              v-for="campaign in recent_campaigns"
              :key="campaign.id"
              class="flex items-center justify-between"
            >
              <a :href="`/app/campaigns/${campaign.id}`" class="text-sm text-[var(--color-text-secondary)] hover:text-[var(--color-text-primary)] truncate flex-1 mr-3">
                {{ campaign.title }}
              </a>
              <Badge :variant="campaignStatusVariants[campaign.status] ?? 'default'">
                {{ campaignStatusLabels[campaign.status] ?? campaign.status }}
              </Badge>
            </div>
          </div>

          <EmptyState v-else title="No campaigns yet" description="Campaigns appear here after you approve a recommendation." />
        </div>
      </div>

      <!-- Recent executions -->
      <div class="bg-[var(--color-surface-elevated)] border border-[var(--color-border)] rounded-xl p-5">
        <div class="flex items-center justify-between mb-4">
          <h2 class="text-sm font-semibold text-[var(--color-text-primary)]">Recent Publishing Activity</h2>
          <a href="/app/publishing" class="text-xs text-[var(--color-text-link)] hover:underline">View all</a>
        </div>

        <div v-if="recent_executions.length > 0" class="space-y-3">
          <div
            v-for="execution in recent_executions"
            :key="execution.id"
            class="flex items-center gap-3"
          >
            <div class="flex-1 min-w-0">
              <p class="text-sm text-[var(--color-text-secondary)] truncate">
                {{ execution.channel?.type ?? 'Unknown channel' }}
              </p>
              <p class="text-xs text-[var(--color-text-muted)]">{{ formatDate(execution.scheduled_at) }}</p>
            </div>
            <Badge
              :variant="execution.status === 'published' || execution.status === 'completed' ? 'success' : execution.status === 'failed' ? 'warning' : 'muted'"
            >
              {{ executionStatusLabels[execution.status] ?? execution.status }}
            </Badge>
          </div>
        </div>

        <EmptyState v-else title="No activity yet" description="Publishing activity appears here once campaigns are running." />
      </div>
    </div>
  </AppLayout>
</template>
