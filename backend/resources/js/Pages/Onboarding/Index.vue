<script setup lang="ts">
import { ref } from 'vue'
import { Head, useForm } from '@inertiajs/vue3'
import AuthLayout from '@/Layouts/AuthLayout.vue'

const props = defineProps<{
  initial_step: 1 | 2 | 3 | 4
}>()

const step = ref<1 | 2 | 3 | 4>(props.initial_step)

const companyForm = useForm({
  name: '',
  industry: '',
})

const integrationForm = useForm({
  website_url: '',
})

const MARKETING_CHANNELS: { type: string; label: string }[] = [
  { type: 'website', label: 'Website' },
  { type: 'email', label: 'Email Newsletter' },
  { type: 'instagram', label: 'Instagram' },
  { type: 'facebook', label: 'Facebook' },
  { type: 'linkedin', label: 'LinkedIn' },
  { type: 'x', label: 'X' },
  { type: 'youtube', label: 'YouTube' },
  { type: 'tiktok', label: 'TikTok' },
  { type: 'google_business_profile', label: 'Google Business Profile' },
  { type: 'events', label: 'Events' },
  { type: 'print', label: 'Print' },
  { type: 'other', label: 'Other' },
]

const marketingPresenceForm = useForm({
  channels: ['website'] as string[],
})

function toggleChannel(type: string): void {
  const index = marketingPresenceForm.channels.indexOf(type)
  if (index === -1) {
    marketingPresenceForm.channels.push(type)
  } else {
    marketingPresenceForm.channels.splice(index, 1)
  }
}

function submitCompany(): void {
  companyForm.post('/onboarding/company', {
    onSuccess: () => {
      step.value = 2
    },
  })
}

function submitIntegration(): void {
  integrationForm.post('/onboarding/integration', {
    onSuccess: () => {
      step.value = 3
    },
  })
}

function submitMarketingPresence(): void {
  marketingPresenceForm.post('/onboarding/marketing-presence', {
    onSuccess: () => {
      step.value = 4
    },
  })
}
</script>

<template>
  <Head><title>Set up your business — Atlas</title></Head>
  <AuthLayout>
    <!-- Step indicator -->
    <div class="flex items-center gap-2 mb-6">
      <div
        v-for="n in 4"
        :key="n"
        :class="[
          'h-1.5 flex-1 rounded-full transition-colors duration-[var(--duration-smooth)]',
          n <= step ? 'bg-[var(--color-accent-500)]' : 'bg-[var(--color-border)]',
        ]"
      />
    </div>

    <!-- Step 1: Company profile -->
    <div v-if="step === 1">
      <h1 class="text-base font-semibold text-[var(--color-text-primary)] mb-1">Tell us about your business</h1>
      <p class="text-sm text-[var(--color-text-muted)] mb-5">Atlas will use this to understand your context.</p>

      <form class="space-y-4" @submit.prevent="submitCompany">
        <div>
          <label for="company-name" class="block text-xs font-medium text-[var(--color-text-muted)] uppercase tracking-widest mb-1.5">Business name</label>
          <input
            id="company-name"
            v-model="companyForm.name"
            type="text"
            required
            :class="[
              'w-full px-3 py-2 text-sm rounded-lg border bg-[var(--color-surface-elevated)] text-[var(--color-text-primary)] placeholder-[var(--color-text-placeholder)] transition-colors duration-[var(--duration-fast)]',
              companyForm.errors.name
                ? 'border-rose-300 focus:outline-none focus:ring-1 focus:ring-rose-400'
                : 'border-[var(--color-border)] focus:outline-none focus:ring-1 focus:ring-[var(--color-border-focus)] focus:border-[var(--color-border-focus)]',
            ]"
            placeholder="Acme Comics"
          />
          <p v-if="companyForm.errors.name" class="mt-1 text-xs text-rose-600">{{ companyForm.errors.name }}</p>
        </div>

        <div>
          <label for="industry" class="block text-xs font-medium text-[var(--color-text-muted)] uppercase tracking-widest mb-1.5">Industry <span class="text-[var(--color-text-muted)]">(optional)</span></label>
          <input
            id="industry"
            v-model="companyForm.industry"
            type="text"
            :class="[
              'w-full px-3 py-2 text-sm rounded-lg border bg-[var(--color-surface-elevated)] text-[var(--color-text-primary)] placeholder-[var(--color-text-placeholder)] transition-colors duration-[var(--duration-fast)]',
              'border-[var(--color-border)] focus:outline-none focus:ring-1 focus:ring-[var(--color-border-focus)] focus:border-[var(--color-border-focus)]',
            ]"
            placeholder="Collectibles, Automotive, etc."
          />
        </div>

        <button
          type="submit"
          :disabled="companyForm.processing"
          class="w-full py-2.5 px-4 text-sm font-medium rounded-lg bg-[var(--color-accent-600)] text-white hover:bg-[var(--color-accent-700)] disabled:opacity-60 disabled:cursor-not-allowed transition-colors duration-[var(--duration-fast)]"
        >
          {{ companyForm.processing ? 'Saving…' : 'Continue' }}
        </button>
      </form>
    </div>

    <!-- Step 2: Website URL -->
    <div v-else-if="step === 2">
      <h1 class="text-base font-semibold text-[var(--color-text-primary)] mb-1">Connect your website</h1>
      <p class="text-sm text-[var(--color-text-muted)] mb-5">Atlas will crawl it to build your Business Brain.</p>

      <form class="space-y-4" @submit.prevent="submitIntegration">
        <div>
          <label for="website_url" class="block text-xs font-medium text-[var(--color-text-muted)] uppercase tracking-widest mb-1.5">Website URL</label>
          <input
            id="website_url"
            v-model="integrationForm.website_url"
            type="url"
            required
            :class="[
              'w-full px-3 py-2 text-sm rounded-lg border bg-[var(--color-surface-elevated)] text-[var(--color-text-primary)] placeholder-[var(--color-text-placeholder)] transition-colors duration-[var(--duration-fast)]',
              integrationForm.errors.website_url
                ? 'border-rose-300 focus:outline-none focus:ring-1 focus:ring-rose-400'
                : 'border-[var(--color-border)] focus:outline-none focus:ring-1 focus:ring-[var(--color-border-focus)] focus:border-[var(--color-border-focus)]',
            ]"
            placeholder="https://acmecomics.com"
          />
          <p v-if="integrationForm.errors.website_url" class="mt-1 text-xs text-rose-600">{{ integrationForm.errors.website_url }}</p>
        </div>

        <button
          type="submit"
          :disabled="integrationForm.processing"
          class="w-full py-2.5 px-4 text-sm font-medium rounded-lg bg-[var(--color-accent-600)] text-white hover:bg-[var(--color-accent-700)] disabled:opacity-60 disabled:cursor-not-allowed transition-colors duration-[var(--duration-fast)]"
        >
          {{ integrationForm.processing ? 'Connecting…' : 'Connect website' }}
        </button>
      </form>
    </div>

    <!-- Step 3: Marketing presence -->
    <div v-else-if="step === 3">
      <h1 class="text-base font-semibold text-[var(--color-text-primary)] mb-1">Where do your customers find you?</h1>
      <p class="text-sm text-[var(--color-text-muted)] mb-5">Help Atlas understand your marketing presence. You can change these later.</p>

      <form class="space-y-4" @submit.prevent="submitMarketingPresence">
        <div class="grid grid-cols-2 gap-2">
          <label
            v-for="channel in MARKETING_CHANNELS"
            :key="channel.type"
            :class="[
              'flex items-center gap-2 px-3 py-2 text-sm rounded-lg border cursor-pointer transition-colors duration-[var(--duration-fast)]',
              marketingPresenceForm.channels.includes(channel.type)
                ? 'border-[var(--color-accent-500)] bg-[var(--color-accent-50)] text-[var(--color-text-primary)]'
                : 'border-[var(--color-border)] bg-[var(--color-surface-elevated)] text-[var(--color-text-secondary)]',
            ]"
          >
            <input
              type="checkbox"
              class="size-4 rounded border-[var(--color-border-strong)] text-[var(--color-accent-600)] focus:ring-1 focus:ring-[var(--color-border-focus)]"
              :checked="marketingPresenceForm.channels.includes(channel.type)"
              @change="toggleChannel(channel.type)"
            />
            {{ channel.label }}
          </label>
        </div>

        <button
          type="submit"
          :disabled="marketingPresenceForm.processing"
          class="w-full py-2.5 px-4 text-sm font-medium rounded-lg bg-[var(--color-accent-600)] text-white hover:bg-[var(--color-accent-700)] disabled:opacity-60 disabled:cursor-not-allowed transition-colors duration-[var(--duration-fast)]"
        >
          {{ marketingPresenceForm.processing ? 'Saving…' : 'Finish setup' }}
        </button>
      </form>
    </div>

    <!-- Step 4: Confirm (shown briefly before redirect to /onboarding/status) -->
    <div v-else>
      <div class="mb-5 flex items-center justify-center">
        <div class="size-12 rounded-full bg-[var(--color-accent-50)] flex items-center justify-center">
          <svg class="size-6 text-[var(--color-accent-600)]" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
        </div>
      </div>

      <h1 class="text-base font-semibold text-[var(--color-text-primary)] mb-1 text-center">Connected</h1>
      <p class="text-sm text-[var(--color-text-muted)] mb-6 text-center">Atlas is now learning about your business. Redirecting…</p>
    </div>
  </AuthLayout>
</template>
