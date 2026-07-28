<script setup lang="ts">
import Badge from '@/Components/UI/Badge.vue'
import ChannelCapabilityBadge from '@/Components/UI/ChannelCapabilityBadge.vue'
import { CAPABILITY_LABELS, resolveDeclaredChannelCapability } from '@/lib/channelCapability'
import type { ChannelMix } from '@/types'

const props = defineProps<{
  channelMix: ChannelMix
}>()

const hasAnything =
  props.channelMix.primary.length > 0 ||
  props.channelMix.supporting.length > 0 ||
  props.channelMix.draft_only.length > 0 ||
  props.channelMix.unavailable.length > 0

const unavailableReasonLabels: Record<string, string> = {
  inactive: 'no longer active',
  planned: 'planned, not started yet',
}
</script>

<template>
  <div v-if="hasAnything" class="bg-[var(--color-surface-elevated)] border border-[var(--color-border)] rounded-[var(--radius-md)] shadow-[var(--shadow-card)] overflow-hidden">
    <div class="bg-[var(--color-surface-panel)] px-5 py-4 border-b border-[var(--color-border)]">
      <h3 class="text-sm font-semibold text-[var(--color-text-primary)]">Channel mix</h3>
      <p class="mt-1 text-xs text-[var(--color-text-muted)]">Where this campaign can actually run, and where Atlas is only using context.</p>
    </div>

    <!-- Primary + supporting: what this campaign actually executes on -->
    <div v-if="channelMix.primary.length > 0 || channelMix.supporting.length > 0" class="p-5">
      <ul class="grid gap-3 sm:grid-cols-2">
        <li v-for="entry in channelMix.primary" :key="`primary-${entry.type}-${entry.name}`" class="rounded-[var(--radius-sm)] border border-[var(--color-border)] bg-[var(--color-surface)] p-3">
          <div class="flex items-center gap-2">
            <svg class="size-4 text-[var(--color-accent-600)] shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
            <span class="text-sm font-semibold text-[var(--color-text-primary)]">{{ entry.name }}</span>
          </div>
          <div class="mt-2 flex items-center gap-2">
            <span class="text-xs font-semibold uppercase tracking-[0.12em] text-[var(--color-text-muted)]">Primary</span>
            <ChannelCapabilityBadge :channel-type="entry.type" :linked-marketing-channel="entry.marketing_channel ? { supportsPublishing: entry.marketing_channel.supports_publishing } : null" />
          </div>
        </li>
        <li v-for="entry in channelMix.supporting" :key="`supporting-${entry.type}-${entry.name}`" class="rounded-[var(--radius-sm)] border border-[var(--color-border)] bg-[var(--color-surface)] p-3">
          <div class="flex items-center gap-2">
            <svg class="size-4 text-[var(--color-accent-600)] shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
            <span class="text-sm font-semibold text-[var(--color-text-primary)]">{{ entry.name }}</span>
          </div>
          <div class="mt-2">
            <ChannelCapabilityBadge :channel-type="entry.type" :linked-marketing-channel="entry.marketing_channel ? { supportsPublishing: entry.marketing_channel.supports_publishing } : null" />
          </div>
        </li>
      </ul>
    </div>

    <!-- Draft-only: real marketing presence, no prepared content path yet -->
    <div v-if="channelMix.draft_only.length > 0" class="px-5 py-4 border-t border-[var(--color-border)] bg-[var(--color-surface-panel)]">
      <p class="text-xs text-[var(--color-text-muted)] mb-3">
        Also part of your marketing presence — Atlas can't prepare content for these yet, but they're valuable context for the campaign's messaging.
      </p>
      <ul class="flex flex-wrap gap-2">
        <li v-for="entry in channelMix.draft_only" :key="`draft-${entry.type}-${entry.name}`" class="inline-flex items-center gap-2 rounded-[var(--radius-sm)] bg-white px-2.5 py-1.5 ring-1 ring-[var(--color-border)]">
          <span class="text-sm font-medium text-[var(--color-text-secondary)]">{{ entry.name }}</span>
          <Badge variant="muted">{{ CAPABILITY_LABELS[resolveDeclaredChannelCapability(entry.type)] }}</Badge>
        </li>
      </ul>
    </div>

    <!-- Unavailable: declared, but excluded from this campaign right now -->
    <div v-if="channelMix.unavailable.length > 0" class="px-5 py-4 border-t border-[var(--color-border)]">
      <p class="text-xs font-semibold text-[var(--color-text-muted)] uppercase tracking-[0.12em] mb-2">Not included this time</p>
      <ul class="space-y-1">
        <li v-for="entry in channelMix.unavailable" :key="`unavailable-${entry.type}-${entry.name}`" class="text-sm text-[var(--color-text-muted)]">
          {{ entry.name }} — {{ unavailableReasonLabels[entry.reason] ?? entry.reason }}
        </li>
      </ul>
    </div>
  </div>
</template>
