<script setup lang="ts">
import { ref } from 'vue'
import type { ContentAsset } from '@/types'

const props = defineProps<{
  asset: ContentAsset
  processing?: boolean
}>()

const emit = defineEmits<{
  cancel: []
  save: [{ title: string; body: string }]
}>()

const title = ref(props.asset.title ?? '')
const body = ref(props.asset.body)
</script>

<template>
  <div class="bg-[var(--color-surface-elevated)] border border-[var(--color-accent-200)] rounded-xl p-4">
    <div class="flex items-center justify-between mb-3">
      <h3 class="text-xs font-semibold text-[var(--color-text-muted)] uppercase tracking-wide">Edit content</h3>
      <button
        type="button"
        class="text-xs text-[var(--color-text-muted)] hover:text-[var(--color-text-secondary)]"
        @click="$emit('cancel')"
      >
        Cancel
      </button>
    </div>

    <div class="space-y-3">
      <div v-if="asset.title !== undefined">
        <label class="block text-xs font-medium text-[var(--color-text-secondary)] mb-1">Title</label>
        <input
          v-model="title"
          type="text"
          class="w-full px-3 py-2 text-sm rounded-lg border border-[var(--color-border)] bg-white text-[var(--color-text-primary)] focus:outline-none focus:ring-1 focus:ring-[var(--color-border-focus)] focus:border-[var(--color-border-focus)]"
        />
      </div>

      <div>
        <label class="block text-xs font-medium text-[var(--color-text-secondary)] mb-1">Body</label>
        <textarea
          v-model="body"
          rows="6"
          class="w-full px-3 py-2 text-sm rounded-lg border border-[var(--color-border)] bg-white text-[var(--color-text-primary)] resize-y focus:outline-none focus:ring-1 focus:ring-[var(--color-border-focus)] focus:border-[var(--color-border-focus)]"
        />
      </div>

      <button
        type="button"
        :disabled="processing"
        class="w-full py-2 px-4 text-sm font-medium rounded-lg bg-[var(--color-accent-600)] text-white hover:bg-[var(--color-accent-700)] disabled:opacity-60 disabled:cursor-not-allowed transition-colors duration-[var(--duration-fast)]"
        @click="$emit('save', { title, body })"
      >
        {{ processing ? 'Saving…' : 'Save & approve' }}
      </button>
    </div>
  </div>
</template>
