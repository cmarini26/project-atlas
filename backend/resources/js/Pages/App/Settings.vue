<script setup lang="ts">
import { ref } from 'vue'
import { Head, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Badge from '@/Components/UI/Badge.vue'

// Persistent layout: the sidebar/toast shell survives Inertia visits.
defineOptions({ layout: AppLayout })

interface Integration {
  id: string
  type: string
  name: string | null
  status: string
  next_run_at: string | null
  last_run_at: string | null
}

interface CompanyData {
  id: string
  name: string
  industry: string | null
  website_url: string | null
}

const props = defineProps<{
  company: CompanyData
  integrations: Integration[]
  membership_role: string
}>()

const form = useForm({
  name: props.company.name,
  industry: props.company.industry ?? '',
})

function save(): void {
  form.patch('/app/settings')
}

const syncingId = ref<string | null>(null)

function sync(integration: Integration): void {
  if (syncingId.value) return
  syncingId.value = integration.id

  const syncForm = useForm({})
  syncForm.post(`/app/settings/integrations/${integration.id}/sync`, {
    onFinish: () => { syncingId.value = null },
  })
}

const integrationStatusVariants: Record<string, 'success' | 'warning' | 'muted' | 'default'> = {
  active: 'success',
  error: 'warning',
  pending: 'muted',
  inactive: 'muted',
  paused: 'muted',
}

function formatDate(date: string | null): string {
  if (!date) return 'Never'
  return new Date(date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
}
</script>

<template>
  <Head><title>Settings — Atlas</title></Head>
  <div class="max-w-2xl">
    <h1 class="text-xl font-semibold text-[var(--color-text-primary)] mb-6">Settings</h1>

    <!-- Company profile -->
    <div class="bg-[var(--color-surface-elevated)] border border-[var(--color-border)] rounded-xl p-5 mb-6">
      <h2 class="text-sm font-semibold text-[var(--color-text-primary)] mb-4">Company profile</h2>

      <form class="space-y-4" @submit.prevent="save">
        <div>
          <label for="company-name" class="block text-xs font-medium text-[var(--color-text-muted)] uppercase tracking-widest mb-1.5">Business name</label>
          <input
            id="company-name"
            v-model="form.name"
            type="text"
            required
            :class="[
              'w-full px-3 py-2 text-sm rounded-lg border bg-white text-[var(--color-text-primary)] transition-colors duration-[var(--duration-fast)]',
              form.errors.name
                ? 'border-rose-300 focus:outline-none focus:ring-1 focus:ring-rose-400'
                : 'border-[var(--color-border)] focus:outline-none focus:ring-1 focus:ring-[var(--color-border-focus)] focus:border-[var(--color-border-focus)]',
            ]"
          />
          <p v-if="form.errors.name" class="mt-1 text-xs text-rose-600">{{ form.errors.name }}</p>
        </div>

        <div>
          <label for="industry" class="block text-xs font-medium text-[var(--color-text-muted)] uppercase tracking-widest mb-1.5">Industry</label>
          <input
            id="industry"
            v-model="form.industry"
            type="text"
            class="w-full px-3 py-2 text-sm rounded-lg border border-[var(--color-border)] bg-white text-[var(--color-text-primary)] focus:outline-none focus:ring-1 focus:ring-[var(--color-border-focus)] focus:border-[var(--color-border-focus)] transition-colors duration-[var(--duration-fast)]"
            placeholder="e.g. Collectibles, Automotive"
          />
        </div>

        <div class="flex items-center gap-3">
          <button
            type="submit"
            :disabled="form.processing || !form.isDirty"
            class="py-2 px-4 text-sm font-medium rounded-lg bg-[var(--color-accent-600)] text-white hover:bg-[var(--color-accent-700)] disabled:opacity-60 disabled:cursor-not-allowed transition-colors duration-[var(--duration-fast)]"
          >
            {{ form.processing ? 'Saving…' : 'Save changes' }}
          </button>
          <p v-if="form.recentlySuccessful" class="text-sm text-emerald-600">Saved.</p>
        </div>
      </form>
    </div>

    <!-- Membership role -->
    <div class="bg-[var(--color-surface-elevated)] border border-[var(--color-border)] rounded-xl p-5 mb-6">
      <h2 class="text-sm font-semibold text-[var(--color-text-primary)] mb-2">Your role</h2>
      <div class="flex items-center gap-2">
        <Badge variant="accent">{{ membership_role }}</Badge>
        <span class="text-sm text-[var(--color-text-muted)]">in this workspace</span>
      </div>
    </div>

    <!-- Integrations -->
    <div class="bg-[var(--color-surface-elevated)] border border-[var(--color-border)] rounded-xl p-5">
      <h2 class="text-sm font-semibold text-[var(--color-text-primary)] mb-4">Integrations</h2>

      <div v-if="integrations.length > 0" class="space-y-3">
        <div
          v-for="integration in integrations"
          :key="integration.id"
          class="flex items-center gap-3 p-3 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface-subtle)]"
        >
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2 mb-0.5">
              <p class="text-sm font-medium text-[var(--color-text-primary)]">{{ integration.name ?? integration.type }}</p>
              <Badge :variant="integrationStatusVariants[integration.status] ?? 'muted'">{{ integration.status }}</Badge>
            </div>
            <p class="text-xs text-[var(--color-text-muted)]">Last synced: {{ formatDate(integration.last_run_at) }}</p>
          </div>
          <button
            type="button"
            :disabled="syncingId === integration.id"
            class="shrink-0 py-1.5 px-3 text-xs font-medium rounded-lg border border-[var(--color-border)] text-[var(--color-text-secondary)] hover:bg-white disabled:opacity-60 disabled:cursor-not-allowed transition-colors duration-[var(--duration-fast)]"
            @click="sync(integration)"
          >
            {{ syncingId === integration.id ? 'Syncing…' : 'Sync now' }}
          </button>
        </div>
      </div>

      <div v-else class="text-center py-4">
        <p class="text-sm text-[var(--color-text-muted)]">No integrations yet.</p>
        <p class="text-xs text-[var(--color-text-muted)] mt-1">Connect your first source during onboarding or contact support.</p>
      </div>
    </div>
  </div>
</template>
