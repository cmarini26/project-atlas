<script setup lang="ts">
import { reactive } from 'vue'
import { Head, Link, router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Badge from '@/Components/UI/Badge.vue'
import MarketingChannelCapabilityBadge from '@/Components/UI/MarketingChannelCapabilityBadge.vue'
import { MARKETING_CHANNEL_TYPES, marketingChannelTypeLabel } from '@/lib/marketingChannelTypes'
import type { MarketingChannelCapability } from '@/lib/marketingChannelCapability'

defineOptions({ layout: AppLayout })

interface MarketingChannelData {
  id: string
  type: string
  display_name: string
  handle_or_url: string | null
  status: string
  importance: string
  objective: string[]
  capability: MarketingChannelCapability
}

const props = defineProps<{
  channels: MarketingChannelData[]
  statuses: string[]
  importances: string[]
  objectives: string[]
}>()

const STATUS_LABELS: Record<string, string> = {
  active: 'Active',
  occasional: 'Occasional',
  planned: 'Planned',
  inactive: 'Inactive',
}

const IMPORTANCE_LABELS: Record<string, string> = {
  primary: 'Primary',
  secondary: 'Secondary',
  experimental: 'Experimental',
}

const OBJECTIVE_LABELS: Record<string, string> = {
  awareness: 'Awareness',
  leads: 'Leads',
  sales: 'Sales',
  retention: 'Retention',
  trust: 'Trust',
  seo: 'SEO',
  community: 'Community',
}

const statusVariants: Record<string, 'success' | 'warning' | 'muted' | 'default'> = {
  active: 'success',
  occasional: 'default',
  planned: 'muted',
  inactive: 'muted',
}

// ── Add channel ──────────────────────────────────────────────────────────────

const addForm = useForm({
  type: 'instagram',
  display_name: '',
  handle_or_url: '',
})

function addChannel(): void {
  addForm.post('/app/settings/marketing-presence', {
    onSuccess: () => addForm.reset(),
  })
}

// ── Edit status / importance / objective (per row) ──────────────────────────

const rowState = reactive<Record<string, { status: string; importance: string; objective: string[] }>>(
  Object.fromEntries(
    props.channels.map((channel) => [
      channel.id,
      { status: channel.status, importance: channel.importance, objective: [...channel.objective] },
    ]),
  ),
)

function saveStatus(channel: MarketingChannelData): void {
  router.patch(`/app/settings/marketing-presence/${channel.id}`, { status: rowState[channel.id].status })
}

function saveImportance(channel: MarketingChannelData): void {
  router.patch(`/app/settings/marketing-presence/${channel.id}`, { importance: rowState[channel.id].importance })
}

function toggleObjective(channel: MarketingChannelData, objective: string): void {
  const current = rowState[channel.id].objective
  const index = current.indexOf(objective)
  if (index === -1) {
    current.push(objective)
  } else {
    current.splice(index, 1)
  }
}

function saveObjective(channel: MarketingChannelData): void {
  router.patch(`/app/settings/marketing-presence/${channel.id}`, { objective: rowState[channel.id].objective })
}

function disable(channel: MarketingChannelData): void {
  router.delete(`/app/settings/marketing-presence/${channel.id}`)
}

function reactivate(channel: MarketingChannelData): void {
  router.patch(`/app/settings/marketing-presence/${channel.id}`, { status: 'active' })
}
</script>

<template>
  <Head><title>Marketing Presence — Atlas</title></Head>
  <div class="max-w-3xl">
    <Link href="/app/settings" class="text-xs text-[var(--color-text-muted)] hover:text-[var(--color-text-secondary)] mb-4 inline-block">← Settings</Link>

    <h1 class="text-xl font-semibold text-[var(--color-text-primary)] mb-1">Marketing Presence</h1>
    <p class="text-sm text-[var(--color-text-muted)] mb-6">
      Declaring a channel here means Atlas knows about it — not that Atlas can publish or read analytics there yet.
      Those capabilities light up automatically once a real connection exists.
    </p>

    <!-- Add channel -->
    <div class="bg-[var(--color-surface-elevated)] border border-[var(--color-border)] rounded-xl p-5 mb-6">
      <h2 class="text-sm font-semibold text-[var(--color-text-primary)] mb-4">Add a channel</h2>

      <form class="flex flex-col sm:flex-row gap-3" @submit.prevent="addChannel">
        <select
          v-model="addForm.type"
          class="px-3 py-2 text-sm rounded-lg border border-[var(--color-border)] bg-white text-[var(--color-text-primary)] focus:outline-none focus:ring-1 focus:ring-[var(--color-border-focus)] focus:border-[var(--color-border-focus)]"
        >
          <option v-for="c in MARKETING_CHANNEL_TYPES" :key="c.type" :value="c.type">{{ c.label }}</option>
        </select>

        <input
          v-model="addForm.display_name"
          type="text"
          required
          placeholder="Display name (e.g. Acme Instagram)"
          class="flex-1 px-3 py-2 text-sm rounded-lg border border-[var(--color-border)] bg-white text-[var(--color-text-primary)] placeholder-[var(--color-text-placeholder)] focus:outline-none focus:ring-1 focus:ring-[var(--color-border-focus)] focus:border-[var(--color-border-focus)]"
        />

        <input
          v-model="addForm.handle_or_url"
          type="text"
          placeholder="Handle or URL (optional)"
          class="flex-1 px-3 py-2 text-sm rounded-lg border border-[var(--color-border)] bg-white text-[var(--color-text-primary)] placeholder-[var(--color-text-placeholder)] focus:outline-none focus:ring-1 focus:ring-[var(--color-border-focus)] focus:border-[var(--color-border-focus)]"
        />

        <button
          type="submit"
          :disabled="addForm.processing"
          class="shrink-0 py-2 px-4 text-sm font-medium rounded-lg bg-[var(--color-accent-600)] text-white hover:bg-[var(--color-accent-700)] disabled:opacity-60 disabled:cursor-not-allowed transition-colors duration-[var(--duration-fast)]"
        >
          {{ addForm.processing ? 'Adding…' : 'Add channel' }}
        </button>
      </form>
      <p v-if="addForm.errors.display_name" class="mt-2 text-xs text-rose-600">{{ addForm.errors.display_name }}</p>
      <p v-if="addForm.errors.type" class="mt-2 text-xs text-rose-600">{{ addForm.errors.type }}</p>
    </div>

    <!-- Declared channels -->
    <div v-if="channels.length > 0" class="space-y-3">
      <div
        v-for="channel in channels"
        :key="channel.id"
        class="bg-[var(--color-surface-elevated)] border border-[var(--color-border)] rounded-xl p-5"
      >
        <div class="flex items-start justify-between gap-3 mb-4">
          <div>
            <div class="flex items-center gap-2 mb-0.5">
              <p class="text-sm font-medium text-[var(--color-text-primary)]">{{ channel.display_name }}</p>
              <Badge variant="neutral">{{ marketingChannelTypeLabel(channel.type) }}</Badge>
            </div>
            <p v-if="channel.handle_or_url" class="text-xs text-[var(--color-text-muted)]">{{ channel.handle_or_url }}</p>
          </div>

          <div class="flex items-center gap-2 shrink-0">
            <Badge :variant="statusVariants[channel.status] ?? 'muted'">{{ STATUS_LABELS[channel.status] ?? channel.status }}</Badge>
            <MarketingChannelCapabilityBadge :capability="channel.capability" />
          </div>
        </div>

        <div class="grid sm:grid-cols-2 gap-4 mb-4">
          <div>
            <label class="block text-xs font-medium text-[var(--color-text-muted)] uppercase tracking-widest mb-1.5">Status</label>
            <select
              v-model="rowState[channel.id].status"
              class="w-full px-3 py-2 text-sm rounded-lg border border-[var(--color-border)] bg-white text-[var(--color-text-primary)] focus:outline-none focus:ring-1 focus:ring-[var(--color-border-focus)] focus:border-[var(--color-border-focus)]"
              @change="saveStatus(channel)"
            >
              <option v-for="s in statuses" :key="s" :value="s">{{ STATUS_LABELS[s] ?? s }}</option>
            </select>
          </div>

          <div>
            <label class="block text-xs font-medium text-[var(--color-text-muted)] uppercase tracking-widest mb-1.5">Importance</label>
            <select
              v-model="rowState[channel.id].importance"
              class="w-full px-3 py-2 text-sm rounded-lg border border-[var(--color-border)] bg-white text-[var(--color-text-primary)] focus:outline-none focus:ring-1 focus:ring-[var(--color-border-focus)] focus:border-[var(--color-border-focus)]"
              @change="saveImportance(channel)"
            >
              <option v-for="i in importances" :key="i" :value="i">{{ IMPORTANCE_LABELS[i] ?? i }}</option>
            </select>
          </div>
        </div>

        <div class="mb-4">
          <label class="block text-xs font-medium text-[var(--color-text-muted)] uppercase tracking-widest mb-1.5">Objectives</label>
          <div class="flex flex-wrap gap-2">
            <label
              v-for="objective in objectives"
              :key="objective"
              :class="[
                'flex items-center gap-1.5 px-2.5 py-1 text-xs rounded-md border cursor-pointer transition-colors duration-[var(--duration-fast)]',
                rowState[channel.id].objective.includes(objective)
                  ? 'border-[var(--color-accent-500)] bg-[var(--color-accent-50)] text-[var(--color-text-primary)]'
                  : 'border-[var(--color-border)] bg-white text-[var(--color-text-secondary)]',
              ]"
            >
              <input
                type="checkbox"
                class="size-3.5 rounded border-[var(--color-border-strong)] text-[var(--color-accent-600)]"
                :checked="rowState[channel.id].objective.includes(objective)"
                @change="toggleObjective(channel, objective); saveObjective(channel)"
              />
              {{ OBJECTIVE_LABELS[objective] ?? objective }}
            </label>
          </div>
        </div>

        <div class="flex justify-end">
          <button
            v-if="channel.status !== 'inactive'"
            type="button"
            class="py-1.5 px-3 text-xs font-medium rounded-lg border border-[var(--color-border)] text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-subtle)] transition-colors duration-[var(--duration-fast)]"
            @click="disable(channel)"
          >
            Disable
          </button>
          <button
            v-else
            type="button"
            class="py-1.5 px-3 text-xs font-medium rounded-lg border border-[var(--color-border)] text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-subtle)] transition-colors duration-[var(--duration-fast)]"
            @click="reactivate(channel)"
          >
            Reactivate
          </button>
        </div>
      </div>
    </div>

    <div v-else class="bg-[var(--color-surface-elevated)] border border-[var(--color-border)] rounded-xl p-8 text-center">
      <p class="text-sm text-[var(--color-text-muted)]">No marketing channels declared yet.</p>
      <p class="text-xs text-[var(--color-text-muted)] mt-1">Add one above — Atlas still works with zero declared channels, it simply has less business context.</p>
    </div>
  </div>
</template>
