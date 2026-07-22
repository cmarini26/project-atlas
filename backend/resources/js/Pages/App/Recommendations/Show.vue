<script setup lang="ts">
import { computed, ref } from 'vue'
import { Head, useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import RationaleCard from '@/Components/Recommendations/RationaleCard.vue'
import ImpactCard from '@/Components/Recommendations/ImpactCard.vue'
import ChannelMixCard from '@/Components/Recommendations/ChannelMixCard.vue'
import ContentPreview from '@/Components/Recommendations/ContentPreview.vue'
import ContentEditor from '@/Components/Recommendations/ContentEditor.vue'
import ApproveActions from '@/Components/Recommendations/ApproveActions.vue'
import Badge from '@/Components/UI/Badge.vue'
import Card from '@/Components/UI/Card.vue'
import PageHeader from '@/Components/UI/PageHeader.vue'
import ChannelCapabilityBadge from '@/Components/UI/ChannelCapabilityBadge.vue'
import { channelLabel } from '@/lib/channelCapability'
import { ArrowLeftIcon, SparklesIcon } from '@heroicons/vue/24/outline'
import type { Recommendation, DecisionDetail, ContentAsset, ChannelMix } from '@/types'

// Persistent layout: the sidebar/toast shell survives Inertia visits.
defineOptions({ layout: AppLayout })

const props = defineProps<{
  recommendation: Recommendation
  decision: DecisionDetail | null
  channel_mix: ChannelMix
  content_assets: ContentAsset[]
  selected_content_asset_ids: string[]
}>()

const editingAsset = ref<ContentAsset | null>(null)
const selectedContentAssetIds = ref<string[]>([...props.selected_content_asset_ids])

const editForm = useForm<{
  content_asset_id: string
  title: string
  body: string
  notes: string
  selected_content_asset_ids: string[]
}>({
  content_asset_id: '',
  title: '',
  body: '',
  notes: '',
  selected_content_asset_ids: [...props.selected_content_asset_ids],
})

const channelForm = useForm<{ selected_content_asset_ids: string[] }>({
  selected_content_asset_ids: [...props.selected_content_asset_ids],
})

const statusLabels: Record<string, string> = {
  pending: 'Pending review',
  approved: 'Approved',
  rejected: 'Passed',
  expired: 'Expired',
}

const statusVariants: Record<string, 'accent' | 'success' | 'neutral' | 'muted'> = {
  pending: 'accent',
  approved: 'success',
  rejected: 'neutral',
  expired: 'muted',
}

const isPending = props.recommendation.status === 'pending'

const selectedCount = computed(() => selectedContentAssetIds.value.length)

const selectedContentAssets = computed(() =>
  props.content_assets.filter((asset) => selectedContentAssetIds.value.includes(asset.id)),
)

const orderedContentAssets = computed(() => {
  const selected = props.content_assets.filter((asset) => selectedContentAssetIds.value.includes(asset.id))
  const unselected = props.content_assets.filter((asset) => !selectedContentAssetIds.value.includes(asset.id))
  return [...selected, ...unselected]
})

function syncSelectedIds(): void {
  channelForm.selected_content_asset_ids = [...selectedContentAssetIds.value]
  editForm.selected_content_asset_ids = [...selectedContentAssetIds.value]
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
  channelForm.patch(`/app/recommendations/${props.recommendation.id}/channels`, {
    preserveScroll: true,
  })
}

function startEdit(asset: ContentAsset): void {
  editingAsset.value = asset
}

function cancelEdit(): void {
  editingAsset.value = null
}

function saveEdit(payload: { title: string; body: string }): void {
  if (!editingAsset.value) return

  syncSelectedIds()
  editForm.content_asset_id = editingAsset.value.id
  editForm.title = payload.title
  editForm.body = payload.body

  editForm.post(`/app/recommendations/${props.recommendation.id}/approve-edit`, {
    onSuccess: () => { editingAsset.value = null },
  })
}
</script>

<template>
  <Head><title>Recommendation — Atlas</title></Head>
  <div>
    <div class="mb-5">
      <Link
        href="/app/recommendations"
        class="inline-flex items-center gap-2 text-sm font-semibold text-[var(--color-text-link)] hover:underline"
      >
        <ArrowLeftIcon class="size-4" aria-hidden="true" />
        Recommendations
      </Link>
    </div>

    <PageHeader
      :title="`${(recommendation.campaign_type ?? '').replace(/_/g, ' ')} campaign`"
      description="Choose the delivery channels, inspect the prepared content and imagery, then approve, edit, or pass."
      :icon="SparklesIcon"
    >
      <template #actions>
        <Badge :variant="statusVariants[recommendation.status] ?? 'muted'">
          {{ statusLabels[recommendation.status] ?? recommendation.status }}
        </Badge>
      </template>
    </PageHeader>

    <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_360px]">
      <div class="space-y-6">
        <Card v-if="isPending && content_assets.length > 0" padding="none" class="overflow-hidden">
          <div class="bg-[var(--color-surface-panel)] px-5 py-4 border-b border-[var(--color-border)]">
            <h2 class="text-sm font-semibold text-[var(--color-text-primary)]">Delivery channels</h2>
            <p class="mt-1 text-xs text-[var(--color-text-muted)]">
              Pick where this campaign should go. Atlas will only queue the selected channels when you approve.
            </p>
          </div>
          <div class="p-5 space-y-4">
            <div class="grid gap-3 md:grid-cols-2">
              <label
                v-for="asset in content_assets"
                :key="asset.id"
                class="flex gap-3 rounded-[var(--radius-md)] border border-[var(--color-border)] bg-[var(--color-surface-elevated)] p-3 transition-colors"
                :class="selectedContentAssetIds.includes(asset.id) ? 'ring-1 ring-[var(--color-accent-200)] border-[var(--color-accent-200)]' : ''"
              >
                <input
                  :checked="selectedContentAssetIds.includes(asset.id)"
                  type="checkbox"
                  class="mt-1 size-4 rounded border-[var(--color-border)] text-[var(--color-accent-600)] focus:ring-[var(--color-accent-500)]"
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

        <section v-if="Object.keys(recommendation.rationale_display ?? {}).length > 0">
          <div class="mb-3">
            <h2 class="text-sm font-semibold text-[var(--color-text-primary)]">Why Atlas recommends this</h2>
            <p class="mt-1 text-xs text-[var(--color-text-muted)]">The reasoning Atlas used before preparing content.</p>
          </div>
          <RationaleCard :rationale-display="recommendation.rationale_display" />
        </section>

        <section>
          <ChannelMixCard :channel-mix="channel_mix" />
        </section>

        <section v-if="orderedContentAssets.length > 0">
          <div class="mb-3 flex items-center justify-between gap-3">
            <div>
              <h2 class="text-sm font-semibold text-[var(--color-text-primary)]">Prepared content</h2>
              <p class="mt-1 text-xs text-[var(--color-text-muted)]">Edit anything before approving if it doesn't sound right.</p>
            </div>
            <p class="text-xs text-[var(--color-text-muted)]">Selected channels appear first.</p>
          </div>
          <div class="space-y-3">
            <template v-for="asset in orderedContentAssets" :key="asset.id">
              <div v-if="!selectedContentAssetIds.includes(asset.id)" class="mb-2">
                <Badge variant="neutral">Not selected for delivery</Badge>
              </div>
              <ContentEditor
                v-if="editingAsset?.id === asset.id"
                :asset="asset"
                :processing="editForm.processing"
                @cancel="cancelEdit"
                @save="saveEdit"
              />
              <ContentPreview
                v-else
                :asset="asset"
                :editable="isPending && selectedContentAssetIds.includes(asset.id)"
                @edit="startEdit(asset)"
              />
            </template>
          </div>
        </section>
      </div>

      <aside class="space-y-4 xl:sticky xl:top-8 xl:self-start">
        <ImpactCard
          v-if="decision?.expected_impact && Object.keys(decision.expected_impact).length > 0"
          :impact="decision.expected_impact"
        />

        <Card v-if="isPending && !editingAsset" padding="none" class="overflow-hidden">
          <div class="bg-[var(--color-surface-panel)] px-5 py-4 border-b border-[var(--color-border)]">
            <h2 class="text-sm font-semibold text-[var(--color-text-primary)]">Your decision</h2>
            <p class="mt-1 text-xs text-[var(--color-text-muted)]">Nothing goes out without your approval.</p>
          </div>
          <div class="p-5">
            <ApproveActions
              :recommendation-id="recommendation.id"
              :content-assets="selectedContentAssets"
              :selected-content-asset-ids="selectedContentAssetIds"
              @edit-and-approve="selectedContentAssets[0] && startEdit(selectedContentAssets[0])"
            />
          </div>
        </Card>
      </aside>
    </div>
  </div>
</template>
