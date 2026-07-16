<script setup lang="ts">
import { computed, ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue'
import { CAPABILITY_LABELS, channelLabel, resolveChannelCapability } from '@/lib/channelCapability'
import type { ContentAsset } from '@/types'

const props = defineProps<{
  recommendationId: string
  contentAssets?: ContentAsset[]
}>()

const emit = defineEmits<{
  editAndApprove: []
}>()

const showConfirm = ref(false)
const showRejectNote = ref(false)
const rejectNote = ref('')
const approveError = ref<string | null>(null)
const rejectError = ref<string | null>(null)

const approveForm = useForm({})
const rejectForm = useForm({ notes: '' })

const assetTypeLabels: Record<string, string> = {
  blog_post: 'blog post',
  email: 'email',
  social_post: 'social post',
  sms: 'SMS message',
  landing_page: 'landing page',
}

// Concrete "what will happen" lines shown in the confirmation dialog — one
// per content asset, naming the content, its destination channel, and that
// channel's real capability today. See docs/product/Channel-Capability-Matrix.md
// for the canonical per-channel truth this branches on: a channel with a
// linked, publishing-verified MarketingChannel (WordPress/Meta/Email, once
// connected) really sends; every other channel still only logs internally.
// Must never say "not yet sent live" for a channel that actually is.
const approvalEffects = computed(() =>
  (props.contentAssets ?? []).map((asset) => {
    const type = assetTypeLabels[asset.type] ?? asset.type.replace(/_/g, ' ')
    const title = asset.title ? ` “${asset.title}”` : ''
    const channelType = asset.channel?.type

    if (!channelType) {
      return `Queue the ${type}${title} for delivery.`
    }

    const label = channelLabel(channelType)
    const linkedMarketingChannel = asset.channel?.marketing_channel
      ? { supportsPublishing: asset.channel.marketing_channel.supports_publishing }
      : null
    const capability = resolveChannelCapability(channelType, linkedMarketingChannel)
    const capabilityNote = CAPABILITY_LABELS[capability]
    const outcome = capability === 'connected'
      ? 'will really send to this connected channel'
      : 'logged internally, not yet sent live'

    return `Queue the ${type}${title} for ${label} — ${capabilityNote}: ${outcome}.`
  }),
)

function requestApproval(): void {
  approveError.value = null
  showConfirm.value = true
}

function approve(): void {
  approveError.value = null
  approveForm.post(`/app/recommendations/${props.recommendationId}/approve`, {
    preserveScroll: true,
    onSuccess: () => {
      showConfirm.value = false
    },
    onError: () => {
      showConfirm.value = false
      approveError.value = 'Something went wrong. Please try again.'
    },
  })
}

function reject(): void {
  rejectError.value = null
  rejectForm.notes = rejectNote.value
  rejectForm.post(`/app/recommendations/${props.recommendationId}/reject`, {
    preserveScroll: true,
    onError: () => {
      rejectError.value = 'Something went wrong. Please try again.'
    },
  })
}
</script>

<template>
  <div class="space-y-4">
    <div class="flex flex-col sm:flex-row gap-3">
      <button
        type="button"
        :disabled="approveForm.processing || rejectForm.processing"
        class="flex-1 py-2.5 px-4 text-sm font-medium rounded-lg bg-[var(--color-accent-500)] text-white hover:bg-[var(--color-accent-600)] disabled:opacity-60 disabled:cursor-not-allowed transition-colors duration-[var(--duration-fast)]"
        @click="requestApproval"
      >
        {{ approveForm.processing ? 'Approving…' : 'Approve' }}
      </button>

      <button
        type="button"
        :disabled="approveForm.processing || rejectForm.processing"
        class="flex-1 py-2.5 px-4 text-sm font-medium rounded-lg border border-[var(--color-border)] text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-subtle)] disabled:opacity-60 disabled:cursor-not-allowed transition-colors duration-[var(--duration-fast)]"
        @click="emit('editAndApprove')"
      >
        Edit &amp; Approve
      </button>

      <button
        type="button"
        :disabled="approveForm.processing || rejectForm.processing"
        class="flex-1 py-2.5 px-4 text-sm font-medium rounded-lg border border-[var(--color-border)] text-[var(--color-text-muted)] hover:bg-[var(--color-surface-subtle)] disabled:opacity-60 disabled:cursor-not-allowed transition-colors duration-[var(--duration-fast)]"
        @click="showRejectNote = !showRejectNote"
      >
        Not this time
      </button>
    </div>

    <p v-if="approveError" class="text-sm text-rose-600" role="alert">{{ approveError }}</p>

    <p class="text-xs text-[var(--color-text-muted)]">
      Approving queues this content for delivery. Until a live channel is connected, delivery is simulated and logged internally — nothing is sent to a real platform yet. You can make edits before approving if anything needs changing.
    </p>

    <div v-if="showRejectNote" class="space-y-2">
      <label for="reject-note" class="block text-xs font-medium text-[var(--color-text-muted)] uppercase tracking-widest">Help Atlas learn (optional)</label>
      <textarea
        id="reject-note"
        v-model="rejectNote"
        rows="2"
        class="w-full px-3 py-2 text-sm rounded-lg border border-[var(--color-border)] bg-[var(--color-surface-elevated)] text-[var(--color-text-primary)] resize-none focus:outline-none focus:ring-1 focus:ring-[var(--color-border-focus)]"
        placeholder="Tell Atlas why (helps it learn)"
      />
      <p v-if="rejectError" class="text-sm text-rose-600" role="alert">{{ rejectError }}</p>
      <button
        type="button"
        :disabled="rejectForm.processing"
        class="w-full py-2 px-4 text-sm font-medium rounded-lg border border-[var(--color-border)] text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-subtle)] disabled:opacity-60 disabled:cursor-not-allowed transition-colors duration-[var(--duration-fast)]"
        @click="reject"
      >
        {{ rejectForm.processing ? 'Passing…' : 'Confirm: not this time' }}
      </button>
    </div>

    <!-- Approval confirmation: spells out exactly what approving will do -->
    <ConfirmDialog
      :open="showConfirm"
      title="Approve this recommendation?"
      :confirm-label="approveForm.processing ? 'Approving…' : 'Approve'"
      :processing="approveForm.processing"
      @confirm="approve"
      @cancel="showConfirm = false"
    >
      <ul v-if="approvalEffects.length > 0" class="list-disc pl-4 space-y-1 mb-3">
        <li v-for="(effect, index) in approvalEffects" :key="index">{{ effect }}</li>
      </ul>
      <p v-else class="mb-3">Atlas will queue this campaign's content for internal processing. No live channels are connected yet, so nothing is sent externally.</p>
      <p class="text-xs text-[var(--color-text-muted)]">
        Processing starts right after you approve. You can follow progress on the Publishing page — each entry there is only real for channels marked "Connected" above; everything else remains simulated and logged internally.
      </p>
    </ConfirmDialog>
  </div>
</template>
