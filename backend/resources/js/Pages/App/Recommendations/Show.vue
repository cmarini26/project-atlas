<script setup lang="ts">
import { ref } from 'vue'
import { Head, useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import RationaleCard from '@/Components/Recommendations/RationaleCard.vue'
import ImpactCard from '@/Components/Recommendations/ImpactCard.vue'
import ChannelMixCard from '@/Components/Recommendations/ChannelMixCard.vue'
import ContentPreview from '@/Components/Recommendations/ContentPreview.vue'
import ContentEditor from '@/Components/Recommendations/ContentEditor.vue'
import ApproveActions from '@/Components/Recommendations/ApproveActions.vue'
import Badge from '@/Components/UI/Badge.vue'
import type { Recommendation, DecisionDetail, ContentAsset, ChannelMix } from '@/types'

// Persistent layout: the sidebar/toast shell survives Inertia visits.
defineOptions({ layout: AppLayout })

const props = defineProps<{
  recommendation: Recommendation
  decision: DecisionDetail | null
  channel_mix: ChannelMix
  content_assets: ContentAsset[]
}>()

const editingAsset = ref<ContentAsset | null>(null)

const editForm = useForm<{
  content_asset_id: string
  title: string
  body: string
  notes: string
}>({
  content_asset_id: '',
  title: '',
  body: '',
  notes: '',
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

function startEdit(asset: ContentAsset): void {
  editingAsset.value = asset
}

function cancelEdit(): void {
  editingAsset.value = null
}

function saveEdit(payload: { title: string; body: string }): void {
  if (!editingAsset.value) return

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
  <div class="max-w-3xl">
    <!-- Header -->
    <div class="flex items-start gap-3 mb-6">
      <Link
        href="/app/recommendations"
        class="mt-0.5 text-[var(--color-text-muted)] hover:text-[var(--color-text-secondary)] transition-colors duration-[var(--duration-fast)]"
        aria-label="Back"
      >
        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" /></svg>
      </Link>
      <div class="flex-1 min-w-0">
        <div class="flex items-center gap-2 mb-1">
          <Badge :variant="statusVariants[recommendation.status] ?? 'muted'">
            {{ statusLabels[recommendation.status] ?? recommendation.status }}
          </Badge>
        </div>
        <h1 class="text-xl font-semibold text-[var(--color-text-primary)] capitalize">
          {{ (recommendation.campaign_type ?? '').replace(/_/g, ' ') }} campaign
        </h1>
      </div>
    </div>

    <!-- Rationale -->
    <section v-if="Object.keys(recommendation.rationale_display ?? {}).length > 0" class="mb-6">
      <h2 class="text-xs font-semibold text-[var(--color-text-muted)] uppercase tracking-wide mb-3">Why Atlas recommends this</h2>
      <RationaleCard :rationale-display="recommendation.rationale_display" />
    </section>

    <!-- Channel mix: the campaign as a coordinated marketing plan -->
    <section class="mb-6">
      <ChannelMixCard :channel-mix="channel_mix" />
    </section>

    <!-- Expected impact -->
    <section v-if="decision?.expected_impact && Object.keys(decision.expected_impact).length > 0" class="mb-6">
      <ImpactCard :impact="decision.expected_impact" />
    </section>

    <!-- Content assets -->
    <section v-if="content_assets.length > 0" class="mb-6">
      <h2 class="text-xs font-semibold text-[var(--color-text-muted)] uppercase tracking-wide mb-3">Content</h2>
      <div class="space-y-3">
        <template v-for="asset in content_assets" :key="asset.id">
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
            :editable="isPending"
            @edit="startEdit(asset)"
          />
        </template>
      </div>
    </section>

    <!-- Approve actions (pending only) -->
    <section v-if="isPending && !editingAsset" class="bg-[var(--color-surface-elevated)] border border-[var(--color-border)] rounded-xl p-4">
      <h2 class="text-xs font-semibold text-[var(--color-text-muted)] uppercase tracking-wide mb-3">Your decision</h2>
      <ApproveActions
        :recommendation-id="recommendation.id"
        :content-assets="content_assets"
        @edit-and-approve="content_assets[0] && startEdit(content_assets[0])"
      />
    </section>
  </div>
</template>
