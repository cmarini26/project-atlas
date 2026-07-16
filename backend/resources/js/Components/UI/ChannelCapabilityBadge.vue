<script setup lang="ts">
import { computed } from 'vue'
import Badge from '@/Components/UI/Badge.vue'
import { CAPABILITY_DESCRIPTIONS, CAPABILITY_LABELS, resolveChannelCapability } from '@/lib/channelCapability'
import type { LinkedMarketingChannel } from '@/lib/channelCapability'

const props = defineProps<{
  channelType: string
  linkedMarketingChannel?: LinkedMarketingChannel | null
}>()

const capability = computed(() => resolveChannelCapability(props.channelType, props.linkedMarketingChannel))

const variant = computed(() => {
  switch (capability.value) {
    case 'connected':
      return 'success' as const
    case 'draft_only':
      return 'warning' as const
    case 'not_configured':
      // Distinct from coming_later's muted gray — this state is actionable
      // by the user right now (connect it in Settings), unlike coming_later
      // (nothing to configure yet, for anyone).
      return 'info' as const
    default:
      return 'muted' as const
  }
})
</script>

<template>
  <Badge :variant="variant" :title="CAPABILITY_DESCRIPTIONS[capability]">
    {{ CAPABILITY_LABELS[capability] }}
  </Badge>
</template>
