<script setup lang="ts">
import { computed } from 'vue'
import Badge from '@/Components/UI/Badge.vue'
import { CAPABILITY_DESCRIPTIONS, CAPABILITY_LABELS, channelCapability } from '@/lib/channelCapability'

const props = defineProps<{
  channelType: string
}>()

const capability = computed(() => channelCapability(props.channelType))

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
