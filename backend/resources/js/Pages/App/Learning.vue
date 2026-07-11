<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Badge from '@/Components/UI/Badge.vue'
import EmptyState from '@/Components/UI/EmptyState.vue'
import { AcademicCapIcon } from '@heroicons/vue/24/outline'
import type { Learning, AppliedEffect } from '@/types'

// Persistent layout: the sidebar/toast shell survives Inertia visits.
defineOptions({ layout: AppLayout })

interface PaginatedLearnings {
  data: Learning[]
  current_page: number
  last_page: number
  total: number
}

defineProps<{
  learnings: PaginatedLearnings
  applied_effects: AppliedEffect[]
}>()

const signalLabels: Record<string, string> = {
  channel_outperformed: 'Channel exceeded expectations',
  channel_underperformed: 'Channel underperformed',
  campaign_type_succeeded: 'Campaign type succeeded',
  campaign_type_underperformed: 'Campaign type underperformed',
  recommendation_approved: 'Recommendation approved',
  recommendation_rejected: 'Recommendation rejected — wrong fit',
  recommendation_edited_and_approved: 'Recommendation edited before approval',
  email_deliverability_issue: 'Email deliverability issue detected',
  high_unsubscribe_rate: 'High unsubscribe rate detected',
  content_angle_engaged: 'Content angle resonated with audience',
  optimal_timing_signal: 'Optimal send time detected',
}

const sourceTypeLabels: Record<string, string> = {
  campaign_metric: 'Campaign metric',
  approval: 'Approval decision',
  user_override: 'Manual override',
}

function formatDate(date: string | null): string {
  if (!date) return '—'
  return new Date(date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
}
</script>

<template>
  <Head><title>Learning — Atlas</title></Head>
  <div class="max-w-3xl">
    <h1 class="text-xl font-semibold text-[var(--color-text-primary)] mb-2">Learning</h1>
    <p class="text-sm text-[var(--color-text-muted)] mb-6">How Atlas is improving its understanding of your business over time.</p>

    <EmptyState
      v-if="learnings.data.length === 0"
      title="No learnings yet"
      description="Atlas records what it learns after each campaign. Approve your first recommendation to get started."
      variant="success"
    >
      <template #icon><AcademicCapIcon class="size-6" /></template>
    </EmptyState>

    <div v-else>
      <!-- Applied effects section -->
      <div v-if="applied_effects.length > 0" class="mb-6">
        <h2 class="text-xs font-semibold text-[var(--color-text-muted)] uppercase tracking-wide mb-3">How Atlas Applied Recent Learnings</h2>
        <div class="space-y-3">
          <div
            v-for="application in applied_effects"
            :key="application.id"
            class="bg-[var(--color-surface-elevated)] border border-[var(--color-border)] rounded-xl p-4"
          >
            <p class="text-xs text-[var(--color-text-muted)] mb-2">{{ formatDate(application.created_at) }}</p>
            <ul class="space-y-1.5">
              <li
                v-for="(effect, i) in application.effects"
                :key="i"
                class="flex items-start gap-2"
              >
                <svg class="size-3.5 text-[var(--color-accent-500)] mt-0.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                <span class="text-xs text-[var(--color-text-secondary)]">{{ effect.description }}</span>
              </li>
            </ul>
          </div>
        </div>
      </div>

      <!-- All learnings -->
      <h2 class="text-xs font-semibold text-[var(--color-text-muted)] uppercase tracking-wide mb-3">All Learnings</h2>
      <div class="space-y-3 mb-4">
        <div
          v-for="learning in learnings.data"
          :key="learning.id"
          class="bg-[var(--color-surface-elevated)] border border-[var(--color-border)] rounded-xl p-4"
        >
          <div class="flex items-start justify-between gap-3 mb-2">
            <div class="flex items-center gap-2 flex-wrap">
              <Badge variant="default">{{ sourceTypeLabels[learning.source_type] ?? learning.source_type }}</Badge>
              <span v-if="learning.applied_at" class="text-xs text-emerald-600 font-medium">Applied</span>
            </div>
            <p class="text-xs text-[var(--color-text-muted)] shrink-0">{{ formatDate(learning.created_at) }}</p>
          </div>

          <p class="text-sm text-[var(--color-text-primary)] font-medium">{{ signalLabels[learning.signal] ?? learning.signal }}</p>

          <dl v-if="Object.keys(learning.value ?? {}).length > 0" class="mt-2 grid grid-cols-2 gap-2">
            <div v-for="(val, key) in learning.value" :key="key">
              <dt class="text-xs text-[var(--color-text-muted)] capitalize">{{ String(key).replace(/_/g, ' ') }}</dt>
              <dd class="text-xs text-[var(--color-text-secondary)] font-medium">{{ val }}</dd>
            </div>
          </dl>
        </div>
      </div>

      <!-- Pagination info -->
      <p v-if="learnings.total > learnings.data.length" class="text-sm text-center text-[var(--color-text-muted)]">
        Showing {{ learnings.data.length }} of {{ learnings.total }} learnings
      </p>
    </div>
  </div>
</template>
