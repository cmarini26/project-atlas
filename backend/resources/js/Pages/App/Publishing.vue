<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue'
import Badge from '@/Components/UI/Badge.vue'
import EmptyState from '@/Components/UI/EmptyState.vue'

interface ExecutionItem {
  id: string
  status: string
  scheduled_at: string | null
  executed_at: string | null
  completed_at: string | null
  last_error: string | null
  channel: { type: string } | null
  content_asset: { type: string; body: string } | null
}

interface PaginatedExecutions {
  data: ExecutionItem[]
  current_page: number
  last_page: number
  total: number
}

defineProps<{
  executions: PaginatedExecutions
}>()

const statusVariants: Record<string, 'success' | 'warning' | 'muted' | 'default'> = {
  published: 'success',
  completed: 'success',
  failed: 'warning',
  pending: 'muted',
  scheduled: 'default',
}

const statusLabels: Record<string, string> = {
  published: 'Published',
  completed: 'Completed',
  failed: 'Failed',
  pending: 'Pending',
  scheduled: 'Scheduled',
  executing: 'Executing',
}

function formatDate(date: string | null): string {
  if (!date) return '—'
  return new Date(date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
}
</script>

<template>
  <AppLayout>
    <div class="max-w-3xl">
      <h1 class="text-xl font-semibold text-[--color-text-primary] mb-6">Publishing Activity</h1>

      <EmptyState
        v-if="executions.data.length === 0"
        title="No publishing activity yet"
        description="Content executions appear here once campaigns are approved and running."
      />

      <div v-else>
        <div class="bg-[--color-surface-elevated] border border-[--color-border] rounded-xl divide-y divide-[--color-border] mb-4">
          <div
            v-for="execution in executions.data"
            :key="execution.id"
            class="px-4 py-4"
          >
            <div class="flex items-start justify-between gap-3 mb-2">
              <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2 mb-1">
                  <span class="text-sm font-medium text-[--color-text-primary]">
                    {{ execution.channel?.type ?? 'Unknown channel' }}
                  </span>
                  <Badge :variant="statusVariants[execution.status] ?? 'muted'">
                    {{ statusLabels[execution.status] ?? execution.status }}
                  </Badge>
                </div>
                <p v-if="execution.content_asset?.body" class="text-sm text-[--color-text-secondary] line-clamp-2">
                  {{ execution.content_asset.body }}
                </p>
                <p v-if="execution.last_error" class="text-xs text-rose-600 mt-1">{{ execution.last_error }}</p>
              </div>
            </div>
            <p class="text-xs text-[--color-text-muted]">{{ formatDate(execution.scheduled_at) }}</p>
          </div>
        </div>

        <p v-if="executions.total > executions.data.length" class="text-sm text-center text-[--color-text-muted]">
          Showing {{ executions.data.length }} of {{ executions.total }} executions
        </p>
      </div>
    </div>
  </AppLayout>
</template>
