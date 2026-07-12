<script setup lang="ts">
import { ref } from 'vue'
import { Head, Link, router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Badge from '@/Components/UI/Badge.vue'
import PageHeader from '@/Components/UI/PageHeader.vue'
import { Cog6ToothIcon } from '@heroicons/vue/24/outline'
import { useProductTour } from '@/composables/useProductTour'

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

interface InstagramAccountData {
  username: string
  display_name: string | null
  profile_picture_url: string | null
  bio: string | null
  website: string | null
  follower_count: number | null
  following_count: number | null
  last_synced_at: string | null
}

const props = defineProps<{
  company: CompanyData
  integrations: Integration[]
  membership_role: string
  instagram_account: InstagramAccountData | null
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

const instagramForm = useForm({
  access_token: '',
})

function connectInstagram(): void {
  instagramForm.post('/app/settings/integrations/instagram', {
    onSuccess: () => instagramForm.reset(),
  })
}

const { requestTourStart } = useProductTour()

function retakeTour(): void {
  requestTourStart()
  router.visit('/app')
}
</script>

<template>
  <Head><title>Settings — Atlas</title></Head>
  <div class="max-w-2xl">
    <PageHeader
      title="Settings"
      description="Manage your company profile, integrations, and marketing presence."
      :icon="Cog6ToothIcon"
    />

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

    <!-- Marketing Presence -->
    <div class="bg-[var(--color-surface-elevated)] border border-[var(--color-border)] rounded-xl p-5 mb-6">
      <div class="flex items-center justify-between gap-3">
        <div>
          <h2 class="text-sm font-semibold text-[var(--color-text-primary)] mb-1">Marketing Presence</h2>
          <p class="text-xs text-[var(--color-text-muted)]">Channels Atlas knows about for your business — Instagram, email, print, and more.</p>
        </div>
        <Link
          href="/app/settings/marketing-presence"
          class="shrink-0 py-1.5 px-3 text-xs font-medium rounded-lg border border-[var(--color-border)] text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-subtle)] transition-colors duration-[var(--duration-fast)]"
        >
          Manage →
        </Link>
      </div>
    </div>

    <!-- Instagram -->
    <div class="bg-[var(--color-surface-elevated)] border border-[var(--color-border)] rounded-xl p-5 mb-6">
      <h2 class="text-sm font-semibold text-[var(--color-text-primary)] mb-4">Instagram</h2>

      <div v-if="instagram_account" class="flex items-start gap-3">
        <img
          v-if="instagram_account.profile_picture_url"
          :src="instagram_account.profile_picture_url"
          :alt="`${instagram_account.username}'s Instagram profile picture`"
          class="size-12 rounded-full object-cover shrink-0"
        />
        <div class="flex-1 min-w-0">
          <p class="text-sm font-medium text-[var(--color-text-primary)]">
            {{ instagram_account.display_name ?? instagram_account.username }}
            <span class="text-[var(--color-text-muted)] font-normal">@{{ instagram_account.username }}</span>
          </p>
          <p v-if="instagram_account.bio" class="text-xs text-[var(--color-text-secondary)] mt-1">{{ instagram_account.bio }}</p>
          <p class="text-xs text-[var(--color-text-muted)] mt-1">
            <span v-if="instagram_account.follower_count !== null">{{ instagram_account.follower_count.toLocaleString() }} followers</span>
            <span v-if="instagram_account.follower_count !== null && instagram_account.following_count !== null"> · </span>
            <span v-if="instagram_account.following_count !== null">{{ instagram_account.following_count.toLocaleString() }} following</span>
          </p>
          <p class="text-xs text-[var(--color-text-muted)] mt-1">Last synced: {{ formatDate(instagram_account.last_synced_at) }}</p>
        </div>
      </div>

      <form v-else class="space-y-3" @submit.prevent="connectInstagram">
        <p class="text-xs text-[var(--color-text-muted)]">
          Connect your Instagram account so Atlas can include it in your Business Brain.
        </p>
        <div>
          <label for="instagram-access-token" class="block text-xs font-medium text-[var(--color-text-muted)] uppercase tracking-widest mb-1.5">Access token</label>
          <input
            id="instagram-access-token"
            v-model="instagramForm.access_token"
            type="password"
            required
            :class="[
              'w-full px-3 py-2 text-sm rounded-lg border bg-white text-[var(--color-text-primary)] transition-colors duration-[var(--duration-fast)]',
              instagramForm.errors.access_token
                ? 'border-rose-300 focus:outline-none focus:ring-1 focus:ring-rose-400'
                : 'border-[var(--color-border)] focus:outline-none focus:ring-1 focus:ring-[var(--color-border-focus)] focus:border-[var(--color-border-focus)]',
            ]"
          />
          <p v-if="instagramForm.errors.access_token" class="mt-1 text-xs text-rose-600">{{ instagramForm.errors.access_token }}</p>
        </div>
        <button
          type="submit"
          :disabled="instagramForm.processing"
          class="py-2 px-4 text-sm font-medium rounded-lg bg-[var(--color-accent-600)] text-white hover:bg-[var(--color-accent-700)] disabled:opacity-60 disabled:cursor-not-allowed transition-colors duration-[var(--duration-fast)]"
        >
          {{ instagramForm.processing ? 'Connecting…' : 'Connect Instagram' }}
        </button>
      </form>
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

    <!-- Help -->
    <div class="bg-[var(--color-surface-elevated)] border border-[var(--color-border)] rounded-xl p-5 mt-6">
      <h2 class="text-sm font-semibold text-[var(--color-text-primary)] mb-1">Help</h2>
      <p class="text-xs text-[var(--color-text-muted)] mb-3">Not sure where to start? Retake the guided tour of your dashboard.</p>
      <button
        type="button"
        class="py-1.5 px-3 text-xs font-medium rounded-lg border border-[var(--color-border)] text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-subtle)] transition-colors duration-[var(--duration-fast)]"
        @click="retakeTour"
      >
        Take the product tour
      </button>
    </div>
  </div>
</template>
