<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import EmptyState from '@/Components/UI/EmptyState.vue'
import { ChartBarIcon } from '@heroicons/vue/24/outline'
import type { CampaignKpiSnapshot } from '@/types'

// Persistent layout: the sidebar/toast shell survives Inertia visits.
defineOptions({ layout: AppLayout })

interface RecommendationKpis {
  total?: number
  approved?: number
  rejected?: number
  approval_rate?: number
  [key: string]: number | undefined
}

defineProps<{
  recommendation_kpis: RecommendationKpis
  campaign_snapshots: CampaignKpiSnapshot[]
}>()

function formatDate(date: string | null): string {
  if (!date) return '—'
  return new Date(date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
}

function pct(value: number | undefined): string {
  if (value === undefined || value === null) return '—'
  return `${Math.round(value * 100)}%`
}
</script>

<template>
  <Head><title>Analytics — Atlas</title></Head>
  <div class="max-w-4xl">
    <h1 class="text-xl font-semibold text-[var(--color-text-primary)] mb-6">Analytics</h1>

    <!-- Recommendation KPIs -->
    <div class="bg-[var(--color-surface-elevated)] border border-[var(--color-border)] rounded-xl p-5 mb-6">
      <h2 class="text-sm font-semibold text-[var(--color-text-primary)] mb-4">Recommendation Decisions</h2>

      <dl class="grid grid-cols-2 sm:grid-cols-4 gap-4">
        <div v-for="(value, key) in recommendation_kpis" :key="key">
          <dt class="text-xs text-[var(--color-text-muted)] mb-0.5 capitalize">{{ String(key).replace(/_/g, ' ') }}</dt>
          <dd class="text-2xl font-semibold text-[var(--color-text-primary)] tabular-nums">
            {{ typeof value === 'number' && String(key).includes('rate') ? pct(value) : value }}
          </dd>
        </div>
      </dl>
    </div>

    <!-- Campaign snapshots -->
    <section>
      <h2 class="text-xs font-semibold text-[var(--color-text-muted)] uppercase tracking-wide mb-3">Campaign Results</h2>

      <EmptyState
        v-if="campaign_snapshots.length === 0"
        title="No campaign results yet"
        description="Results appear here after campaigns complete."
        variant="info"
      >
        <template #icon><ChartBarIcon class="size-6" /></template>
      </EmptyState>

      <div v-else class="space-y-3">
        <div
          v-for="snapshot in campaign_snapshots"
          :key="snapshot.id"
          class="bg-[var(--color-surface-elevated)] border border-[var(--color-border)] rounded-xl p-4"
        >
          <div class="flex items-start justify-between gap-3 mb-3">
            <div>
              <Link
                v-if="snapshot.campaign"
                :href="`/app/analytics/${snapshot.campaign.id}`"
                class="text-sm font-semibold text-[var(--color-text-primary)] hover:text-[var(--color-text-link)] transition-colors duration-[var(--duration-fast)]"
              >
                {{ snapshot.campaign.title }}
              </Link>
              <p class="text-xs text-[var(--color-text-muted)] mt-0.5">{{ formatDate(snapshot.snapshotted_at) }}</p>
            </div>
            <Link
              v-if="snapshot.campaign"
              :href="`/app/analytics/${snapshot.campaign.id}`"
              class="text-xs text-[var(--color-text-link)] hover:underline shrink-0"
            >Details</Link>
          </div>

          <dl class="grid grid-cols-3 gap-3">
            <div v-for="(value, key) in snapshot.actual_kpis" :key="key">
              <dt class="text-xs text-[var(--color-text-muted)] mb-0.5 capitalize">{{ String(key).replace(/_/g, ' ') }}</dt>
              <dd class="text-sm font-semibold text-[var(--color-text-primary)] tabular-nums">{{ value }}</dd>
            </div>
          </dl>
        </div>
      </div>
    </section>
  </div>
</template>
