<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import type { Recommendation } from '@/types'

const props = defineProps<{
  recommendation: Recommendation
}>()

// rationale_display is a flat Record<string, string> e.g. { why_now: '...', why_this: '...' }
const whyNow = props.recommendation.rationale_display?.why_now
  ?? props.recommendation.rationale_display?.why
  ?? Object.values(props.recommendation.rationale_display ?? {})[0]
  ?? null
</script>

<template>
  <div class="bg-[var(--color-surface-elevated)] border border-[var(--color-accent-200)] rounded-xl p-5">
    <div class="flex items-start gap-3">
      <div class="size-8 rounded-lg bg-[var(--color-accent-50)] flex items-center justify-center shrink-0 mt-0.5">
        <svg class="size-4 text-[var(--color-accent-600)]" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 0 0-2.456 2.456ZM16.894 20.567 16.5 21.75l-.394-1.183a2.25 2.25 0 0 0-1.423-1.423L13.5 18.75l1.183-.394a2.25 2.25 0 0 0 1.423-1.423l.394-1.183.394 1.183a2.25 2.25 0 0 0 1.423 1.423l1.183.394-1.183.394a2.25 2.25 0 0 0-1.423 1.423Z" /></svg>
      </div>
      <div class="flex-1 min-w-0">
        <p class="text-xs font-medium text-[var(--color-accent-600)] mb-1">Atlas recommends</p>
        <h3 class="text-sm font-semibold text-[var(--color-text-primary)] mb-1.5 capitalize">
          {{ (recommendation.campaign_type ?? '').replace(/_/g, ' ') }} campaign
        </h3>
        <p v-if="whyNow" class="text-sm text-[var(--color-text-secondary)] line-clamp-2">{{ whyNow }}</p>
        <Link
          :href="`/app/recommendations/${recommendation.id}`"
          class="mt-3 inline-flex items-center gap-1.5 text-sm font-medium text-[var(--color-accent-600)] hover:text-[var(--color-accent-700)] transition-colors duration-[var(--duration-fast)]"
        >
          Review &amp; approve
          <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
        </Link>
      </div>
    </div>
  </div>
</template>
