<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Badge from '@/Components/UI/Badge.vue'
import EmptyState from '@/Components/UI/EmptyState.vue'
import PageHeader from '@/Components/UI/PageHeader.vue'
import Card from '@/Components/UI/Card.vue'
import ListItem from '@/Components/UI/ListItem.vue'
import { CpuChipIcon, BookOpenIcon, EyeIcon } from '@heroicons/vue/24/outline'
import type { DigitalTwin, Fact, Knowledge, BrainObservation } from '@/types'

// Persistent layout: the sidebar/toast shell survives Inertia visits.
defineOptions({ layout: AppLayout })

defineProps<{
  twin: DigitalTwin | null
  facts: Fact[]
  knowledge: Knowledge[]
  recent_observations: BrainObservation[]
  integration_count: number
}>()

const twinStatusLabels: Record<string, string> = {
  initializing: 'Getting started',
  active: 'Active',
  error: 'Needs attention',
}

const twinStatusVariants: Record<string, 'accent' | 'success' | 'muted' | 'warning'> = {
  active: 'success',
  initializing: 'muted',
  error: 'warning',
}

function formatDate(date: string | null): string {
  if (!date) return '—'
  return new Date(date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
}
</script>

<template>
  <Head><title>Business Brain — Atlas</title></Head>
  <div class="max-w-4xl">
    <PageHeader
      title="Business Brain"
      description="The facts and knowledge Atlas has learned about your business so far."
      :icon="CpuChipIcon"
    />

    <!-- Twin status -->
    <Card class="mb-6">
      <template #header>
        <h2 class="text-sm font-semibold text-[var(--color-text-primary)]">Digital Twin</h2>
        <Badge
          v-if="twin"
          :variant="twinStatusVariants[twin.status] ?? 'muted'"
        >
          {{ twinStatusLabels[twin.status] ?? twin.status }}
        </Badge>
        <Badge v-else variant="muted">Not initialized</Badge>
      </template>
      <p class="text-sm text-[var(--color-text-muted)]">
        Atlas's model of your business — updated as it learns more.
      </p>
      <p v-if="twin?.last_enriched_at" class="mt-2 text-xs text-[var(--color-text-muted)]">
        Last updated {{ formatDate(twin.last_enriched_at) }}
      </p>
    </Card>

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
        >
          <template #icon><CpuChipIcon class="size-6" /></template>
          <template v-if="integration_count === 0" #action>
            <Link href="/app/settings" class="text-sm text-[var(--color-text-link)] hover:underline">Connect your website →</Link>
          </template>
        </EmptyState>

        <Card v-else padding="none" divided>
          <ListItem v-for="fact in facts" :key="fact.id">
            <p class="text-xs font-medium text-[var(--color-text-muted)] mb-0.5">{{ fact.key }}</p>
            <p class="text-sm text-[var(--color-text-secondary)]">{{ fact.value }}</p>
            <template v-if="fact.confidence !== null && fact.confidence !== undefined" #trailing>
              <span class="text-xs text-[var(--color-text-placeholder)] tabular-nums">
                {{ Math.round((fact.confidence as number) * 100) }}%
              </span>
            </template>
          </ListItem>
        </Card>
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
        >
          <template #icon><BookOpenIcon class="size-6" /></template>
        </EmptyState>

        <div v-else class="space-y-3">
          <Card v-for="k in knowledge" :key="k.id" padding="sm">
            <div class="flex items-center gap-2 mb-2">
              <Badge variant="default">{{ k.type }}</Badge>
              <span v-if="k.subject" class="text-xs text-[var(--color-text-muted)] truncate">{{ k.subject }}</span>
            </div>
            <p class="text-sm text-[var(--color-text-secondary)]">{{ k.body }}</p>
          </Card>
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
      >
        <template #icon><EyeIcon class="size-6" /></template>
      </EmptyState>

      <Card v-else padding="none" divided>
        <ListItem v-for="obs in recent_observations" :key="obs.id">
          <p class="text-sm text-[var(--color-text-secondary)]">Status: {{ obs.status }}</p>
          <template #trailing>
            <span class="text-xs text-[var(--color-text-placeholder)]">{{ formatDate(obs.created_at) }}</span>
          </template>
        </ListItem>
      </Card>
    </section>
  </div>
</template>
