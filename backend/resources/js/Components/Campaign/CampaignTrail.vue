<script setup lang="ts">
import { computed } from 'vue'
import type { CampaignStatus } from '@/types'

const props = defineProps<{
  status: CampaignStatus
}>()

const STEPS: { key: CampaignStatus; label: string }[] = [
  { key: 'draft', label: 'Draft' },
  { key: 'approved', label: 'Approved' },
  { key: 'active', label: 'Active' },
  { key: 'published', label: 'Published' },
  { key: 'completed', label: 'Completed' },
]

// 'cancelled' doesn't fit a linear progression — there's no record of which
// step it was cancelled at, so the trail simply isn't rendered for it (the
// status Badge elsewhere on the page already communicates "cancelled").
const currentIndex = computed(() => STEPS.findIndex((s) => s.key === props.status))
</script>

<template>
  <ol v-if="currentIndex !== -1" class="flex items-center" aria-label="Campaign lifecycle">
    <li v-for="(step, index) in STEPS" :key="step.key" class="flex items-center" :class="{ 'flex-1': index < STEPS.length - 1 }">
      <div class="flex flex-col items-center gap-1.5">
        <div
          :class="[
            'size-3 rounded-full shrink-0',
            index < currentIndex ? 'bg-[var(--color-accent-500)]' : '',
            index === currentIndex ? 'bg-[var(--color-accent-500)] ring-4 ring-[var(--color-accent-100)]' : '',
            index > currentIndex ? 'border-2 border-[var(--color-border-strong)] bg-[var(--color-surface-elevated)]' : '',
          ]"
          :aria-current="index === currentIndex ? 'step' : undefined"
        />
        <span
          :class="[
            'text-xs whitespace-nowrap',
            index <= currentIndex ? 'text-[var(--color-text-secondary)] font-medium' : 'text-[var(--color-text-placeholder)]',
          ]"
        >
          {{ step.label }}
        </span>
      </div>
      <div
        v-if="index < STEPS.length - 1"
        :class="['h-px flex-1 mx-1 mb-4', index < currentIndex ? 'bg-[var(--color-accent-500)]' : 'bg-[var(--color-border)]']"
      />
    </li>
  </ol>
</template>
