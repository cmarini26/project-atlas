<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue'
import Badge from '@/Components/UI/Badge.vue'
import ScoreBar from '@/Components/UI/ScoreBar.vue'
import EmptyState from '@/Components/UI/EmptyState.vue'
import type { Opportunity } from '@/types'

defineProps<{
  opportunities: Opportunity[]
}>()

function formatDate(date: string | null): string {
  if (!date) return ''
  return new Date(date).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })
}

function isExpiringSoon(expiresAt: string | null): boolean {
  if (!expiresAt) return false
  return (new Date(expiresAt).getTime() - Date.now()) < 7 * 24 * 60 * 60 * 1000
}
</script>

<template>
  <AppLayout>
    <div class="max-w-3xl">
      <h1 class="text-xl font-semibold text-[--color-text-primary] mb-6">Opportunities</h1>

      <EmptyState
        v-if="opportunities.length === 0"
        title="No open opportunities"
        description="Atlas is scanning your business for growth opportunities. Check back soon."
      />

      <div v-else class="space-y-3">
        <div
          v-for="opp in opportunities"
          :key="opp.id"
          class="bg-[--color-surface-elevated] border border-[--color-border] rounded-xl p-4"
        >
          <div class="flex items-start justify-between gap-3 mb-3">
            <div class="flex-1 min-w-0">
              <div class="flex items-center gap-2 mb-1">
                <Badge variant="default">{{ opp.type }}</Badge>
                <span
                  v-if="opp.expires_at && isExpiringSoon(opp.expires_at)"
                  class="text-xs text-amber-600 font-medium"
                >
                  Expires {{ formatDate(opp.expires_at) }}
                </span>
              </div>
              <h3 class="text-sm font-semibold text-[--color-text-primary]">{{ opp.title }}</h3>
              <p v-if="opp.description" class="mt-1 text-sm text-[--color-text-secondary] line-clamp-2">{{ opp.description }}</p>
            </div>
          </div>

          <div class="space-y-2">
            <div v-if="opp.composite_score !== null && opp.composite_score !== undefined" class="flex items-center gap-2">
              <span class="text-xs text-[--color-text-muted] w-20 shrink-0">Score</span>
              <ScoreBar :score="opp.composite_score" />
            </div>
            <div v-if="opp.urgency_score !== null && opp.urgency_score !== undefined" class="flex items-center gap-2">
              <span class="text-xs text-[--color-text-muted] w-20 shrink-0">Urgency</span>
              <ScoreBar :score="opp.urgency_score" />
            </div>
            <div v-if="opp.relevance_score !== null && opp.relevance_score !== undefined" class="flex items-center gap-2">
              <span class="text-xs text-[--color-text-muted] w-20 shrink-0">Relevance</span>
              <ScoreBar :score="opp.relevance_score" />
            </div>
          </div>

          <p v-if="opp.detected_at" class="mt-3 text-xs text-[--color-text-muted]">
            Detected {{ formatDate(opp.detected_at) }}
          </p>
        </div>
      </div>
    </div>
  </AppLayout>
</template>
