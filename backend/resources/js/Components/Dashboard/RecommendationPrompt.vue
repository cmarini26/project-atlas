<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import { ArrowRightIcon, SparklesIcon } from '@heroicons/vue/24/outline'
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
  <div class="relative overflow-hidden rounded-[var(--radius-lg)] border border-[var(--color-border-strong)] bg-[var(--color-surface-nav)] p-5 text-white shadow-[var(--shadow-raised)] sm:p-6">
    <div class="absolute inset-x-0 top-0 h-1 bg-[var(--gradient-accent)]" aria-hidden="true" />
    <div class="flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">
      <div class="flex min-w-0 items-start gap-4">
        <div class="size-11 rounded-[var(--radius-md)] bg-white/10 ring-1 ring-white/15 flex items-center justify-center shrink-0">
          <SparklesIcon class="size-5 text-white" aria-hidden="true" />
        </div>
        <div class="min-w-0">
          <p class="text-xs font-semibold uppercase tracking-[0.14em] text-white/58">Decision ready</p>
          <h3 class="mt-1 text-xl font-semibold leading-tight text-white capitalize">
            {{ (recommendation.campaign_type ?? '').replace(/_/g, ' ') }} campaign
          </h3>
          <p v-if="whyNow" class="mt-2 max-w-3xl text-sm leading-6 text-white/74 line-clamp-2">{{ whyNow }}</p>
        </div>
      </div>
      <Link
        :href="`/app/recommendations/${recommendation.id}`"
        class="inline-flex h-10 shrink-0 items-center justify-center gap-2 rounded-[var(--radius-sm)] bg-white px-4 text-sm font-semibold text-[var(--color-surface-nav)] transition-colors duration-[var(--duration-fast)] hover:bg-[var(--color-accent-50)]"
      >
        Review
        <ArrowRightIcon class="size-4" aria-hidden="true" />
      </Link>
    </div>
  </div>
</template>
