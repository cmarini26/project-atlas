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
  last_error: string | null
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

interface MetaChannel {
  type: string
  name: string
  status: string
}

interface WordPressChannel {
  name: string
  site_url: string
  status: string
}

interface EmailChannel {
  provider_type: string
  from_email: string
  from_name: string
  status: string
  last_used_at: string | null
}

const props = defineProps<{
  company: CompanyData
  integrations: Integration[]
  membership_role: string
  instagram_account: InstagramAccountData | null
  meta_channels: MetaChannel[]
  wordpress_channel: WordPressChannel | null
  email_channel: EmailChannel | null
}>()

const metaChannelLabels: Record<string, string> = {
  facebook: 'Facebook',
  instagram: 'Instagram',
}

function revokeMeta(): void {
  router.post('/app/settings/meta/revoke', {}, { preserveScroll: true })
}

const wordPressForm = useForm({
  site_url: '',
  username: '',
  app_password: '',
})

function connectWordPress(): void {
  wordPressForm.post('/app/settings/wordpress/connect', {
    onSuccess: () => wordPressForm.reset(),
  })
}

function revokeWordPress(): void {
  router.post('/app/settings/wordpress/revoke', {}, { preserveScroll: true })
}

const emailForm = useForm({
  api_token: '',
  from_email: '',
  from_name: '',
})

function connectEmail(): void {
  emailForm.post('/app/settings/email/connect', {
    onSuccess: () => emailForm.reset(),
  })
}

function revokeEmail(): void {
  router.post('/app/settings/email/revoke', {}, { preserveScroll: true })
}

const emailTestForm = useForm({
  to_email: '',
})

function sendEmailTest(): void {
  emailTestForm.post('/app/settings/email/test', { preserveScroll: true })
}

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
    preserveScroll: true,
    preserveState: true,
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
            class="py-2 px-4 text-sm font-medium rounded-lg bg-[var(--color-accent-500)] text-white hover:bg-[var(--color-accent-600)] disabled:opacity-60 disabled:cursor-not-allowed transition-colors duration-[var(--duration-fast)]"
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
          class="py-2 px-4 text-sm font-medium rounded-lg bg-[var(--color-accent-500)] text-white hover:bg-[var(--color-accent-600)] disabled:opacity-60 disabled:cursor-not-allowed transition-colors duration-[var(--duration-fast)]"
        >
          {{ instagramForm.processing ? 'Connecting…' : 'Connect Instagram' }}
        </button>
      </form>
    </div>

    <!-- Publishing -->
    <div class="bg-[var(--color-surface-elevated)] border border-[var(--color-border)] rounded-xl p-5 mb-6">
      <h2 class="text-sm font-semibold text-[var(--color-text-primary)] mb-4">Publishing</h2>

      <div v-if="meta_channels.length > 0" class="space-y-3">
        <div
          v-for="channel in meta_channels"
          :key="channel.type"
          class="flex items-center gap-3 p-3 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface-subtle)]"
        >
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2 mb-0.5">
              <p class="text-sm font-medium text-[var(--color-text-primary)]">{{ channel.name }}</p>
              <Badge :variant="channel.status === 'active' ? 'success' : 'muted'">{{ metaChannelLabels[channel.type] ?? channel.type }}</Badge>
            </div>
            <p class="text-xs text-[var(--color-text-muted)]">Status: {{ channel.status }}</p>
          </div>
        </div>
        <button
          type="button"
          class="py-1.5 px-3 text-xs font-medium rounded-lg border border-[var(--color-border)] text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-subtle)] transition-colors duration-[var(--duration-fast)]"
          @click="revokeMeta"
        >
          Disconnect
        </button>
      </div>

      <div v-else class="space-y-3">
        <p class="text-xs text-[var(--color-text-muted)]">
          Connect Instagram &amp; Facebook so Atlas can publish campaigns directly to your Pages.
        </p>
        <a
          href="/app/settings/meta/connect"
          class="inline-block py-2 px-4 text-sm font-medium rounded-lg bg-[var(--color-accent-500)] text-white hover:bg-[var(--color-accent-600)] transition-colors duration-[var(--duration-fast)]"
        >
          Connect Instagram &amp; Facebook
        </a>
      </div>
    </div>

    <!-- WordPress -->
    <div class="bg-[var(--color-surface-elevated)] border border-[var(--color-border)] rounded-xl p-5 mb-6">
      <h2 class="text-sm font-semibold text-[var(--color-text-primary)] mb-4">WordPress</h2>

      <div v-if="wordpress_channel" class="flex items-start gap-3">
        <div class="flex-1 min-w-0">
          <div class="flex items-center gap-2 mb-0.5">
            <p class="text-sm font-medium text-[var(--color-text-primary)]">{{ wordpress_channel.name }}</p>
            <Badge :variant="wordpress_channel.status === 'active' ? 'success' : 'muted'">Blog</Badge>
          </div>
          <p class="text-xs text-[var(--color-text-muted)]">{{ wordpress_channel.site_url }}</p>
          <p class="text-xs text-[var(--color-text-muted)] mt-1">Status: {{ wordpress_channel.status }}</p>
          <button
            type="button"
            class="mt-3 py-1.5 px-3 text-xs font-medium rounded-lg border border-[var(--color-border)] text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-subtle)] transition-colors duration-[var(--duration-fast)]"
            @click="revokeWordPress"
          >
            Disconnect
          </button>
        </div>
      </div>

      <form v-else class="space-y-3" @submit.prevent="connectWordPress">
        <p class="text-xs text-[var(--color-text-muted)]">
          Connect your WordPress site so Atlas can publish campaign blog posts directly to it. Create an
          <a href="https://wordpress.org/documentation/article/application-passwords/" target="_blank" rel="noopener noreferrer" class="text-[var(--color-text-link)] hover:underline">Application Password</a>
          under your WordPress user profile — no plugin required.
        </p>
        <div>
          <label for="wordpress-site-url" class="block text-xs font-medium text-[var(--color-text-muted)] uppercase tracking-widest mb-1.5">Site URL</label>
          <input
            id="wordpress-site-url"
            v-model="wordPressForm.site_url"
            type="url"
            placeholder="https://yourblog.com"
            required
            class="w-full px-3 py-2 text-sm rounded-lg border border-[var(--color-border)] bg-white text-[var(--color-text-primary)] focus:outline-none focus:ring-1 focus:ring-[var(--color-border-focus)] focus:border-[var(--color-border-focus)] transition-colors duration-[var(--duration-fast)]"
          />
          <p v-if="wordPressForm.errors.site_url" class="mt-1 text-xs text-rose-600">{{ wordPressForm.errors.site_url }}</p>
        </div>
        <div>
          <label for="wordpress-username" class="block text-xs font-medium text-[var(--color-text-muted)] uppercase tracking-widest mb-1.5">Username</label>
          <input
            id="wordpress-username"
            v-model="wordPressForm.username"
            type="text"
            required
            class="w-full px-3 py-2 text-sm rounded-lg border border-[var(--color-border)] bg-white text-[var(--color-text-primary)] focus:outline-none focus:ring-1 focus:ring-[var(--color-border-focus)] focus:border-[var(--color-border-focus)] transition-colors duration-[var(--duration-fast)]"
          />
          <p v-if="wordPressForm.errors.username" class="mt-1 text-xs text-rose-600">{{ wordPressForm.errors.username }}</p>
        </div>
        <div>
          <label for="wordpress-app-password" class="block text-xs font-medium text-[var(--color-text-muted)] uppercase tracking-widest mb-1.5">Application password</label>
          <input
            id="wordpress-app-password"
            v-model="wordPressForm.app_password"
            type="password"
            required
            class="w-full px-3 py-2 text-sm rounded-lg border border-[var(--color-border)] bg-white text-[var(--color-text-primary)] focus:outline-none focus:ring-1 focus:ring-[var(--color-border-focus)] focus:border-[var(--color-border-focus)] transition-colors duration-[var(--duration-fast)]"
          />
          <p v-if="wordPressForm.errors.app_password" class="mt-1 text-xs text-rose-600">{{ wordPressForm.errors.app_password }}</p>
        </div>
        <button
          type="submit"
          :disabled="wordPressForm.processing"
          class="py-2 px-4 text-sm font-medium rounded-lg bg-[var(--color-accent-500)] text-white hover:bg-[var(--color-accent-600)] disabled:opacity-60 disabled:cursor-not-allowed transition-colors duration-[var(--duration-fast)]"
        >
          {{ wordPressForm.processing ? 'Connecting…' : 'Connect WordPress' }}
        </button>
      </form>
    </div>

    <!-- Email (Postmark) -->
    <div class="bg-[var(--color-surface-elevated)] border border-[var(--color-border)] rounded-xl p-5 mb-6">
      <h2 class="text-sm font-semibold text-[var(--color-text-primary)] mb-4">Email</h2>

      <div v-if="email_channel" class="space-y-4">
        <div class="flex items-start gap-3">
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2 mb-0.5">
              <p class="text-sm font-medium text-[var(--color-text-primary)]">Postmark</p>
              <Badge :variant="email_channel.status === 'active' ? 'success' : 'muted'">Email</Badge>
            </div>
            <p class="text-xs text-[var(--color-text-muted)]">Sending as {{ email_channel.from_name ? `${email_channel.from_name} <${email_channel.from_email}>` : email_channel.from_email }}</p>
            <p class="text-xs text-[var(--color-text-muted)] mt-1">Status: {{ email_channel.status }}</p>
            <p v-if="email_channel.last_used_at" class="text-xs text-[var(--color-text-muted)] mt-1">Last verified: {{ formatDate(email_channel.last_used_at) }}</p>
            <button
              type="button"
              class="mt-3 py-1.5 px-3 text-xs font-medium rounded-lg border border-[var(--color-border)] text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-subtle)] transition-colors duration-[var(--duration-fast)]"
              @click="revokeEmail"
            >
              Disconnect
            </button>
          </div>
        </div>

        <div class="pt-4 border-t border-[var(--color-border)]">
          <p class="text-xs font-medium text-[var(--color-text-muted)] uppercase tracking-widest mb-1.5">Send a test email</p>
          <form class="flex items-start gap-2" @submit.prevent="sendEmailTest">
            <div class="flex-1">
              <input
                id="email-test-to"
                v-model="emailTestForm.to_email"
                type="email"
                placeholder="you@example.com"
                required
                class="w-full px-3 py-2 text-sm rounded-lg border border-[var(--color-border)] bg-white text-[var(--color-text-primary)] focus:outline-none focus:ring-1 focus:ring-[var(--color-border-focus)] focus:border-[var(--color-border-focus)] transition-colors duration-[var(--duration-fast)]"
              />
              <p v-if="emailTestForm.errors.to_email" class="mt-1 text-xs text-rose-600">{{ emailTestForm.errors.to_email }}</p>
            </div>
            <button
              type="submit"
              :disabled="emailTestForm.processing"
              class="shrink-0 py-2 px-3 text-xs font-medium rounded-lg border border-[var(--color-border)] text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-subtle)] disabled:opacity-60 disabled:cursor-not-allowed transition-colors duration-[var(--duration-fast)]"
            >
              {{ emailTestForm.processing ? 'Sending…' : 'Send test' }}
            </button>
          </form>
        </div>
      </div>

      <form v-else class="space-y-3" @submit.prevent="connectEmail">
        <p class="text-xs text-[var(--color-text-muted)]">
          Connect Postmark so Atlas can send real campaign emails. Create a
          <a href="https://postmarkapp.com/support/article/1008-what-are-server-api-tokens" target="_blank" rel="noopener noreferrer" class="text-[var(--color-text-link)] hover:underline">Server API Token</a>
          from your Postmark server's API Tokens tab.
        </p>
        <div>
          <label for="email-api-token" class="block text-xs font-medium text-[var(--color-text-muted)] uppercase tracking-widest mb-1.5">Server API token</label>
          <input
            id="email-api-token"
            v-model="emailForm.api_token"
            type="password"
            required
            :class="[
              'w-full px-3 py-2 text-sm rounded-lg border bg-white text-[var(--color-text-primary)] transition-colors duration-[var(--duration-fast)]',
              emailForm.errors.api_token
                ? 'border-rose-300 focus:outline-none focus:ring-1 focus:ring-rose-400'
                : 'border-[var(--color-border)] focus:outline-none focus:ring-1 focus:ring-[var(--color-border-focus)] focus:border-[var(--color-border-focus)]',
            ]"
          />
          <p v-if="emailForm.errors.api_token" class="mt-1 text-xs text-rose-600">{{ emailForm.errors.api_token }}</p>
        </div>
        <div>
          <label for="email-from-email" class="block text-xs font-medium text-[var(--color-text-muted)] uppercase tracking-widest mb-1.5">From email</label>
          <input
            id="email-from-email"
            v-model="emailForm.from_email"
            type="email"
            placeholder="hello@yourbusiness.com"
            required
            class="w-full px-3 py-2 text-sm rounded-lg border border-[var(--color-border)] bg-white text-[var(--color-text-primary)] focus:outline-none focus:ring-1 focus:ring-[var(--color-border-focus)] focus:border-[var(--color-border-focus)] transition-colors duration-[var(--duration-fast)]"
          />
          <p v-if="emailForm.errors.from_email" class="mt-1 text-xs text-rose-600">{{ emailForm.errors.from_email }}</p>
          <p class="mt-1 text-xs text-[var(--color-text-muted)]">Must be a Sender Signature or domain verified in Postmark.</p>
        </div>
        <div>
          <label for="email-from-name" class="block text-xs font-medium text-[var(--color-text-muted)] uppercase tracking-widest mb-1.5">From name <span class="normal-case text-[var(--color-text-placeholder)]">(optional)</span></label>
          <input
            id="email-from-name"
            v-model="emailForm.from_name"
            type="text"
            class="w-full px-3 py-2 text-sm rounded-lg border border-[var(--color-border)] bg-white text-[var(--color-text-primary)] focus:outline-none focus:ring-1 focus:ring-[var(--color-border-focus)] focus:border-[var(--color-border-focus)] transition-colors duration-[var(--duration-fast)]"
          />
        </div>
        <button
          type="submit"
          :disabled="emailForm.processing"
          class="py-2 px-4 text-sm font-medium rounded-lg bg-[var(--color-accent-500)] text-white hover:bg-[var(--color-accent-600)] disabled:opacity-60 disabled:cursor-not-allowed transition-colors duration-[var(--duration-fast)]"
        >
          {{ emailForm.processing ? 'Connecting…' : 'Connect Postmark' }}
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
            <p v-if="integration.status === 'error' && integration.last_error" class="text-xs text-rose-600 mt-1">
              {{ integration.last_error }}
            </p>
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
