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
  <div class="bg-[var(--color-surface-elevated)] border border-[var(--color-border)] rounded-[var(--radius-md)] shadow-[var(--shadow-card)] overflow-hidden">
    <div class="flex items-center justify-between gap-3 bg-[var(--color-surface-panel)] px-5 py-3 border-b border-[var(--color-border)]">
      <div class="flex items-center gap-2">
        <h3 class="text-xs font-semibold text-[var(--color-text-muted)] uppercase tracking-[0.12em]">Content preview</h3>
        <Badge variant="muted">{{ asset.type }}</Badge>
      </div>
      <button
        v-if="editable"
        type="button"
        class="shrink-0 rounded-[var(--radius-sm)] px-2.5 py-1.5 text-xs font-semibold text-[var(--color-text-link)] hover:bg-[var(--color-accent-50)] hover:underline"
        @click="$emit('edit')"
      >
        Edit
      </button>
    </div>

    <div class="p-5">
      <div v-if="asset.media?.[0]?.url" class="mb-4 overflow-hidden rounded-[var(--radius-md)] bg-[var(--color-surface)] ring-1 ring-[var(--color-border)]">
        <img
          :src="asset.media[0].url"
          alt="Prepared campaign image"
          class="h-56 w-full object-cover"
          loading="lazy"
        />
      </div>

      <h4 v-if="asset.title" class="text-base font-semibold text-[var(--color-text-primary)] mb-2">{{ asset.title }}</h4>
      <p class="text-sm text-[var(--color-text-secondary)] whitespace-pre-line leading-7">{{ asset.body }}</p>

      <div v-if="asset.metadata && Object.keys(asset.metadata).length > 0" class="mt-4 pt-4 border-t border-[var(--color-border)]">
        <dl class="flex flex-wrap gap-x-4 gap-y-1">
          <div v-for="(value, key) in asset.metadata" :key="key" class="flex items-center gap-1">
            <dt class="text-xs text-[var(--color-text-muted)] capitalize">{{ key }}:</dt>
            <dd class="text-xs text-[var(--color-text-secondary)]">{{ value }}</dd>
          </div>
        </dl>
      </div>
    </div>
  </div>
</template>
