<script setup lang="ts">
import { ref } from 'vue'
import { Head, useForm } from '@inertiajs/vue3'
import AuthLayout from '@/Layouts/AuthLayout.vue'

const step = ref<1 | 2 | 3>(1)

const companyForm = useForm({
  name: '',
  industry: '',
})

const integrationForm = useForm({
  url: '',
})

const companyId = ref<string | null>(null)

function submitCompany(): void {
  companyForm.post('/onboarding/company', {
    onSuccess: (page) => {
      const data = page.props as Record<string, unknown>
      if (typeof data.company_id === 'string') {
        companyId.value = data.company_id
      }
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

function goToDashboard(): void {
  window.location.href = '/onboarding/status'
}
</script>

<template>
  <Head><title>Set up your business — Atlas</title></Head>
  <AuthLayout>
    <!-- Step indicator -->
    <div class="flex items-center gap-2 mb-6">
      <div
        v-for="n in 3"
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
      <p class="text-sm text-[var(--color-text-muted)] mb-5">Atlas will crawl it to learn about your business.</p>

      <form class="space-y-4" @submit.prevent="submitIntegration">
        <div>
          <label for="url" class="block text-xs font-medium text-[var(--color-text-muted)] uppercase tracking-widest mb-1.5">Website URL</label>
          <input
            id="url"
            v-model="integrationForm.url"
            type="url"
            required
            :class="[
              'w-full px-3 py-2 text-sm rounded-lg border bg-[var(--color-surface-elevated)] text-[var(--color-text-primary)] placeholder-[var(--color-text-placeholder)] transition-colors duration-[var(--duration-fast)]',
              integrationForm.errors.url
                ? 'border-rose-300 focus:outline-none focus:ring-1 focus:ring-rose-400'
                : 'border-[var(--color-border)] focus:outline-none focus:ring-1 focus:ring-[var(--color-border-focus)] focus:border-[var(--color-border-focus)]',
            ]"
            placeholder="https://acmecomics.com"
          />
          <p v-if="integrationForm.errors.url" class="mt-1 text-xs text-rose-600">{{ integrationForm.errors.url }}</p>
        </div>

        <button
          type="submit"
          :disabled="integrationForm.processing"
          class="w-full py-2.5 px-4 text-sm font-medium rounded-lg bg-[var(--color-accent-600)] text-white hover:bg-[var(--color-accent-700)] disabled:opacity-60 disabled:cursor-not-allowed transition-colors duration-[var(--duration-fast)]"
        >
          {{ integrationForm.processing ? 'Connecting…' : 'Connect website' }}
        </button>

        <button
          type="button"
          class="w-full py-2.5 px-4 text-sm font-medium rounded-lg border border-[var(--color-border)] text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-subtle)] transition-colors duration-[var(--duration-fast)]"
          @click="step = 3"
        >
          Skip for now
        </button>
      </form>
    </div>

    <!-- Step 3: Confirm -->
    <div v-else>
      <div class="mb-5 flex items-center justify-center">
        <div class="size-12 rounded-full bg-[var(--color-accent-50)] flex items-center justify-center">
          <svg class="size-6 text-[var(--color-accent-600)]" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
        </div>
      </div>

      <h1 class="text-base font-semibold text-[var(--color-text-primary)] mb-1 text-center">You're all set</h1>
      <p class="text-sm text-[var(--color-text-muted)] mb-6 text-center">Atlas is now learning about your business. Your first recommendation will appear once it has enough context.</p>

      <button
        type="button"
        class="w-full py-2.5 px-4 text-sm font-medium rounded-lg bg-[var(--color-accent-600)] text-white hover:bg-[var(--color-accent-700)] transition-colors duration-[var(--duration-fast)]"
        @click="goToDashboard"
      >
        Go to dashboard
      </button>
    </div>
  </AuthLayout>
</template>
