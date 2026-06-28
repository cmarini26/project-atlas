<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue'
import Badge from '@/Components/UI/Badge.vue'
import EmptyState from '@/Components/UI/EmptyState.vue'
import type { DigitalTwin, Fact, Knowledge, BrainObservation } from '@/types'

defineProps<{
  twin: DigitalTwin | null
  facts: Fact[]
  knowledge: Knowledge[]
  recent_observations: BrainObservation[]
}>()

const twinStatusLabels: Record<string, string> = {
  initializing: 'Getting started',
  crawling: 'Learning from your website',
  analyzing: 'Building your profile',
  ready: 'Up to date',
  error: 'Needs attention',
}

const twinStatusVariants: Record<string, 'accent' | 'success' | 'muted' | 'warning'> = {
  ready: 'success',
  analyzing: 'accent',
  crawling: 'accent',
  initializing: 'muted',
  error: 'warning',
}

function formatDate(date: string | null): string {
  if (!date) return '—'
  return new Date(date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
}
</script>

<template>
  <AppLayout>
    <div class="max-w-4xl">
      <h1 class="text-xl font-semibold text-[var(--color-text-primary)] mb-6">Business Brain</h1>

      <!-- Twin status -->
      <div class="bg-[var(--color-surface-elevated)] border border-[var(--color-border)] rounded-xl p-5 mb-6">
        <div class="flex items-center justify-between mb-1">
          <h2 class="text-sm font-semibold text-[var(--color-text-primary)]">Digital Twin</h2>
          <Badge
            v-if="twin"
            :variant="twinStatusVariants[twin.status] ?? 'muted'"
          >
            {{ twinStatusLabels[twin.status] ?? twin.status }}
          </Badge>
          <Badge v-else variant="muted">Not initialized</Badge>
        </div>
        <p class="text-sm text-[var(--color-text-muted)]">
          Atlas's model of your business — updated as it learns more.
        </p>
        <p v-if="twin?.last_enriched_at" class="mt-2 text-xs text-[var(--color-text-muted)]">
          Last updated {{ formatDate(twin.last_enriched_at) }}
        </p>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        <!-- Facts -->
        <div>
          <h2 class="text-xs font-semibold text-[var(--color-text-muted)] uppercase tracking-wide mb-3">
            Facts <span class="ml-1 text-[var(--color-text-placeholder)]">({{ facts.length }})</span>
          </h2>

          <EmptyState
            v-if="facts.length === 0"
            title="No facts yet"
            description="Facts appear as Atlas learns about your business."
          />

          <div v-else class="bg-[var(--color-surface-elevated)] border border-[var(--color-border)] rounded-xl divide-y divide-[var(--color-border)]">
            <div
              v-for="fact in facts"
              :key="fact.id"
              class="px-4 py-3"
            >
              <div class="flex items-start justify-between gap-2">
                <div class="flex-1 min-w-0">
                  <p class="text-xs font-medium text-[var(--color-text-muted)] mb-0.5">{{ fact.key }}</p>
                  <p class="text-sm text-[var(--color-text-secondary)]">{{ fact.value }}</p>
                </div>
                <span
                  v-if="fact.confidence !== null && fact.confidence !== undefined"
                  class="text-xs text-[var(--color-text-placeholder)] tabular-nums shrink-0"
                >
                  {{ Math.round((fact.confidence as number) * 100) }}%
                </span>
              </div>
            </div>
          </div>
        </div>

        <!-- Knowledge -->
        <div>
          <h2 class="text-xs font-semibold text-[var(--color-text-muted)] uppercase tracking-wide mb-3">
            Knowledge <span class="ml-1 text-[var(--color-text-placeholder)]">({{ knowledge.length }})</span>
          </h2>

          <EmptyState
            v-if="knowledge.length === 0"
            title="No knowledge yet"
            description="Knowledge appears as Atlas synthesizes what it learns."
          />

          <div v-else class="space-y-3">
            <div
              v-for="k in knowledge"
              :key="k.id"
              class="bg-[var(--color-surface-elevated)] border border-[var(--color-border)] rounded-xl p-4"
            >
              <div class="flex items-center gap-2 mb-2">
                <Badge variant="default">{{ k.type }}</Badge>
                <span v-if="k.subject" class="text-xs text-[var(--color-text-muted)] truncate">{{ k.subject }}</span>
              </div>
              <p class="text-sm text-[var(--color-text-secondary)]">{{ k.body }}</p>
            </div>
          </div>
        </div>
      </div>

      <!-- Recent observations -->
      <section>
        <h2 class="text-xs font-semibold text-[var(--color-text-muted)] uppercase tracking-wide mb-3">
          Recent Observations <span class="ml-1 text-[var(--color-text-placeholder)]">({{ recent_observations.length }})</span>
        </h2>

        <EmptyState
          v-if="recent_observations.length === 0"
          title="No observations yet"
          description="Observations appear as Atlas monitors your business activity."
        />

        <div v-else class="bg-[var(--color-surface-elevated)] border border-[var(--color-border)] rounded-xl divide-y divide-[var(--color-border)]">
          <div
            v-for="obs in recent_observations"
            :key="obs.id"
            class="px-4 py-3 flex items-center gap-3"
          >
            <div class="flex-1 min-w-0">
              <p class="text-sm text-[var(--color-text-secondary)]">Status: {{ obs.status }}</p>
            </div>
            <span class="text-xs text-[var(--color-text-placeholder)] shrink-0">{{ formatDate(obs.created_at) }}</span>
          </div>
        </div>
      </section>
    </div>
  </AppLayout>
</template>
