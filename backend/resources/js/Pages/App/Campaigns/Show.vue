<script setup lang="ts">
import { computed, ref } from 'vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Badge from '@/Components/UI/Badge.vue'
import EmptyState from '@/Components/UI/EmptyState.vue'
import Card from '@/Components/UI/Card.vue'
import PageHeader from '@/Components/UI/PageHeader.vue'
import CampaignTrail from '@/Components/Campaign/CampaignTrail.vue'
import ChannelCapabilityBadge from '@/Components/UI/ChannelCapabilityBadge.vue'
import { channelLabel } from '@/lib/channelCapability'
import { ArrowLeftIcon, ClockIcon, MegaphoneIcon, PaperAirplaneIcon } from '@heroicons/vue/24/outline'
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
  selected_content_asset_ids: string[]
  can_edit_channel_selection: boolean
}

const props = defineProps<ShowProps>()

const audienceForm = useForm({
  email_audience_id: props.email_audience_selector.selected?.id ?? '',
})

const channelForm = useForm<{ selected_content_asset_ids: string[] }>({
  selected_content_asset_ids: [...props.selected_content_asset_ids],
})

const selectedContentAssetIds = ref<string[]>([...props.selected_content_asset_ids])

const selectedCount = computed(() => selectedContentAssetIds.value.length)

const orderedContentAssets = computed(() => {
  const selected = props.content_assets.filter((asset) => selectedContentAssetIds.value.includes(asset.id))
  const unselected = props.content_assets.filter((asset) => !selectedContentAssetIds.value.includes(asset.id))
  return [...selected, ...unselected]
})

function syncSelectedIds(): void {
  channelForm.selected_content_asset_ids = [...selectedContentAssetIds.value]
}

function toggleAssetSelection(assetId: string, checked: boolean): void {
  if (checked) {
    if (!selectedContentAssetIds.value.includes(assetId)) {
      selectedContentAssetIds.value = [...selectedContentAssetIds.value, assetId]
    }
  } else {
    selectedContentAssetIds.value = selectedContentAssetIds.value.filter((id) => id !== assetId)
  }

  syncSelectedIds()
}

function handleSelectionChange(assetId: string, event: Event): void {
  toggleAssetSelection(assetId, (event.target as HTMLInputElement).checked)
}

function saveChannelSelection(): void {
  syncSelectedIds()
  channelForm.patch(`/app/campaigns/${props.campaign.id}/channels`, { preserveScroll: true })
}

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
  <div>
    <div class="mb-5">
      <Link href="/app/campaigns" class="inline-flex items-center gap-2 text-sm font-semibold text-[var(--color-text-link)] hover:underline">
        <ArrowLeftIcon class="size-4" aria-hidden="true" />
        Campaigns
      </Link>
    </div>

    <PageHeader
      :title="campaign.title"
      description="Confirm destinations, review the generated assets and track what has already been queued or sent."
      :icon="MegaphoneIcon"
    >
      <template #actions>
        <Badge :variant="statusVariants[campaign.status] ?? 'muted'">{{ statusLabels[campaign.status] ?? campaign.status }}</Badge>
      </template>
    </PageHeader>

    <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_360px]">
      <div class="space-y-6">
        <Card v-if="campaign.status !== 'cancelled'" padding="none" class="overflow-hidden">
          <div class="bg-[var(--color-surface-panel)] px-5 py-4 border-b border-[var(--color-border)]">
            <h2 class="text-sm font-semibold text-[var(--color-text-primary)]">Campaign lifecycle</h2>
            <p class="mt-1 text-xs text-[var(--color-text-muted)]">Where this campaign is in the approval and publishing flow.</p>
          </div>
          <div class="p-5">
            <CampaignTrail :status="campaign.status" />
          </div>
        </Card>

        <Card v-if="content_assets.length > 0" padding="none" class="overflow-hidden">
          <div class="bg-[var(--color-surface-panel)] px-5 py-4 border-b border-[var(--color-border)]">
            <h2 class="text-sm font-semibold text-[var(--color-text-primary)]">Delivery channels</h2>
            <p class="mt-1 text-xs text-[var(--color-text-muted)]">
              {{ can_edit_channel_selection ? 'Choose where this campaign should publish before anything is queued.' : 'These are the destinations currently attached to this campaign.' }}
            </p>
          </div>
          <div class="p-5 space-y-4">
            <div class="grid gap-3 md:grid-cols-2">
              <label
                v-for="asset in content_assets"
                :key="asset.id"
                class="flex gap-3 rounded-[var(--radius-md)] border border-[var(--color-border)] bg-[var(--color-surface-elevated)] p-3"
                :class="selectedContentAssetIds.includes(asset.id) ? 'ring-1 ring-[var(--color-accent-200)] border-[var(--color-accent-200)]' : ''"
              >
                <input
                  :checked="selectedContentAssetIds.includes(asset.id)"
                  :disabled="!can_edit_channel_selection"
                  type="checkbox"
                  class="mt-1 size-4 rounded border-[var(--color-border)] text-[var(--color-accent-600)] focus:ring-[var(--color-accent-500)] disabled:opacity-60"
                  @change="handleSelectionChange(asset.id, $event)"
                />
                <div class="min-w-0 flex-1">
                  <div class="flex items-center gap-2 flex-wrap">
                    <p class="text-sm font-semibold text-[var(--color-text-primary)]">
                      {{ asset.channel?.type ? channelLabel(asset.channel.type) : 'Internal draft' }}
                    </p>
                    <Badge variant="muted">{{ asset.type }}</Badge>
                    <ChannelCapabilityBadge
                      v-if="asset.channel?.type"
                      :channel-type="asset.channel.type"
                      :linked-marketing-channel="asset.channel.marketing_channel ? { supportsPublishing: asset.channel.marketing_channel.supports_publishing } : null"
                    />
                  </div>
                  <p v-if="asset.title" class="mt-1 text-sm text-[var(--color-text-secondary)] truncate">{{ asset.title }}</p>
                  <div
                    v-if="asset.media?.[0]?.url"
                    class="mt-3 overflow-hidden rounded-[var(--radius-sm)] bg-[var(--color-surface)] ring-1 ring-[var(--color-border)]"
                  >
                    <img :src="asset.media[0].url" alt="Prepared campaign image" class="h-28 w-full object-cover" loading="lazy" />
                  </div>
                </div>
              </label>
            </div>

            <div class="flex flex-col gap-3 border-t border-[var(--color-border)] pt-4 sm:flex-row sm:items-center sm:justify-between">
              <p class="text-sm text-[var(--color-text-muted)]">
                {{ selectedCount }} of {{ content_assets.length }} channel{{ content_assets.length === 1 ? '' : 's' }} selected.
              </p>
              <button
                v-if="can_edit_channel_selection"
                type="button"
                :disabled="channelForm.processing || selectedCount === 0"
                class="inline-flex items-center justify-center rounded-[var(--radius-sm)] border border-[var(--color-border-strong)] bg-white px-4 py-2 text-sm font-semibold text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-panel)] disabled:cursor-not-allowed disabled:opacity-60"
                @click="saveChannelSelection"
              >
                {{ channelForm.processing ? 'Saving…' : 'Save channel selection' }}
              </button>
            </div>
          </div>
        </Card>

        <section v-if="orderedContentAssets.length > 0">
          <div class="mb-3">
            <h2 class="text-sm font-semibold text-[var(--color-text-primary)]">Campaign content</h2>
            <p class="mt-1 text-xs text-[var(--color-text-muted)]">Selected channels appear first. Unselected ones remain in the campaign record but will not be queued.</p>
          </div>
          <div class="space-y-3">
            <div v-for="asset in orderedContentAssets" :key="asset.id">
              <div v-if="!selectedContentAssetIds.includes(asset.id)" class="mb-2">
                <Badge variant="neutral">Not selected for delivery</Badge>
              </div>
              <div class="bg-[var(--color-surface-elevated)] border border-[var(--color-border)] rounded-[var(--radius-md)] shadow-[var(--shadow-card)] overflow-hidden">
                <div class="flex flex-wrap items-center gap-2 bg-[var(--color-surface-panel)] px-5 py-3 border-b border-[var(--color-border)]">
                  <Badge variant="muted">{{ asset.type }}</Badge>
                  <span v-if="asset.channel?.type" class="text-xs font-semibold text-[var(--color-text-muted)]">{{ channelLabel(asset.channel.type) }}</span>
                  <ChannelCapabilityBadge
                    v-if="asset.channel?.type"
                    :channel-type="asset.channel.type"
                    :linked-marketing-channel="asset.channel.marketing_channel ? { supportsPublishing: asset.channel.marketing_channel.supports_publishing } : null"
                  />
                </div>
                <div class="p-5">
                  <div v-if="asset.media?.[0]?.url" class="mb-4 overflow-hidden rounded-[var(--radius-md)] bg-[var(--color-surface)] ring-1 ring-[var(--color-border)]">
                    <img :src="asset.media[0].url" alt="Prepared campaign image" class="h-56 w-full object-cover" loading="lazy" />
                  </div>
                  <h3 v-if="asset.title" class="text-base font-semibold text-[var(--color-text-primary)] mb-2">{{ asset.title }}</h3>
                  <p class="text-sm text-[var(--color-text-secondary)] whitespace-pre-line leading-7">{{ asset.body }}</p>
                </div>
              </div>
            </div>
          </div>
        </section>

        <section>
          <div class="mb-3">
            <h2 class="text-sm font-semibold text-[var(--color-text-primary)]">Publishing activity</h2>
            <p class="mt-1 text-xs text-[var(--color-text-muted)]">Each execution shows whether it is live or simulated.</p>
          </div>

          <EmptyState
            v-if="executions.length === 0"
            title="No publishing activity"
            description="Executions appear here as content is scheduled and processed — real for connected channels, simulated for the rest."
          >
            <template #icon><ClockIcon class="size-6" /></template>
          </EmptyState>

          <div v-else class="bg-[var(--color-surface-elevated)] border border-[var(--color-border)] rounded-[var(--radius-md)] shadow-[var(--shadow-card)] divide-y divide-[var(--color-border)] overflow-hidden">
            <div
              v-for="execution in executions"
              :key="execution.id"
              class="flex items-center gap-4 px-5 py-4"
            >
              <div class="size-10 rounded-[var(--radius-sm)] bg-[var(--color-surface)] ring-1 ring-[var(--color-border)] flex items-center justify-center text-[var(--color-text-muted)] shrink-0">
                <PaperAirplaneIcon class="size-5" aria-hidden="true" />
              </div>
              <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2">
                  <p class="text-sm font-semibold text-[var(--color-text-primary)] truncate">{{ execution.channel ? channelLabel(execution.channel.type) : 'Unknown' }}</p>
                  <ChannelCapabilityBadge
                    v-if="execution.channel"
                    :channel-type="execution.channel.type"
                    :linked-marketing-channel="execution.channel.marketing_channel ? { supportsPublishing: execution.channel.marketing_channel.supports_publishing } : null"
                  />
                </div>
                <p class="mt-1 text-xs text-[var(--color-text-muted)]">{{ formatDate(execution.scheduled_at) }}</p>
              </div>
              <Badge :variant="executionStatusVariants[execution.status] ?? 'muted'">
                {{ executionStatusLabels[execution.status] ?? execution.status }}
              </Badge>
            </div>
          </div>
        </section>
      </div>

      <aside class="space-y-4 xl:sticky xl:top-8 xl:self-start">
        <Card padding="none" class="overflow-hidden">
          <div class="bg-[var(--color-surface-panel)] px-5 py-4 border-b border-[var(--color-border)]">
            <div class="flex items-center gap-2">
              <h2 class="text-sm font-semibold text-[var(--color-text-primary)]">Email audience</h2>
              <ChannelCapabilityBadge
                channel-type="email"
                :linked-marketing-channel="email_audience_selector.linked_marketing_channel ? { supportsPublishing: email_audience_selector.linked_marketing_channel.supports_publishing } : null"
              />
            </div>
            <p class="mt-1 text-xs text-[var(--color-text-muted)]">Who receives this campaign if email is connected.</p>
          </div>

          <div class="p-5">
            <form class="space-y-3" @submit.prevent="selectAudience">
              <select
                v-model="audienceForm.email_audience_id"
                class="w-full px-3 py-2 text-sm rounded-[var(--radius-sm)] border border-[var(--color-border)] bg-white text-[var(--color-text-primary)] focus:outline-none focus:ring-1 focus:ring-[var(--color-border-focus)] focus:border-[var(--color-border-focus)] transition-colors duration-[var(--duration-fast)]"
              >
                <option value="">No audience selected</option>
                <option v-for="option in email_audience_selector.audiences" :key="option.id" :value="option.id">
                  {{ option.name }} ({{ option.member_count }})
                </option>
              </select>
              <button
                type="submit"
                :disabled="audienceForm.processing"
                class="w-full py-2 px-4 text-sm font-semibold rounded-[var(--radius-sm)] border border-[var(--color-border-strong)] bg-white text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-panel)] disabled:opacity-60 disabled:cursor-not-allowed transition-colors duration-[var(--duration-fast)]"
              >
                {{ audienceForm.processing ? 'Saving…' : 'Save audience' }}
              </button>
            </form>

            <p v-if="email_audience_selector.selected" class="mt-3 text-xs text-[var(--color-text-muted)]">
              {{ email_audience_selector.selected.member_count }} recipient{{ email_audience_selector.selected.member_count === 1 ? '' : 's' }}
              <span v-if="email_audience_selector.selected.member_count === 0" class="text-amber-600">— this audience is empty, nothing would be sent</span>
            </p>
            <p v-else class="mt-3 text-xs text-[var(--color-text-muted)]">No audience selected yet.</p>

            <div v-if="email_audience_selector.recipient_outcomes" class="mt-5 pt-5 border-t border-[var(--color-border)]">
              <h3 class="text-xs font-semibold text-[var(--color-text-muted)] uppercase tracking-[0.12em] mb-3">Send outcomes</h3>
              <dl class="grid grid-cols-2 gap-3">
                <div v-for="(key) in (['pending', 'accepted', 'failed', 'skipped'] as const)" :key="key" class="rounded-[var(--radius-sm)] bg-[var(--color-surface)] p-3 ring-1 ring-[var(--color-border)]">
                  <dt class="text-xs text-[var(--color-text-muted)] mb-1">{{ recipientOutcomeLabels[key] }}</dt>
                  <dd class="text-lg font-semibold text-[var(--color-text-primary)] tabular-nums">{{ email_audience_selector.recipient_outcomes[key] }}</dd>
                </div>
              </dl>
              <p class="text-xs text-[var(--color-text-muted)] mt-3">
                {{ email_audience_selector.recipient_outcomes.total }} recipient{{ email_audience_selector.recipient_outcomes.total === 1 ? '' : 's' }} targeted in total. "Accepted by provider" does not confirm delivery, opens, or clicks.
              </p>
            </div>
          </div>
        </Card>

        <Card v-if="kpi_snapshot" padding="none" class="overflow-hidden">
          <div class="bg-[var(--color-surface-panel)] px-5 py-4 border-b border-[var(--color-border)]">
            <h2 class="text-sm font-semibold text-[var(--color-text-primary)]">Results</h2>
            <p class="mt-1 text-xs text-[var(--color-text-muted)]">Campaign metrics collected so far.</p>
          </div>
          <dl class="grid grid-cols-1 divide-y divide-[var(--color-border)]">
            <div v-for="(value, key) in kpi_snapshot.actual_kpis" :key="key" class="px-5 py-3">
              <dt class="text-xs text-[var(--color-text-muted)] mb-1 capitalize">{{ String(key).replace(/_/g, ' ') }}</dt>
              <dd class="text-sm font-semibold text-[var(--color-text-primary)] tabular-nums">{{ value }}</dd>
            </div>
          </dl>
        </Card>
      </aside>
    </div>
  </div>
</template>
