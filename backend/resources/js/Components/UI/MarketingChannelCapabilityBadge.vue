<script setup lang="ts">
import { computed } from 'vue'
import Badge from '@/Components/UI/Badge.vue'
import {
  MARKETING_CHANNEL_CAPABILITY_DESCRIPTIONS,
  MARKETING_CHANNEL_CAPABILITY_LABELS,
} from '@/lib/marketingChannelCapability'
import type { MarketingChannelCapability } from '@/lib/marketingChannelCapability'

const props = defineProps<{
  capability: MarketingChannelCapability
}>()

const variant = computed(() => {
  switch (props.capability) {
    case 'analytics_enabled':
    case 'publishing_enabled':
      return 'success' as const
    case 'connected':
      return 'accent' as const
    default:
      return 'muted' as const
  }
})
</script>

<template>
  <Badge :variant="variant" :title="MARKETING_CHANNEL_CAPABILITY_DESCRIPTIONS[capability]">
    {{ MARKETING_CHANNEL_CAPABILITY_LABELS[capability] }}
  </Badge>
</template>
