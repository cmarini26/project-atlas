<script setup lang="ts">
import { computed } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Badge from '@/Components/UI/Badge.vue'
import Card from '@/Components/UI/Card.vue'
import ScoreBar from '@/Components/UI/ScoreBar.vue'
import EmptyState from '@/Components/UI/EmptyState.vue'
import PageHeader from '@/Components/UI/PageHeader.vue'
import {
  BoltIcon,
  ClockIcon,
  MagnifyingGlassIcon,
  SparklesIcon,
} from '@heroicons/vue/24/outline'
import type { Opportunity } from '@/types'

// Persistent layout: the sidebar/toast shell survives Inertia visits.
defineOptions({ layout: AppLayout })

const props = defineProps<{
  opportunities: Opportunity[]
  integration_count: number
}>()

const typeLabels: Record<string, string> = {
  featured_item: 'Featured item',
  urgency_promotion: 'Urgency promotion',
  new_arrival: 'New arrival',
  re_engagement: 'Re-engagement',
}

const strongestOpportunity = computed(() => {
  return [...props.opportunities].sort((a, b) => (b.composite_score ?? 0) - (a.composite_score ?? 0))[0] ?? null
})

const expiringSoonCount = computed(() => {
  return props.opportunities.filter((opp) => {
    if (!opp.expires_at) return false
    const hours = (new Date(opp.expires_at).getTime() - Date.now()) / (1000 * 60 * 60)
    return hours > 0 && hours <= 48
  }).length
})

const averageScore = computed(() => {
  const scored = props.opportunities.filter((opp) => opp.composite_score !== null && opp.composite_score !== undefined)
  if (scored.length === 0) return null

  return Math.round(scored.reduce((sum, opp) => sum + (opp.composite_score ?? 0), 0) / scored.length)
})

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

function formatShortDate(date: string | null): string {
  if (!date) return '—'

  return new Date(date).toLocaleDateString('en-US', {
    month: 'short',
    day: 'numeric',
  })
}

function scoreTone(score: number | null | undefined): string {
  if (score === null || score === undefined) return 'bg-[var(--color-surface-panel)] text-[var(--color-text-muted)] border-[var(--color-border)]'
  if (score >= 85) return 'bg-emerald-50 text-emerald-700 border-emerald-200'
  if (score >= 70) return 'bg-[var(--color-accent-50)] text-[var(--color-accent-700)] border-[var(--color-accent-200)]'
  if (score >= 55) return 'bg-amber-50 text-amber-700 border-amber-200'
  return 'bg-rose-50 text-rose-700 border-rose-200'
}

const urgencyClass: Record<string, string> = {
  none: 'text-[var(--color-text-muted)]',
  amber: 'text-amber-700 font-medium',
  rose: 'text-rose-700 font-medium',
}
</script>

<template>
  <Head><title>Opportunities — Atlas</title></Head>

  <div class="max-w-6xl space-y-6">
    <PageHeader
      title="Opportunities"
      description="Signals Atlas has noticed in your business that could turn into a recommendation. Review the strongest ideas first, then decide what deserves action."
      :icon="MagnifyingGlassIcon"
    >
      <template #actions>
        <Badge variant="accent">{{ opportunities.length }} open</Badge>
      </template>
    </PageHeader>

    <EmptyState
      v-if="opportunities.length === 0"
      title="No open opportunities"
      description="Atlas is scanning your business for growth opportunities. Check back soon."
      variant="info"
    >
      <template #icon><MagnifyingGlassIcon class="size-6" /></template>
      <template v-if="integration_count === 0" #action>
        <Link href="/app/settings" class="text-sm text-[var(--color-text-link)] hover:underline">Connect your website →</Link>
      </template>
    </EmptyState>

    <template v-else>
      <section class="grid gap-4 md:grid-cols-3">
        <Card class="overflow-hidden" padding="none">
          <div class="border-b border-[var(--color-border)] bg-[var(--color-surface-panel)] px-5 py-3">
            <p class="text-xs font-semibold uppercase tracking-[0.14em] text-[var(--color-text-muted)]">Strongest signal</p>
          </div>
          <div class="p-5">
            <div class="flex items-start justify-between gap-3">
              <div>
                <p class="text-sm font-semibold text-[var(--color-text-primary)]">
                  {{ strongestOpportunity?.title ?? '—' }}
                </p>
                <p class="mt-2 text-sm leading-6 text-[var(--color-text-secondary)] line-clamp-3">
                  {{ strongestOpportunity?.description ?? 'No active opportunity is currently ranked.' }}
                </p>
              </div>
              <div
                class="shrink-0 rounded-full border px-3 py-1 text-sm font-semibold tabular-nums"
                :class="scoreTone(strongestOpportunity?.composite_score)"
              >
                {{ strongestOpportunity?.composite_score ?? '—' }}
              </div>
            </div>
          </div>
        </Card>

        <Card class="overflow-hidden" padding="none">
          <div class="border-b border-[var(--color-border)] bg-[var(--color-surface-panel)] px-5 py-3">
            <p class="text-xs font-semibold uppercase tracking-[0.14em] text-[var(--color-text-muted)]">Average quality</p>
          </div>
          <div class="flex h-full items-center gap-4 p-5">
            <div class="rounded-[var(--radius-md)] bg-[var(--color-accent-50)] p-3 text-[var(--color-accent-700)]">
              <SparklesIcon class="size-5" />
            </div>
            <div>
              <p class="text-3xl font-semibold tracking-tight text-[var(--color-text-primary)] tabular-nums">
                {{ averageScore ?? '—' }}
              </p>
              <p class="mt-1 text-sm text-[var(--color-text-muted)]">Average composite score across current opportunities</p>
            </div>
          </div>
        </Card>

        <Card class="overflow-hidden" padding="none">
          <div class="border-b border-[var(--color-border)] bg-[var(--color-surface-panel)] px-5 py-3">
            <p class="text-xs font-semibold uppercase tracking-[0.14em] text-[var(--color-text-muted)]">Needs attention soon</p>
          </div>
          <div class="flex h-full items-center gap-4 p-5">
            <div class="rounded-[var(--radius-md)] bg-amber-50 p-3 text-amber-700">
              <ClockIcon class="size-5" />
            </div>
            <div>
              <p class="text-3xl font-semibold tracking-tight text-[var(--color-text-primary)] tabular-nums">
                {{ expiringSoonCount }}
              </p>
              <p class="mt-1 text-sm text-[var(--color-text-muted)]">Open opportunities expiring in the next 48 hours</p>
            </div>
          </div>
        </Card>
      </section>

      <section class="space-y-4">
        <div class="flex items-center justify-between gap-3">
          <div>
            <h2 class="text-sm font-semibold text-[var(--color-text-primary)]">Open opportunities</h2>
            <p class="mt-1 text-sm text-[var(--color-text-muted)]">A cleaner, decision-first view of what Atlas thinks is worth acting on.</p>
          </div>
        </div>

        <Card
          v-for="opp in opportunities"
          :key="opp.id"
          padding="none"
          class="overflow-hidden"
        >
          <div class="grid gap-0 lg:grid-cols-[minmax(0,1fr)_248px]">
            <div class="p-6">
              <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                  <div class="mb-3 flex flex-wrap items-center gap-2">
                    <Badge variant="default">{{ typeLabels[opp.type] ?? opp.type }}</Badge>
                    <span
                      v-if="opp.expires_at"
                      :class="['inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-medium', urgencyClass[formatTimeRemaining(opp.expires_at).urgency]]"
                    >
                      <ClockIcon class="size-3.5" />
                      {{ formatTimeRemaining(opp.expires_at).text }}
                    </span>
                  </div>

                  <div class="flex items-start gap-3">
                    <div class="hidden rounded-[var(--radius-md)] bg-[var(--color-surface-panel)] p-2 text-[var(--color-text-muted)] sm:block">
                      <BoltIcon class="size-5" />
                    </div>
                    <div class="min-w-0">
                      <h3 class="text-xl font-semibold tracking-tight text-[var(--color-text-primary)]">
                        {{ opp.title }}
                      </h3>
                      <p v-if="opp.description" class="mt-3 max-w-3xl text-[15px] leading-7 text-[var(--color-text-secondary)] line-clamp-3">
                        {{ opp.description }}
                      </p>
                    </div>
                  </div>
                </div>

                <div
                  class="shrink-0 rounded-full border px-3 py-1 text-sm font-semibold tabular-nums"
                  :class="scoreTone(opp.composite_score)"
                >
                  {{ opp.composite_score ?? '—' }}
                </div>
              </div>
            </div>

            <div class="border-t border-[var(--color-border)] bg-[var(--color-surface-panel)] p-6 lg:border-l lg:border-t-0">
              <div class="space-y-4">
                <div>
                  <p class="mb-2 text-xs font-semibold uppercase tracking-[0.12em] text-[var(--color-text-muted)]">Signal quality</p>
                  <div class="space-y-3">
                    <div v-if="opp.composite_score !== null && opp.composite_score !== undefined" class="flex items-center gap-3">
                      <span class="w-20 shrink-0 text-xs font-medium text-[var(--color-text-muted)]">Score</span>
                      <ScoreBar :score="opp.composite_score" />
                    </div>
                    <div v-if="opp.urgency_score !== null && opp.urgency_score !== undefined" class="flex items-center gap-3">
                      <span class="w-20 shrink-0 text-xs font-medium text-[var(--color-text-muted)]">Urgency</span>
                      <ScoreBar :score="opp.urgency_score" />
                    </div>
                    <div v-if="opp.relevance_score !== null && opp.relevance_score !== undefined" class="flex items-center gap-3">
                      <span class="w-20 shrink-0 text-xs font-medium text-[var(--color-text-muted)]">Relevance</span>
                      <ScoreBar :score="opp.relevance_score" />
                    </div>
                  </div>
                </div>

                <dl class="grid grid-cols-2 gap-3 border-t border-[var(--color-border)] pt-4 text-sm">
                  <div>
                    <dt class="text-xs font-medium uppercase tracking-[0.08em] text-[var(--color-text-muted)]">Detected</dt>
                    <dd class="mt-1 font-medium text-[var(--color-text-primary)]">{{ formatShortDate(opp.detected_at) }}</dd>
                  </div>
                  <div>
                    <dt class="text-xs font-medium uppercase tracking-[0.08em] text-[var(--color-text-muted)]">Status</dt>
                    <dd class="mt-1 font-medium capitalize text-[var(--color-text-primary)]">{{ opp.status }}</dd>
                  </div>
                </dl>
              </div>
            </div>
          </div>
        </Card>
      </section>
    </template>
  </div>
</template>
