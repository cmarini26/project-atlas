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
  <div v-if="hasAnything" class="bg-[var(--color-surface-elevated)] border border-[var(--color-border)] rounded-xl p-4">
    <h3 class="text-xs font-semibold text-[var(--color-text-muted)] uppercase tracking-wide mb-3">Channel mix</h3>

    <!-- Primary + supporting: what this campaign actually executes on -->
    <div v-if="channelMix.primary.length > 0 || channelMix.supporting.length > 0" class="mb-4">
      <ul class="space-y-2">
        <li v-for="entry in channelMix.primary" :key="`primary-${entry.type}-${entry.name}`" class="flex items-center gap-2">
          <svg class="size-4 text-[var(--color-accent-600)] shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
          <span class="text-sm text-[var(--color-text-primary)]">{{ entry.name }}</span>
          <span class="text-xs text-[var(--color-text-muted)]">Primary</span>
          <ChannelCapabilityBadge :channel-type="entry.type" :linked-marketing-channel="entry.marketing_channel ? { supportsPublishing: entry.marketing_channel.supports_publishing } : null" />
        </li>
        <li v-for="entry in channelMix.supporting" :key="`supporting-${entry.type}-${entry.name}`" class="flex items-center gap-2">
          <svg class="size-4 text-[var(--color-accent-600)] shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
          <span class="text-sm text-[var(--color-text-primary)]">{{ entry.name }}</span>
          <ChannelCapabilityBadge :channel-type="entry.type" :linked-marketing-channel="entry.marketing_channel ? { supportsPublishing: entry.marketing_channel.supports_publishing } : null" />
        </li>
      </ul>
    </div>

    <!-- Draft-only: real marketing presence, no prepared content path yet -->
    <div v-if="channelMix.draft_only.length > 0" class="mb-4 pt-3 border-t border-[var(--color-border)]">
      <p class="text-xs text-[var(--color-text-muted)] mb-2">
        Also part of your marketing presence — Atlas can't prepare content for these yet, but they're valuable context for the campaign's messaging.
      </p>
      <ul class="space-y-2">
        <li v-for="entry in channelMix.draft_only" :key="`draft-${entry.type}-${entry.name}`" class="flex items-center gap-2">
          <span class="size-4 shrink-0" />
          <span class="text-sm text-[var(--color-text-secondary)]">{{ entry.name }}</span>
          <Badge variant="muted">{{ CAPABILITY_LABELS[resolveDeclaredChannelCapability(entry.type)] }}</Badge>
        </li>
      </ul>
    </div>

    <!-- Unavailable: declared, but excluded from this campaign right now -->
    <div v-if="channelMix.unavailable.length > 0" class="pt-3 border-t border-[var(--color-border)]">
      <p class="text-xs text-[var(--color-text-muted)] mb-2">Not included this time:</p>
      <ul class="space-y-1">
        <li v-for="entry in channelMix.unavailable" :key="`unavailable-${entry.type}-${entry.name}`" class="text-sm text-[var(--color-text-muted)]">
          {{ entry.name }} — {{ unavailableReasonLabels[entry.reason] ?? entry.reason }}
        </li>
      </ul>
    </div>
  </div>
</template>
