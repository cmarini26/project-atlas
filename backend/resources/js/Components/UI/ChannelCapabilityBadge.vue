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
