<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Badge from '@/Components/UI/Badge.vue'
import EmptyState from '@/Components/UI/EmptyState.vue'
import PageHeader from '@/Components/UI/PageHeader.vue'
import { LightBulbIcon } from '@heroicons/vue/24/outline'
import type { Recommendation } from '@/types'

// Persistent layout: the sidebar/toast shell survives Inertia visits.
defineOptions({ layout: AppLayout })

defineProps<{
  pending: Recommendation[]
  recent: Recommendation[]
}>()

const statusVariants: Record<string, 'accent' | 'success' | 'neutral' | 'muted'> = {
  pending: 'accent',
  approved: 'success',
  rejected: 'neutral',
  expired: 'muted',
}

const statusLabels: Record<string, string> = {
  pending: 'Pending review',
  approved: 'Approved',
  rejected: 'Passed',
  expired: 'Expired',
}

function formatDate(date: string | null): string {
  if (!date) return '—'
  return new Date(date).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })
}

function getRationalePreview(rec: Recommendation): string | null {
  if (!rec.rationale_display) return null
  const values = Object.values(rec.rationale_display)
  return values[0] ?? null
}
</script>

<template>
  <Head><title>Recommendations — Atlas</title></Head>
  <div class="max-w-3xl">
    <PageHeader
      title="Recommendations"
      description="Review AI-generated marketing recommendations and approve the ones worth acting on."
      :icon="LightBulbIcon"
    />

    <!-- Pending (prominent) -->
    <section v-if="pending.length > 0" class="mb-8">
      <h2 class="text-xs font-semibold text-[var(--color-text-muted)] uppercase tracking-wide mb-3">Waiting for your review</h2>
      <div class="space-y-3">
        <Link
          v-for="rec in pending"
          :key="rec.id"
          :href="`/app/recommendations/${rec.id}`"
          class="block bg-[var(--color-surface-elevated)] border border-[var(--color-accent-200)] rounded-xl p-4 hover:border-[var(--color-accent-400)] transition-colors duration-[var(--duration-fast)]"
        >
          <div class="flex items-start justify-between gap-3">
            <div class="flex-1 min-w-0">
              <h3 class="text-sm font-semibold text-[var(--color-text-primary)] mb-1 capitalize">
                {{ (rec.campaign_type ?? '').replace(/_/g, ' ') }} campaign
              </h3>
              <p v-if="getRationalePreview(rec)" class="text-sm text-[var(--color-text-secondary)] line-clamp-2">
                {{ getRationalePreview(rec) }}
              </p>
            </div>
            <Badge variant="accent">Review</Badge>
          </div>
        </Link>
      </div>
    </section>

    <!-- Empty state -->
    <div v-if="pending.length === 0 && recent.length === 0">
      <EmptyState
        title="No recommendations yet"
        description="Atlas is still learning about your business. Check back soon."
        variant="accent"
      >
        <template #icon><LightBulbIcon class="size-6" /></template>
      </EmptyState>
    </div>

    <!-- Recent -->
    <section v-if="recent.length > 0">
      <h2 class="text-xs font-semibold text-[var(--color-text-muted)] uppercase tracking-wide mb-3">Previous recommendations</h2>
      <div class="bg-[var(--color-surface-elevated)] border border-[var(--color-border)] rounded-xl divide-y divide-[var(--color-border)]">
        <Link
          v-for="rec in recent"
          :key="rec.id"
          :href="`/app/recommendations/${rec.id}`"
          class="flex items-center gap-3 px-4 py-3 hover:bg-[var(--color-surface-subtle)] transition-colors duration-[var(--duration-fast)]"
        >
          <div class="flex-1 min-w-0">
            <p class="text-sm text-[var(--color-text-primary)] truncate capitalize">
              {{ (rec.campaign_type ?? '').replace(/_/g, ' ') }} campaign
            </p>
            <p class="text-xs text-[var(--color-text-muted)]">{{ formatDate(rec.created_at) }}</p>
          </div>
          <Badge :variant="statusVariants[rec.status] ?? 'muted'">
            {{ statusLabels[rec.status] ?? rec.status }}
          </Badge>
        </Link>
      </div>
    </section>
  </div>
</template>
