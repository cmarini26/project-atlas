<script setup lang="ts">
import type { ContentAsset } from '@/types'
import Badge from '@/Components/UI/Badge.vue'

defineProps<{
  asset: ContentAsset
  editable?: boolean
}>()

defineEmits<{
  edit: []
}>()
</script>

<template>
  <div class="bg-[--color-surface-elevated] border border-[--color-border] rounded-xl p-4">
    <div class="flex items-center justify-between mb-3">
      <div class="flex items-center gap-2">
        <h3 class="text-xs font-semibold text-[--color-text-muted] uppercase tracking-wide">Content preview</h3>
        <Badge variant="muted">{{ asset.type }}</Badge>
      </div>
      <button
        v-if="editable"
        type="button"
        class="text-xs text-[--color-text-link] hover:underline"
        @click="$emit('edit')"
      >
        Edit before approving
      </button>
    </div>

    <h4 v-if="asset.title" class="text-sm font-semibold text-[--color-text-primary] mb-2">{{ asset.title }}</h4>
    <p class="text-sm text-[--color-text-secondary] whitespace-pre-line leading-relaxed">{{ asset.body }}</p>

    <div v-if="asset.metadata && Object.keys(asset.metadata).length > 0" class="mt-3 pt-3 border-t border-[--color-border]">
      <dl class="flex flex-wrap gap-x-4 gap-y-1">
        <div v-for="(value, key) in asset.metadata" :key="key" class="flex items-center gap-1">
          <dt class="text-xs text-[--color-text-muted] capitalize">{{ key }}:</dt>
          <dd class="text-xs text-[--color-text-secondary]">{{ value }}</dd>
        </div>
      </dl>
    </div>
  </div>
</template>
