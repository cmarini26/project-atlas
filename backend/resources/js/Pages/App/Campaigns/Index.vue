<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue'
import Badge from '@/Components/UI/Badge.vue'
import EmptyState from '@/Components/UI/EmptyState.vue'
import type { Campaign } from '@/types'

interface PaginatedCampaigns {
  data: Campaign[]
  current_page: number
  last_page: number
  per_page: number
  total: number
  next_page_url: string | null
  prev_page_url: string | null
}

defineProps<{
  campaigns: PaginatedCampaigns
}>()

const statusVariants: Record<string, 'accent' | 'success' | 'muted' | 'default'> = {
  active: 'accent',
  completed: 'success',
  draft: 'muted',
  approved: 'default',
  cancelled: 'muted',
}

const statusLabels: Record<string, string> = {
  active: 'Active',
  completed: 'Completed',
  draft: 'Draft',
  approved: 'Approved',
  cancelled: 'Cancelled',
}

function formatDate(date: string | null): string {
  if (!date) return '—'
  return new Date(date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
}
</script>

<template>
  <AppLayout>
    <div class="max-w-3xl">
      <h1 class="text-xl font-semibold text-[var(--color-text-primary)] mb-6">Campaigns</h1>

      <EmptyState
        v-if="campaigns.data.length === 0"
        title="No campaigns yet"
        description="Campaigns are created when you approve a recommendation."
      />

      <div v-else>
        <div class="bg-[var(--color-surface-elevated)] border border-[var(--color-border)] rounded-xl divide-y divide-[var(--color-border)] mb-4">
          <a
            v-for="campaign in campaigns.data"
            :key="campaign.id"
            :href="`/app/campaigns/${campaign.id}`"
            class="flex items-center gap-4 px-4 py-3 hover:bg-[var(--color-surface-subtle)] transition-colors duration-[var(--duration-fast)]"
          >
            <div class="flex-1 min-w-0">
              <p class="text-sm font-medium text-[var(--color-text-primary)] truncate">{{ campaign.title }}</p>
              <p class="text-xs text-[var(--color-text-muted)]">Started {{ formatDate(campaign.created_at) }}</p>
            </div>
            <Badge :variant="statusVariants[campaign.status] ?? 'muted'">
              {{ statusLabels[campaign.status] ?? campaign.status }}
            </Badge>
          </a>
        </div>

        <!-- Pagination -->
        <div v-if="campaigns.last_page > 1" class="flex items-center justify-between">
          <a
            v-if="campaigns.prev_page_url"
            :href="campaigns.prev_page_url"
            class="text-sm text-[var(--color-text-link)] hover:underline"
          >← Previous</a>
          <span v-else class="text-sm text-[var(--color-text-placeholder)]">← Previous</span>

          <span class="text-sm text-[var(--color-text-muted)]">
            Page {{ campaigns.current_page }} of {{ campaigns.last_page }}
          </span>

          <a
            v-if="campaigns.next_page_url"
            :href="campaigns.next_page_url"
            class="text-sm text-[var(--color-text-link)] hover:underline"
          >Next →</a>
          <span v-else class="text-sm text-[var(--color-text-placeholder)]">Next →</span>
        </div>
      </div>
    </div>
  </AppLayout>
</template>
