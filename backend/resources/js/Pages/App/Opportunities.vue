<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Badge from '@/Components/UI/Badge.vue'
import ScoreBar from '@/Components/UI/ScoreBar.vue'
import EmptyState from '@/Components/UI/EmptyState.vue'
import type { Opportunity } from '@/types'

// Persistent layout: the sidebar/toast shell survives Inertia visits.
defineOptions({ layout: AppLayout })

defineProps<{
  opportunities: Opportunity[]
}>()

const typeLabels: Record<string, string> = {
  featured_item: 'Featured Item',
  urgency_promotion: 'Urgency Promotion',
  new_arrival: 'New Arrival',
  re_engagement: 'Re-engagement',
}

function formatTimeRemaining(expiresAt: string | null): { text: string; urgency: 'none' | 'amber' | 'rose' } {
  if (!expiresAt) return { text: '', urgency: 'none' }

  const ms = new Date(expiresAt).getTime() - Date.now()
  if (ms <= 0) return { text: 'Expired', urgency: 'rose' }

  const hours = ms / (1000 * 60 * 60)

  if (hours < 24) {
    const h = Math.floor(hours)
    const m = Math.floor((ms % (1000 * 60 * 60)) / (1000 * 60))
    return { text: `Expires in ${h}h ${m}m`, urgency: 'rose' }
  }

  if (hours < 48) {
    const h = Math.floor(hours)
    const m = Math.floor((ms % (1000 * 60 * 60)) / (1000 * 60))
    return { text: `Expires in ${h}h ${m}m`, urgency: 'amber' }
  }

  const days = Math.floor(hours / 24)
  if (days <= 7) {
    return { text: `Expires in ${days} day${days !== 1 ? 's' : ''}`, urgency: 'none' }
  }

  return {
    text: `Expires ${new Date(expiresAt).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })}`,
    urgency: 'none',
  }
}

const urgencyClass: Record<string, string> = {
  none: 'text-[var(--color-text-muted)]',
  amber: 'text-amber-700 font-medium',
  rose: 'text-rose-700 font-medium',
}
</script>

<template>
  <Head><title>Opportunities — Atlas</title></Head>
  <div class="max-w-3xl">
    <h1 class="text-xl font-semibold text-[var(--color-text-primary)] mb-6">Opportunities</h1>

    <EmptyState
      v-if="opportunities.length === 0"
      title="No open opportunities"
      description="Atlas is scanning your business for growth opportunities. Check back soon."
    />

    <div v-else class="space-y-3">
      <div
        v-for="opp in opportunities"
        :key="opp.id"
        class="bg-[var(--color-surface-elevated)] border border-[var(--color-border)] rounded-xl p-4"
      >
        <div class="flex items-start justify-between gap-3 mb-3">
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2 mb-1">
              <Badge variant="default">{{ typeLabels[opp.type] ?? opp.type }}</Badge>
              <span
                v-if="opp.expires_at"
                :class="['text-xs', urgencyClass[formatTimeRemaining(opp.expires_at).urgency]]"
              >
                {{ formatTimeRemaining(opp.expires_at).text }}
              </span>
            </div>
            <h3 class="text-sm font-semibold text-[var(--color-text-primary)]">{{ opp.title }}</h3>
            <p v-if="opp.description" class="mt-1 text-sm text-[var(--color-text-secondary)] line-clamp-2">{{ opp.description }}</p>
          </div>
        </div>

        <div class="space-y-2">
          <div v-if="opp.composite_score !== null && opp.composite_score !== undefined" class="flex items-center gap-2">
            <span class="text-xs text-[var(--color-text-muted)] w-20 shrink-0">Score</span>
            <ScoreBar :score="opp.composite_score" />
          </div>
          <div v-if="opp.urgency_score !== null && opp.urgency_score !== undefined" class="flex items-center gap-2">
            <span class="text-xs text-[var(--color-text-muted)] w-20 shrink-0">Urgency</span>
            <ScoreBar :score="opp.urgency_score" />
          </div>
          <div v-if="opp.relevance_score !== null && opp.relevance_score !== undefined" class="flex items-center gap-2">
            <span class="text-xs text-[var(--color-text-muted)] w-20 shrink-0">Relevance</span>
            <ScoreBar :score="opp.relevance_score" />
          </div>
        </div>

        <p v-if="opp.detected_at" class="mt-3 text-xs text-[var(--color-text-muted)]">
          Detected {{ new Date(opp.detected_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric' }) }}
        </p>
      </div>
    </div>
  </div>
</template>
