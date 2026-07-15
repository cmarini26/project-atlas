<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import SummaryCard from '@/Components/Dashboard/SummaryCard.vue'
import HealthCard from '@/Components/Dashboard/HealthCard.vue'
import RecommendationPrompt from '@/Components/Dashboard/RecommendationPrompt.vue'
import OnboardingChecklist from '@/Components/Dashboard/OnboardingChecklist.vue'
import EmptyState from '@/Components/UI/EmptyState.vue'
import Badge from '@/Components/UI/Badge.vue'
import PageHeader from '@/Components/UI/PageHeader.vue'
import Card from '@/Components/UI/Card.vue'
import ChannelCapabilityBadge from '@/Components/UI/ChannelCapabilityBadge.vue'
import {
  PaperAirplaneIcon,
  HomeIcon,
  ClockIcon,
  LightBulbIcon,
  MegaphoneIcon,
  ChartBarIcon,
} from '@heroicons/vue/24/outline'
import { channelLabel } from '@/lib/channelCapability'
import type { Recommendation, Campaign, SharedProps } from '@/types'

const page = usePage<SharedProps>()

// Persistent layout: the sidebar/toast shell survives Inertia visits.
defineOptions({ layout: AppLayout })

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
  has_campaign_history: boolean
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
  <div class="max-w-4xl">
    <PageHeader
      title="Overview"
      description="Your daily snapshot — see what needs a decision and how your brand twin is doing."
      :icon="HomeIcon"
    />

    <OnboardingChecklist v-if="!page.props.auth.user?.has_dismissed_checklist" />

    <!-- Pending recommendation prompt -->
    <div v-if="pending_recommendation" data-tour="recommendation-prompt">
      <RecommendationPrompt :recommendation="pending_recommendation" class="mb-6" />
    </div>

    <!-- Summary counts -->
    <div data-tour="summary-cards" class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
      <SummaryCard
        label="Pending"
        :value="counts.pending_recommendations"
        href="/app/recommendations"
        :icon="ClockIcon"
        accent="rose"
      />
      <SummaryCard
        label="Opportunities"
        :value="counts.open_opportunities"
        href="/app/opportunities"
        :icon="LightBulbIcon"
        accent="amber"
      />
      <SummaryCard
        label="Campaigns"
        :value="counts.active_campaigns"
        href="/app/campaigns"
        :icon="MegaphoneIcon"
        accent="indigo"
      />
      <SummaryCard
        label="Learnings"
        :value="counts.unapplied_learnings"
        href="/app/learning"
        :icon="ChartBarIcon"
        accent="teal"
      />
    </div>

    <div
      data-tour="health-card"
      :class="['grid grid-cols-1 gap-4 mb-6', has_campaign_history ? 'lg:grid-cols-2' : '']"
    >
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

      <!-- Recent campaigns — only shares the row once there's real history;
           a brand-new company gets the Health card full-width instead of a
           guaranteed-empty box crowding it. -->
      <Card v-if="has_campaign_history">
        <template #header>
          <h2 class="text-sm font-semibold text-[var(--color-text-primary)]">Recent Campaigns</h2>
          <Link href="/app/campaigns" class="text-xs text-[var(--color-text-link)] hover:underline">View all</Link>
        </template>

        <div class="space-y-3">
          <div
            v-for="campaign in recent_campaigns"
            :key="campaign.id"
            class="flex items-center justify-between"
          >
            <Link :href="`/app/campaigns/${campaign.id}`" class="text-sm text-[var(--color-text-secondary)] hover:text-[var(--color-text-primary)] truncate flex-1 mr-3">
              {{ campaign.title }}
            </Link>
            <Badge :variant="campaignStatusVariants[campaign.status] ?? 'default'">
              {{ campaignStatusLabels[campaign.status] ?? campaign.status }}
            </Badge>
          </div>
        </div>
      </Card>
    </div>

    <!-- Recent executions -->
    <Card data-tour="recent-executions">
      <template #header>
        <h2 class="text-sm font-semibold text-[var(--color-text-primary)]">Recent Publishing Activity</h2>
        <Link href="/app/publishing" class="text-xs text-[var(--color-text-link)] hover:underline">View all</Link>
      </template>

      <div v-if="recent_executions.length > 0" class="space-y-3">
        <div
          v-for="execution in recent_executions"
          :key="execution.id"
          class="flex items-center gap-3"
        >
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2">
              <p class="text-sm text-[var(--color-text-secondary)] truncate">
                {{ execution.channel ? channelLabel(execution.channel.type) : 'Unknown channel' }}
              </p>
              <ChannelCapabilityBadge v-if="execution.channel" :channel-type="execution.channel.type" />
            </div>
            <p class="text-xs text-[var(--color-text-muted)]">{{ formatDate(execution.scheduled_at) }}</p>
          </div>
          <Badge
            :variant="execution.status === 'published' || execution.status === 'completed' ? 'success' : execution.status === 'failed' ? 'warning' : 'muted'"
          >
            {{ executionStatusLabels[execution.status] ?? execution.status }}
          </Badge>
        </div>
      </div>

      <EmptyState v-else title="No activity yet" description="Simulated publishing activity appears here once campaigns run — no live channels are connected yet.">
        <template #icon><PaperAirplaneIcon class="size-6" /></template>
      </EmptyState>
    </Card>
  </div>
</template>
