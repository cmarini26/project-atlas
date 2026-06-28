<script setup lang="ts">
import { ref, onMounted, onUnmounted } from 'vue'
import { router } from '@inertiajs/vue3'
import AuthLayout from '@/Layouts/AuthLayout.vue'

interface StatusData {
  twin_status: string
  fact_count: number
  opportunity_count: number
  recommendation_count: number
}

const status = ref<StatusData>({
  twin_status: 'initializing',
  fact_count: 0,
  opportunity_count: 0,
  recommendation_count: 0,
})

const loading = ref(true)
let intervalId: ReturnType<typeof setInterval> | null = null

async function fetchStatus(): Promise<void> {
  try {
    const response = await fetch('/api/onboarding/status', {
      headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    })
    if (response.ok) {
      status.value = await response.json() as StatusData
      loading.value = false

      if (status.value.recommendation_count > 0) {
        if (intervalId) clearInterval(intervalId)
        router.visit('/app')
      }
    }
  } catch {
    // silently retry
  }
}

const stepLabels: Record<string, string> = {
  initializing: 'Getting started…',
  crawling: 'Reading your website…',
  analyzing: 'Learning your business…',
  ready: 'Building your first recommendation…',
}

onMounted(() => {
  void fetchStatus()
  intervalId = setInterval(() => { void fetchStatus() }, 4000)
})

onUnmounted(() => {
  if (intervalId) clearInterval(intervalId)
})
</script>

<template>
  <AuthLayout>
    <div class="text-center">
      <!-- Spinner -->
      <div class="flex items-center justify-center mb-5">
        <div class="size-12 rounded-full border-2 border-[--color-border] border-t-[--color-accent-500] animate-spin" aria-hidden="true" />
      </div>

      <h1 class="text-base font-semibold text-[--color-text-primary] mb-1">
        {{ loading ? 'Checking in with Atlas…' : (stepLabels[status.twin_status] ?? 'Processing…') }}
      </h1>
      <p class="text-sm text-[--color-text-muted] mb-6">This usually takes a few minutes. You can leave and come back.</p>

      <!-- Progress items -->
      <div class="space-y-3 text-left" aria-live="polite">
        <div class="flex items-center gap-3">
          <div :class="['size-5 rounded-full flex items-center justify-center shrink-0', status.fact_count > 0 ? 'bg-[--color-accent-500]' : 'bg-[--color-border]']">
            <svg v-if="status.fact_count > 0" class="size-3 text-white" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
          </div>
          <span class="text-sm text-[--color-text-secondary]">
            Facts gathered
            <span v-if="status.fact_count > 0" class="text-[--color-text-muted]">({{ status.fact_count }})</span>
          </span>
        </div>

        <div class="flex items-center gap-3">
          <div :class="['size-5 rounded-full flex items-center justify-center shrink-0', status.opportunity_count > 0 ? 'bg-[--color-accent-500]' : 'bg-[--color-border]']">
            <svg v-if="status.opportunity_count > 0" class="size-3 text-white" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
          </div>
          <span class="text-sm text-[--color-text-secondary]">
            Opportunities identified
            <span v-if="status.opportunity_count > 0" class="text-[--color-text-muted]">({{ status.opportunity_count }})</span>
          </span>
        </div>

        <div class="flex items-center gap-3">
          <div :class="['size-5 rounded-full flex items-center justify-center shrink-0', status.recommendation_count > 0 ? 'bg-[--color-accent-500]' : 'bg-[--color-border]']">
            <svg v-if="status.recommendation_count > 0" class="size-3 text-white" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
          </div>
          <span class="text-sm text-[--color-text-secondary]">First recommendation ready</span>
        </div>
      </div>

      <a
        href="/app"
        class="mt-6 inline-block text-sm text-[--color-text-link] hover:underline"
      >
        Skip to dashboard
      </a>
    </div>
  </AuthLayout>
</template>
