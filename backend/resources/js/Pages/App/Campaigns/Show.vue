<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Badge from '@/Components/UI/Badge.vue'
import EmptyState from '@/Components/UI/EmptyState.vue'
import CampaignTrail from '@/Components/Campaign/CampaignTrail.vue'
import ChannelCapabilityBadge from '@/Components/UI/ChannelCapabilityBadge.vue'
import { channelLabel } from '@/lib/channelCapability'
import { ClockIcon } from '@heroicons/vue/24/outline'
import type { Campaign, ContentAsset, Execution, CampaignKpiSnapshot } from '@/types'

// Persistent layout: the sidebar/toast shell survives Inertia visits.
defineOptions({ layout: AppLayout })

interface EmailAudienceOption {
  id: string
  name: string
  member_count: number
}

interface EmailRecipientOutcomes {
  pending: number
  accepted: number
  failed: number
  skipped: number
  total: number
}

interface EmailAudienceSelector {
  audiences: EmailAudienceOption[]
  selected: EmailAudienceOption | null
  linked_marketing_channel: { supports_publishing: boolean } | null
  recipient_outcomes: EmailRecipientOutcomes | null
}

interface ShowProps {
  campaign: Campaign
  content_assets: ContentAsset[]
  executions: Execution[]
  kpi_snapshot: CampaignKpiSnapshot | null
  decision: { rationale: Record<string, string> | null; expected_impact: Record<string, string | number> | null; confidence_score: number } | null
  email_audience_selector: EmailAudienceSelector
}

const props = defineProps<ShowProps>()

const audienceForm = useForm({
  email_audience_id: props.email_audience_selector.selected?.id ?? '',
})

function selectAudience(): void {
  audienceForm.patch(`/app/campaigns/${props.campaign.id}/email-audience`, { preserveScroll: true })
}

const statusVariants: Record<string, 'accent' | 'success' | 'muted' | 'default'> = {
  active: 'accent',
  published: 'accent',
  completed: 'success',
  draft: 'muted',
  approved: 'default',
  cancelled: 'muted',
}

const statusLabels: Record<string, string> = {
  draft: 'Draft',
  approved: 'Approved',
  active: 'Active',
  published: 'Published',
  completed: 'Completed',
  cancelled: 'Cancelled',
}

const executionStatusVariants: Record<string, 'success' | 'warning' | 'muted' | 'default'> = {
  published: 'success',
  completed: 'success',
  failed: 'warning',
  pending: 'muted',
  scheduled: 'default',
  executing: 'default',
}

const executionStatusLabels: Record<string, string> = {
  pending: 'Pending',
  scheduled: 'Scheduled',
  executing: 'Executing',
  completed: 'Completed',
  published: 'Published',
  failed: 'Failed',
  cancelled: 'Cancelled',
}

// Deliberately not "Delivered"/"Sent successfully" — `accepted` only means
// Postmark's API accepted the message for that one recipient, which is all
// EmailRecipientSnapshot actually tracks today. There is no delivery/open/
// click signal per recipient here (that's ExecutionMetric/CampaignKpiService,
// a separate, campaign-wide, provider-side pull — not this per-recipient
// send-time record).
const recipientOutcomeLabels: Record<keyof Omit<EmailRecipientOutcomes, 'total'>, string> = {
  pending: 'Pending',
  accepted: 'Accepted by provider',
  failed: 'Send failed',
  skipped: 'Skipped (duplicate)',
}

function formatDate(date: string | null): string {
  if (!date) return '—'
  return new Date(date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
}
</script>

<template>
  <Head><title>{{ campaign.title }} — Atlas</title></Head>
  <div class="max-w-3xl">
    <!-- Header -->
    <div class="flex items-start gap-3 mb-6">
      <Link href="/app/campaigns" class="mt-1 text-[var(--color-text-muted)] hover:text-[var(--color-text-secondary)] transition-colors duration-[var(--duration-fast)]" aria-label="Back">
        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" /></svg>
      </Link>
      <div class="flex-1 min-w-0">
        <div class="flex items-center gap-2 mb-1">
          <Badge :variant="statusVariants[campaign.status] ?? 'muted'">{{ statusLabels[campaign.status] ?? campaign.status }}</Badge>
        </div>
        <h1 class="text-xl font-semibold text-[var(--color-text-primary)]">{{ campaign.title }}</h1>
        <p class="text-sm text-[var(--color-text-muted)] mt-1">Started {{ formatDate(campaign.created_at) }}</p>
      </div>
    </div>

    <div v-if="campaign.status !== 'cancelled'" class="bg-[var(--color-surface-elevated)] border border-[var(--color-border)] rounded-xl p-5 mb-6">
      <CampaignTrail :status="campaign.status" />
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

    <!-- Email audience targeting -->
    <div class="bg-[var(--color-surface-elevated)] border border-[var(--color-border)] rounded-xl p-5 mb-6">
      <div class="flex items-center gap-2 mb-3">
        <h2 class="text-xs font-semibold text-[var(--color-text-muted)] uppercase tracking-wide">Email Audience</h2>
        <ChannelCapabilityBadge
          channel-type="email"
          :linked-marketing-channel="email_audience_selector.linked_marketing_channel ? { supportsPublishing: email_audience_selector.linked_marketing_channel.supports_publishing } : null"
        />
      </div>

      <form class="flex items-start gap-2 mb-2" @submit.prevent="selectAudience">
        <select
          v-model="audienceForm.email_audience_id"
          class="flex-1 px-3 py-2 text-sm rounded-lg border border-[var(--color-border)] bg-white text-[var(--color-text-primary)] focus:outline-none focus:ring-1 focus:ring-[var(--color-border-focus)] focus:border-[var(--color-border-focus)] transition-colors duration-[var(--duration-fast)]"
        >
          <option value="">No audience selected</option>
          <option v-for="option in email_audience_selector.audiences" :key="option.id" :value="option.id">
            {{ option.name }} ({{ option.member_count }})
          </option>
        </select>
        <button
          type="submit"
          :disabled="audienceForm.processing"
          class="shrink-0 py-2 px-4 text-sm font-medium rounded-lg border border-[var(--color-border)] text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-subtle)] disabled:opacity-60 disabled:cursor-not-allowed transition-colors duration-[var(--duration-fast)]"
        >
          {{ audienceForm.processing ? 'Saving…' : 'Save' }}
        </button>
      </form>

      <p v-if="email_audience_selector.selected" class="text-xs text-[var(--color-text-muted)]">
        {{ email_audience_selector.selected.member_count }} recipient{{ email_audience_selector.selected.member_count === 1 ? '' : 's' }}
        <span v-if="email_audience_selector.selected.member_count === 0" class="text-amber-600">— this audience is empty, nothing would be sent</span>
      </p>
      <p v-else class="text-xs text-[var(--color-text-muted)]">No audience selected yet.</p>

      <!-- Recipient outcomes — aggregate counts only, never individual
           addresses. See the recipientOutcomeLabels comment above for why
           "accepted" is the honest word, not "delivered"/"sent". -->
      <div v-if="email_audience_selector.recipient_outcomes" class="mt-4 pt-4 border-t border-[var(--color-border)]">
        <h3 class="text-xs font-semibold text-[var(--color-text-muted)] uppercase tracking-wide mb-2">Send outcomes</h3>
        <dl class="grid grid-cols-2 sm:grid-cols-4 gap-3">
          <div v-for="(key) in (['pending', 'accepted', 'failed', 'skipped'] as const)" :key="key">
            <dt class="text-xs text-[var(--color-text-muted)] mb-0.5">{{ recipientOutcomeLabels[key] }}</dt>
            <dd class="text-lg font-semibold text-[var(--color-text-primary)] tabular-nums">{{ email_audience_selector.recipient_outcomes[key] }}</dd>
          </div>
        </dl>
        <p class="text-xs text-[var(--color-text-muted)] mt-2">
          {{ email_audience_selector.recipient_outcomes.total }} recipient{{ email_audience_selector.recipient_outcomes.total === 1 ? '' : 's' }} targeted in total.
          "Accepted by provider" means Postmark accepted the send — it does not confirm delivery, opens, or clicks.
        </p>
      </div>
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
            <span v-if="asset.channel?.type" class="text-xs text-[var(--color-text-muted)]">{{ channelLabel(asset.channel.type) }}</span>
            <ChannelCapabilityBadge
              v-if="asset.channel?.type"
              :channel-type="asset.channel.type"
              :linked-marketing-channel="asset.channel.marketing_channel ? { supportsPublishing: asset.channel.marketing_channel.supports_publishing } : null"
            />
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
        description="Executions appear here as content is scheduled and processed (simulated — not yet sent to a live channel)."
      >
        <template #icon><ClockIcon class="size-6" /></template>
      </EmptyState>

      <div v-else class="bg-[var(--color-surface-elevated)] border border-[var(--color-border)] rounded-xl divide-y divide-[var(--color-border)]">
        <div
          v-for="execution in executions"
          :key="execution.id"
          class="flex items-center gap-3 px-4 py-3"
        >
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2">
              <p class="text-sm font-medium text-[var(--color-text-primary)] truncate">{{ execution.channel ? channelLabel(execution.channel.type) : 'Unknown' }}</p>
              <ChannelCapabilityBadge
                v-if="execution.channel"
                :channel-type="execution.channel.type"
                :linked-marketing-channel="execution.channel.marketing_channel ? { supportsPublishing: execution.channel.marketing_channel.supports_publishing } : null"
              />
            </div>
            <p class="text-xs text-[var(--color-text-muted)]">{{ formatDate(execution.scheduled_at) }}</p>
          </div>
          <Badge :variant="executionStatusVariants[execution.status] ?? 'muted'">
            {{ executionStatusLabels[execution.status] ?? execution.status }}
          </Badge>
        </div>
      </div>
    </section>
  </div>
</template>
