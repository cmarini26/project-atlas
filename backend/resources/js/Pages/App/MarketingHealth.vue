<script setup lang="ts">
import { computed } from 'vue'
import { Head } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Badge from '@/Components/UI/Badge.vue'
import EmptyState from '@/Components/UI/EmptyState.vue'
import PageHeader from '@/Components/UI/PageHeader.vue'
import { HeartIcon } from '@heroicons/vue/24/outline'

defineOptions({ layout: AppLayout })

interface Evidence {
  label: string
  source_type: string
  source_id: string | null
  value: unknown
}

interface DimensionScore {
  dimension: string
  score: number
  confidence: number
  evidence: Evidence[]
  computed_at: string
}

interface Composite {
  score: number
  confidence: number
}

const props = defineProps<{
  composite: Composite | null
  dimensions: DimensionScore[]
}>()

const DIMENSION_LABELS: Record<string, string> = {
  website: 'Website Health',
  social_activity: 'Social Activity',
  campaign_consistency: 'Campaign Consistency',
  brand_consistency: 'Brand Consistency',
  content_diversity: 'Content Diversity',
  cta_strength: 'CTA Strength',
  presence_coverage: 'Marketing Presence Coverage',
}

const DIMENSION_ORDER = [
  'website',
  'social_activity',
  'campaign_consistency',
  'brand_consistency',
  'content_diversity',
  'cta_strength',
  'presence_coverage',
]

const orderedDimensions = computed(() => {
  const byKey = new Map(props.dimensions.map((d) => [d.dimension, d]))
  return DIMENSION_ORDER.map((key) => byKey.get(key) ?? null)
})

function scoreBand(score: number): { label: string; variant: 'success' | 'warning' | 'muted' } {
  if (score >= 70) return { label: 'Strong', variant: 'success' }
  if (score >= 40) return { label: 'Developing', variant: 'warning' }
  return { label: 'Needs attention', variant: 'muted' }
}

function formatDate(date: string): string {
  return new Date(date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
}
</script>

<template>
  <Head><title>Marketing Health — Atlas</title></Head>
  <div class="max-w-4xl">
    <PageHeader
      title="Marketing Health"
      description="A deterministic, evidence-based score of how healthy your marketing is today — computed from what Atlas has actually observed, not AI guesswork."
      :icon="HeartIcon"
    />

    <!-- Overall score -->
    <div class="bg-[var(--color-surface-elevated)] border border-[var(--color-border)] rounded-xl p-5 mb-6">
      <h2 class="text-sm font-semibold text-[var(--color-text-primary)] mb-4">Overall Marketing Health</h2>

      <div v-if="composite" class="flex items-center gap-4">
        <p class="text-4xl font-semibold text-[var(--color-text-primary)] tabular-nums">{{ composite.score }}</p>
        <div>
          <Badge :variant="scoreBand(composite.score).variant">{{ scoreBand(composite.score).label }}</Badge>
          <p class="text-xs text-[var(--color-text-muted)] mt-1">Confidence: {{ composite.confidence }}%</p>
        </div>
      </div>

      <EmptyState v-else title="Not enough data yet" description="Connect more sources — a website crawl, Instagram, or a declared marketing channel — and Atlas will start scoring your marketing health." />
    </div>

    <!-- Dimension scores -->
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
      <div
        v-for="(dim, index) in orderedDimensions"
        :key="DIMENSION_ORDER[index]"
        class="bg-[var(--color-surface-elevated)] border border-[var(--color-border)] rounded-xl p-5"
      >
        <div class="flex items-center justify-between mb-2">
          <h3 class="text-sm font-medium text-[var(--color-text-primary)]">{{ DIMENSION_LABELS[DIMENSION_ORDER[index]] }}</h3>
          <Badge v-if="dim" :variant="scoreBand(dim.score).variant">{{ dim.score }}</Badge>
          <Badge v-else variant="muted">N/A</Badge>
        </div>

        <div v-if="dim">
          <p class="text-xs text-[var(--color-text-muted)] mb-2">
            Confidence: {{ dim.confidence }}% &middot; Computed {{ formatDate(dim.computed_at) }}
          </p>

          <details v-if="dim.evidence.length > 0" class="text-xs">
            <summary class="cursor-pointer text-[var(--color-text-link)] hover:underline select-none">
              {{ dim.evidence.length }} supporting evidence item(s)
            </summary>
            <ul class="mt-2 space-y-1.5 pl-3 border-l-2 border-[var(--color-border)]">
              <li v-for="(item, i) in dim.evidence" :key="i" class="text-[var(--color-text-secondary)]">
                {{ item.label }}
              </li>
            </ul>
          </details>
        </div>

        <p v-else class="text-xs text-[var(--color-text-muted)]">Not enough data yet for this dimension.</p>
      </div>
    </div>
  </div>
</template>
